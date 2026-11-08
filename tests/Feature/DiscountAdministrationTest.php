<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Product;
use App\Services\Discount\DiscountAdministrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiscountAdministrationTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): DiscountAdministrationService
    {
        return $this->app->make(DiscountAdministrationService::class);
    }

    private function attributes(array $changes = []): array
    {
        return array_replace(['name' => 'Promotion', 'type' => 'percentage', 'value' => '10.00'], $changes);
    }

    public function test_creation_update_deactivation_and_idempotent_assignment(): void
    {
        $discount = $this->service()->create($this->attributes());
        $product = Product::factory()->create(['price' => '100.00']);
        $this->service()->assignProducts($discount, [$product->id]);
        $this->service()->assignProducts($discount, [$product->id]);
        $this->assertSame(1, $discount->products()->count());
        $this->assertSame($discount->id, $product->discounts()->sole()->id);
        $this->assertNotNull($product->discounts()->sole()->pivot->created_at);
        $updated = $this->service()->update($discount, ['value' => '20.00', 'name' => 'Updated']);
        $this->assertSame('20.00', $updated->value);
        $this->assertSame('Updated', $updated->name);
        $this->assertFalse($this->service()->deactivate($updated)->is_active);
    }

    public function test_normalizes_schedules_to_utc_and_enforces_loyalty_requirement(): void
    {
        $discount = $this->service()->create($this->attributes([
            'type' => 'loyalty', 'value' => '85.00', 'requires_loyalty' => false,
            'starts_at' => '2026-11-08T20:00:00+08:00', 'expires_at' => '2026-11-08T21:00:00+08:00',
        ]));
        $this->assertTrue($discount->requires_loyalty);
        $this->assertSame('2026-11-08 12:00:00', $discount->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-08 13:00:00', $discount->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($this->service()->update($discount, ['requires_loyalty' => false])->requires_loyalty);
    }

    public static function invalidAttributes(): array
    {
        return [
            [['value' => '-1.00']], [['value' => '100.01']], [['value' => '1.001']],
            [['value' => 'abc']], [['type' => 'unknown']], [['name' => '']],
            [['type' => 'loyalty', 'value' => '15.00']],
            [['starts_at' => 'not-a-date']],
            [['starts_at' => '2026-11-08', 'expires_at' => '2026-11-07']],
            [['starts_at' => '2026-11-08', 'expires_at' => '2026-11-08']],
            [['type' => 'fixed', 'value' => '10000000000.00']],
        ];
    }

    #[DataProvider('invalidAttributes')]
    public function test_rejects_invalid_discount_attributes(array $attributes): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->create($this->attributes($attributes));
    }

    public function test_assignment_rejects_amount_exceeding_any_product_and_rolls_back_all_links(): void
    {
        $discount = $this->service()->create($this->attributes(['type' => 'fixed', 'value' => '20.00']));
        $valid = Product::factory()->create(['price' => '100.00']);
        $invalid = Product::factory()->create(['price' => '19.99']);
        try {
            $this->service()->assignProducts($discount, [$valid->id, $invalid->id]);
            $this->fail('Expected invalid fixed discount to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value', $exception->errors());
        }
        $this->assertSame(0, $discount->products()->count());
    }

    public function test_fixed_discount_equal_to_price_is_allowed(): void
    {
        $discount = $this->service()->create($this->attributes(['type' => 'fixed', 'value' => '20.00']));
        $product = Product::factory()->create(['price' => '20.00']);
        $this->service()->assignProducts($discount, [$product->id]);
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '0.00');
    }

    public function test_update_cannot_invalidate_existing_assignments(): void
    {
        $discount = $this->service()->create($this->attributes());
        $product = Product::factory()->create(['price' => '20.00']);
        $this->service()->assignProducts($discount, [$product->id]);
        try {
            $this->service()->update($discount, ['type' => 'fixed', 'value' => '20.01', 'name' => 'Invalid']);
            $this->fail('Expected invalid fixed update to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value', $exception->errors());
        }
        $this->assertSame('percentage', $discount->fresh()->type);
        $this->assertSame('Promotion', $discount->fresh()->name);
        $this->assertSame(1, $discount->products()->count());
    }

    public function test_price_reduction_does_not_prevent_deactivation(): void
    {
        $discount = $this->service()->create($this->attributes(['type' => 'fixed', 'value' => '20.00']));
        $product = Product::factory()->create(['price' => '20.00']);
        $this->service()->assignProducts($discount, [$product->id]);
        $product->update(['price' => '10.00']);
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '10.00');
        $this->assertFalse($this->service()->deactivate($discount)->is_active);
    }

    public function test_assignment_rejects_missing_deleted_or_duplicate_products(): void
    {
        $discount = Discount::factory()->create();
        $product = Product::factory()->create();
        $product->delete();
        foreach ([[99999999], [$product->id], [$product->id, $product->id]] as $ids) {
            try {
                $this->service()->assignProducts($discount, $ids);
                $this->fail('Expected invalid product assignment.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(0, $discount->products()->count());
    }

    public function test_pivot_foreign_keys_unique_pair_and_cascade_cleanup(): void
    {
        $discount = Discount::factory()->create();
        $product = Product::factory()->create();
        $discount->products()->attach($product);
        $this->assertDatabaseCount('discount_product', 1);
        $discount->delete();
        $this->assertDatabaseCount('discount_product', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
