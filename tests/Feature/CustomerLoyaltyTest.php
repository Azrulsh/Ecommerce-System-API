<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use App\Services\Discount\LoyaltyEligibility;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class CustomerLoyaltyTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    public function test_guest_regular_and_loyalty_pricing_use_stored_membership_only(): void
    {
        $product = Product::factory()->create(['price' => '100.00']);
        $sale = Discount::factory()->create(['value' => '20.00']);
        $vip = Discount::factory()->create(['type' => 'loyalty', 'value' => '85.00', 'requires_loyalty' => true]);
        $product->discounts()->attach([$sale->id, $vip->id]);
        $this->getJson('/api/products/'.$product->id.'/price?is_loyalty_member=true')->assertJsonPath('data.final_price', '80.00');
        $regular = $this->asCustomer();
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '80.00');
        $regular->setAttribute('is_loyalty_member', true);
        $this->assertFalse(app(LoyaltyEligibility::class)->eligible($regular));
        $loyal = $this->asCustomer(User::factory()->create(['is_loyalty_member' => true]));
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '15.00')
            ->assertJsonPath('data.applied_discount.id', $vip->id);
        $this->getJson('/api/products/'.$product->id.'/discounts')->assertJsonCount(2, 'data');
        $this->postJson('/api/discounts/calculate', ['product_id' => $product->id])->assertJsonPath('data.final_price', '15.00');
        $this->postJson('/api/discounts/calculate', ['product_id' => $product->id, 'is_loyalty_member' => true])->assertUnprocessable();
        $loyal->forceFill(['is_loyalty_member' => false])->save();
        Auth::forgetGuards();
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '80.00');
    }

    public function test_expired_inactive_and_future_loyalty_discounts_do_not_apply_and_best_price_still_wins(): void
    {
        $this->asCustomer(User::factory()->create(['is_loyalty_member' => true]));
        $product = Product::factory()->create(['price' => '100.00']);
        foreach ([['expires_at' => now()->subSecond()], ['is_active' => false], ['starts_at' => now()->addDay()]] as $state) {
            $discount = Discount::factory()->create(array_replace(['type' => 'loyalty', 'value' => '85.00'], $state));
            $product->discounts()->attach($discount);
        }
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '100.00');
        $vip = Discount::factory()->create(['type' => 'loyalty', 'value' => '85.00']);
        $better = Discount::factory()->create(['type' => 'percentage', 'value' => '90.00']);
        $product->discounts()->attach([$vip->id, $better->id]);
        $this->getJson('/api/products/'.$product->id.'/price')->assertJsonPath('data.final_price', '10.00')
            ->assertJsonPath('data.applied_discount.id', $better->id);
    }
}
