<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use App\Services\Discount\LoyaltyEligibility;
use App\Services\Discount\ProductPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductDiscountTest extends TestCase
{
    use DatabaseTransactions;

    private function attach(Product $product, array $attributes = []): Discount
    {
        $discount = Discount::factory()->create($attributes);
        $product->discounts()->attach($discount);

        return $discount;
    }

    public function test_price_and_calculator_return_exact_money_and_same_resource(): void
    {
        $product = Product::factory()->create(['price' => '1299.90']);
        $discount = $this->attach($product, ['name' => '10% Electronics Discount']);
        $expected = ['data' => ['product_id' => $product->id, 'original_price' => '1299.90',
            'discount_amount' => '129.99', 'final_price' => '1169.91',
            'applied_discount' => ['id' => $discount->id, 'name' => '10% Electronics Discount', 'type' => 'percentage']]];
        $this->getJson('/api/products/'.$product->id.'/price')->assertOk()->assertExactJson($expected);
        $this->postJson('/api/discounts/calculate', ['product_id' => $product->id])->assertOk()->assertExactJson($expected);
    }

    public function test_no_eligible_discounts_returns_original_price(): void
    {
        $product = Product::factory()->create(['price' => '100.00']);
        Discount::factory()->create(); // Unassigned discounts must never apply.
        $this->getJson('/api/products/'.$product->id.'/price')->assertOk()
            ->assertJsonPath('data.final_price', '100.00')->assertJsonPath('data.discount_amount', '0.00')
            ->assertJsonPath('data.applied_discount', null);
        $this->getJson('/api/products/'.$product->id.'/discounts')->assertExactJson(['data' => []]);
    }

    public function test_selects_best_discount_without_stacking_and_breaks_ties_by_id(): void
    {
        $product = Product::factory()->create(['price' => '100.00']);
        $this->attach($product, ['type' => 'fixed', 'value' => '10.00']);
        $best = $this->attach($product, ['value' => '20.00']);
        $this->attach($product, ['type' => 'fixed', 'value' => '20.00']);
        $this->getJson('/api/products/'.$product->id.'/price')->assertOk()
            ->assertJsonPath('data.final_price', '80.00')->assertJsonPath('data.applied_discount.id', $best->id);
    }

    public function test_zero_discounts_use_lowest_id_too(): void
    {
        $product = Product::factory()->create(['price' => '0.00']);
        $first = $this->attach($product);
        $this->attach($product);
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '0.00')
            ->assertJsonPath('data.applied_discount.id', $first->id);
    }

    public function test_activation_is_inclusive_expiration_exclusive_and_inactive_discounts_hidden(): void
    {
        $now = CarbonImmutable::parse('2026-11-08T12:00:00Z');
        $this->travelTo($now);
        $product = Product::factory()->create(['price' => '100.00']);
        $eligible = $this->attach($product, ['starts_at' => $now, 'expires_at' => $now->addHour()]);
        $this->attach($product, ['starts_at' => $now->addSecond(), 'value' => '90.00']);
        $this->attach($product, ['expires_at' => $now, 'value' => '90.00']);
        $this->attach($product, ['is_active' => false, 'value' => '90.00']);
        $this->getJson('/api/products/'.$product->id.'/discounts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eligible->id)->assertJsonPath('data.0.value', '10.00')
            ->assertJsonPath('data.0.starts_at', '2026-11-08T12:00:00.000000Z');
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '90.00');
        $this->travelTo($now->addHours(2));
        $this->getJson('/api/products/'.$product->id.'/discounts')->assertJsonCount(1, 'data');
    }

    public function test_invalid_fixed_or_percentage_records_are_ineligible_and_never_negative(): void
    {
        $product = Product::factory()->create(['price' => '10.00']);
        $this->attach($product, ['type' => 'fixed', 'value' => '11.00']);
        $this->attach($product, ['value' => '100.01']);
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '10.00');
        $this->getJson('/api/products/'.$product->id.'/discounts')->assertJsonCount(0, 'data');
    }

    public function test_public_loyalty_claims_cannot_unlock_discounts(): void
    {
        $product = Product::factory()->create(['price' => '100.00']);
        $this->attach($product, ['type' => 'loyalty', 'value' => '85.00', 'requires_loyalty' => false]);
        $this->attach($product, ['value' => '50.00', 'requires_loyalty' => true]);
        $this->getJson('/api/products/'.$product->id.'/price?is_loyalty_member=1&customer_id=1')
            ->assertJsonPath('data.final_price', '100.00');
        $this->getJson('/api/products/'.$product->id.'/discounts')->assertJsonCount(0, 'data');
        $this->postJson('/api/discounts/calculate', ['product_id' => $product->id, 'is_loyalty_member' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_loyalty_member');
        $this->postJson('/api/discounts/calculate', ['product_id' => $product->id, 'type' => 'loyalty', 'value' => 85])
            ->assertOk()->assertJsonPath('data.final_price', '100.00');
        $user = User::factory()->make();
        $user->setAttribute('is_loyalty_member', true);
        $this->actingAs($user)->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '100.00');
    }

    public function test_loyalty_requires_a_customer_and_a_trusted_server_eligibility_provider(): void
    {
        $product = Product::factory()->create(['price' => '100.00']);
        $discount = $this->attach($product, ['type' => 'loyalty', 'value' => '85.00']);
        $provider = new class extends LoyaltyEligibility
        {
            public function eligible(?Authenticatable $customer): bool
            {
                return $customer !== null;
            }
        };
        $service = new ProductPricingService($provider);
        $this->assertSame('100.00', $service->price($product)['final_price']);
        $price = $service->price($product, User::factory()->make());
        $this->assertSame('15.00', $price['final_price']);
        $this->assertSame($discount->id, $price['applied_discount']->id);
    }

    public function test_inactive_deleted_and_missing_products_are_not_exposed(): void
    {
        $product = Product::factory()->create(['is_active' => false]);
        foreach (['price', 'discounts'] as $action) {
            $this->getJson('/api/products/'.$product->id.'/'.$action)->assertNotFound();
        }
        $this->postJson('/api/discounts/calculate', ['product_id' => $product->id])->assertNotFound();
        $product->update(['is_active' => true]);
        $product->delete();
        foreach (['price', 'discounts'] as $action) {
            $this->getJson('/api/products/'.$product->id.'/'.$action)->assertNotFound();
            $this->getJson('/api/products/99999999/'.$action)->assertNotFound();
        }
        $this->postJson('/api/discounts/calculate', ['product_id' => $product->id])->assertNotFound();
    }

    public static function invalidRequests(): array
    {
        return [[[]], [['product_id' => 0]], [['product_id' => 'abc']]];
    }

    #[DataProvider('invalidRequests')]
    public function test_calculator_validates_input_without_accept_header(array $payload): void
    {
        $this->post('/api/discounts/calculate', $payload)->assertUnprocessable()->assertJsonValidationErrors('product_id');
    }

    public function test_no_public_discount_administration_routes_exist(): void
    {
        $this->postJson('/api/discounts', [])->assertNotFound();
        $this->patchJson('/api/discounts/1', [])->assertNotFound();
        $this->postJson('/api/products/1/discounts', [])->assertMethodNotAllowed();
    }
}
