<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class ShoppingCartTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    public function test_empty_cart_is_created_once_for_customer(): void
    {
        $user = $this->asCustomer();
        $first = $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.subtotal', '0.00')->assertJsonPath('data.total_savings', '0.00')
            ->assertJsonPath('data.grand_total', '0.00')->assertJsonPath('data.is_ready', true);
        $this->getJson('/api/cart')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, $user->cart()->count());
    }

    public function test_add_existing_product_accumulates_updates_and_removes_without_deducting_stock(): void
    {
        $user = $this->asCustomer();
        $product = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 5]);
        $first = $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.items.0.quantity', 2)->assertJsonPath('data.grand_total', '20.00');
        $itemId = $first->json('data.items.0.id');
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertCreated()
            ->assertJsonPath('data.items.0.id', $itemId)->assertJsonPath('data.items.0.quantity', 4)->assertJsonCount(1, 'data.items');
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertConflict();
        $this->assertSame(4, CartItem::findOrFail($itemId)->quantity);
        $this->patchJson('/api/cart/items/'.$itemId, ['quantity' => 3])->assertOk()->assertJsonPath('data.grand_total', '30.00');
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->deleteJson('/api/cart/items/'.$itemId)->assertNoContent();
        $this->getJson('/api/cart')->assertJsonCount(0, 'data.items');
        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [1.5], ['invalid'], [1001], [null]];
    }

    #[DataProvider('invalidQuantities')]
    public function test_add_and_update_validate_quantities(mixed $quantity): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $added = $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $item = $added->json('data.items.0.id');
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => $quantity])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->patchJson('/api/cart/items/'.$item, ['quantity' => $quantity])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(1, CartItem::findOrFail($item)->quantity);
    }

    public function test_missing_inactive_deleted_and_out_of_stock_products_cannot_be_added(): void
    {
        $this->asCustomer();
        $inactive = Product::factory()->create(['is_active' => false]);
        $deleted = Product::factory()->create();
        $deleted->delete();
        foreach ([$inactive->id, $deleted->id, 99999999] as $id) {
            $this->postJson('/api/cart/items', ['product_id' => $id, 'quantity' => 1])->assertNotFound();
        }
        $empty = Product::factory()->create(['stock_quantity' => 0]);
        $this->postJson('/api/cart/items', ['product_id' => $empty->id, 'quantity' => 1])->assertConflict();
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_cross_user_items_are_hidden_and_cannot_be_changed_or_removed(): void
    {
        $owner = $this->asCustomer();
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $item = $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->json('data.items.0.id');
        $this->asCustomer();
        $this->getJson('/api/cart')->assertJsonCount(0, 'data.items');
        $this->patchJson('/api/cart/items/'.$item, ['quantity' => 1])->assertNotFound();
        $this->deleteJson('/api/cart/items/'.$item)->assertNotFound();
        $this->assertDatabaseHas('cart_items', ['id' => $item, 'quantity' => 2]);
        $this->asCustomer($owner);
        $this->getJson('/api/cart')->assertJsonPath('data.items.0.id', $item);
    }

    public function test_exact_line_and_cart_totals_use_current_discounts_and_ignore_client_price_fields(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['price' => '19.99', 'stock_quantity' => 10]);
        $discount = Discount::factory()->create(['value' => '10.00']);
        $product->discounts()->attach($discount);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3, 'unit_final_price' => '0.00'])
            ->assertCreated()->assertJsonPath('data.items.0.unit_original_price', '19.99')
            ->assertJsonPath('data.items.0.unit_final_price', '17.99')->assertJsonPath('data.items.0.applied_discount.id', $discount->id)
            ->assertJsonPath('data.items.0.line_subtotal', '59.97')->assertJsonPath('data.items.0.line_discount_total', '6.00')
            ->assertJsonPath('data.items.0.line_final_total', '53.97');
        $other = Product::factory()->create(['price' => '0.10', 'stock_quantity' => 10]);
        $this->postJson('/api/cart/items', ['product_id' => $other->id, 'quantity' => 3])->assertCreated()
            ->assertJsonPath('data.subtotal', '60.27')->assertJsonPath('data.total_savings', '6.00')->assertJsonPath('data.grand_total', '54.27');
        $discount->update(['value' => '20.00']);
        $this->getJson('/api/cart')->assertJsonPath('data.grand_total', '48.27');
        $discount->update(['is_active' => false]);
        $product->update(['price' => '20.00']);
        $this->getJson('/api/cart')->assertJsonPath('data.subtotal', '60.30')->assertJsonPath('data.total_savings', '0.00')
            ->assertJsonPath('data.grand_total', '60.30');
    }

    public function test_cart_uses_loyalty_membership_and_does_not_stack(): void
    {
        $this->asCustomer(User::factory()->create(['is_loyalty_member' => true]));
        $product = Product::factory()->create(['price' => '100.00', 'stock_quantity' => 5]);
        $vip = Discount::factory()->create(['type' => 'loyalty', 'value' => '85.00']);
        $sale = Discount::factory()->create(['value' => '20.00']);
        $product->discounts()->attach([$vip->id, $sale->id]);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertCreated()
            ->assertJsonPath('data.subtotal', '200.00')->assertJsonPath('data.total_savings', '170.00')->assertJsonPath('data.grand_total', '30.00');
    }

    public function test_unavailable_products_remain_visible_with_deterministic_totals(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 5]);
        $item = $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])->json('data.items.0.id');
        $product->update(['stock_quantity' => 2]);
        $this->getJson('/api/cart')->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.availability', 'insufficient_stock')
            ->assertJsonPath('data.is_ready', false)->assertJsonPath('data.grand_total', '30.00');
        $this->patchJson('/api/cart/items/'.$item, ['quantity' => 3])->assertConflict();
        $product->update(['is_active' => false]);
        $this->getJson('/api/cart')->assertJsonPath('data.items.0.availability', 'inactive')
            ->assertJsonPath('data.items.0.unit_final_price', null)->assertJsonPath('data.grand_total', null)
            ->assertJsonPath('data.subtotal', '30.00')->assertJsonPath('data.total_savings', null);
        $this->patchJson('/api/cart/items/'.$item, ['quantity' => 1])->assertNotFound();
        $product->delete();
        $this->getJson('/api/cart')->assertJsonPath('data.items.0.availability', 'deleted')->assertJsonCount(1, 'data.items');
        $this->deleteJson('/api/cart/items/'.$item)->assertNoContent();
    }

    public function test_missing_items_and_client_ownership_or_price_claims_are_rejected(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $this->patchJson('/api/cart/items/99999999', ['quantity' => 1])->assertNotFound();
        $this->deleteJson('/api/cart/items/99999999')->assertNotFound();
        foreach (['price' => '0.00', 'cart_id' => 1, 'user_id' => 1, 'discount' => 100] as $field => $value) {
            $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_combined_quantity_limit_does_not_overflow_and_stock_is_not_reserved(): void
    {
        $first = $this->asCustomer();
        $product = Product::factory()->create(['price' => '9999999999.99', 'stock_quantity' => 2000]);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1000])->assertCreated()
            ->assertJsonPath('data.grand_total', '9999999999990.00');
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertUnprocessable();
        $this->asCustomer();
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1000])->assertCreated();
        $this->assertSame(2000, $product->fresh()->stock_quantity);
        $this->assertSame(1, Cart::where('user_id', $first->id)->count());
    }
}
