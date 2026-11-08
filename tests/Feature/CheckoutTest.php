<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\MockPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    private function add(Product $product, int $quantity = 1): void
    {
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => $quantity])->assertCreated();
    }

    private function checkout(string $key = 'checkout-example-1', array $body = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/checkout', $body, array_replace(['Idempotency-Key' => $key], $headers));
    }

    public function test_success_creates_reconciled_snapshots_payment_deducts_stock_and_clears_cart(): void
    {
        $user = $this->asCustomer();
        $product = Product::factory()->create(['sku' => 'CHECKOUT-SKU', 'name' => 'Snapshot Product', 'price' => '19.99', 'stock_quantity' => 10]);
        $discount = Discount::factory()->create(['value' => '10.00']);
        $product->discounts()->attach($discount);
        $this->add($product, 3);
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $response = $this->checkout()->assertCreated()->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.status', 'placed')->assertJsonPath('data.currency', 'MYR')
            ->assertJsonPath('data.subtotal', '59.97')->assertJsonPath('data.discount_total', '6.00')
            ->assertJsonPath('data.grand_total', '53.97')->assertJsonPath('data.items.0.unit_original_price', '19.99')
            ->assertJsonPath('data.items.0.unit_discount', '2.00')->assertJsonPath('data.items.0.unit_final_price', '17.99')
            ->assertJsonPath('data.items.0.quantity', 3)->assertJsonPath('data.items.0.line_total', '53.97')
            ->assertJsonPath('data.items.0.sku_snapshot', 'CHECKOUT-SKU')
            ->assertJsonPath('data.items.0.product_name_snapshot', 'Snapshot Product')
            ->assertJsonPath('data.items.0.applied_discount_snapshot.value', '10.00')
            ->assertJsonPath('data.payment.status', 'paid')->assertJsonPath('data.payment.amount', '53.97');
        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, $user->cart->items()->count());
        $this->getJson('/api/orders/'.$response->json('data.id').'/payment')->assertOk()
            ->assertJsonPath('data.reference', $response->json('data.payment.reference'));
    }

    public function test_empty_cart_and_guest_checkout_are_rejected(): void
    {
        $this->post('/api/checkout', [])->assertUnauthorized();
        $this->asCustomer();
        $this->checkout()->assertConflict();
        $this->assertDatabaseCount('orders', 0);
    }

    public static function invalidKeys(): array
    {
        return [[''], ['with spaces'], [str_repeat('x', 129)], ['bad/key'], ['é-key']];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_idempotency_keys_return_422(string $key): void
    {
        $this->asCustomer();
        $this->checkout($key)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_body_key_cannot_replace_required_header_and_client_totals_are_rejected(): void
    {
        $this->asCustomer();
        $this->postJson('/api/checkout', ['idempotency_key' => 'body-only'])->assertUnprocessable();
        $this->checkout(body: ['grand_total' => '0.00', 'payment_success' => true])->assertUnprocessable()
            ->assertJsonValidationErrors(['grand_total', 'payment_success']);
    }

    public function test_inactive_deleted_and_understocked_products_fail_without_partial_changes(): void
    {
        $user = $this->asCustomer();
        $valid = Product::factory()->create(['stock_quantity' => 10]);
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $this->add($valid);
        $this->add($product, 3);
        $product->update(['is_active' => false]);
        $this->checkout()->assertConflict();
        $product->update(['is_active' => true, 'stock_quantity' => 2]);
        $this->checkout()->assertConflict();
        $product->delete();
        $this->checkout()->assertConflict();
        $this->assertSame(10, $valid->fresh()->stock_quantity);
        $this->assertSame(2, $user->cart->items()->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_checkout_uses_current_price_and_discount_not_cart_or_client_values(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['price' => '100.00', 'stock_quantity' => 5]);
        $this->add($product, 2);
        $product->update(['price' => '80.00']);
        $discount = Discount::factory()->create(['type' => 'fixed', 'value' => '10.00']);
        $product->discounts()->attach($discount);
        $this->checkout(body: ['unit_final_price' => '0.00'])->assertCreated()
            ->assertJsonPath('data.subtotal', '160.00')->assertJsonPath('data.discount_total', '20.00')->assertJsonPath('data.grand_total', '140.00');
    }

    public function test_checkout_applies_authenticated_loyalty_without_stacking(): void
    {
        $this->asCustomer(User::factory()->create(['is_loyalty_member' => true]));
        $product = Product::factory()->create(['price' => '100.00', 'stock_quantity' => 5]);
        $vip = Discount::factory()->create(['type' => 'loyalty', 'value' => '85.00']);
        $sale = Discount::factory()->create(['value' => '20.00']);
        $product->discounts()->attach([$vip->id, $sale->id]);
        $this->add($product, 2);
        $this->checkout()->assertCreated()->assertJsonPath('data.grand_total', '30.00')
            ->assertJsonPath('data.discount_total', '170.00')->assertJsonPath('data.items.0.applied_discount_snapshot.type', 'loyalty');
    }

    public function test_mock_decline_rolls_back_order_items_stock_payment_and_preserves_cart(): void
    {
        $user = $this->asCustomer();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $this->add($product, 2);
        $this->checkout(headers: ['X-Mock-Payment' => 'fail'])->assertConflict();
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(2, $user->cart->items()->sole()->quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        // A failed attempt did not consume the idempotency key.
        $this->checkout()->assertCreated();
    }

    public function test_failure_after_payment_insert_also_rolls_back_everything(): void
    {
        $user = $this->asCustomer();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $this->add($product, 2);
        $this->app->instance(MockPaymentService::class, new class extends MockPaymentService
        {
            public function pay(Order $order, ?string $simulation = null): Payment
            {
                parent::pay($order, $simulation);
                abort(409, 'Injected failure after payment insert.');
            }
        });
        $this->checkout()->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(1, $user->cart->items()->count());
    }

    public function test_payment_header_is_rejected_in_production_even_for_success(): void
    {
        $this->asCustomer();
        $this->app->instance('env', 'production');
        $this->checkout(headers: ['X-Mock-Payment' => 'success'])->assertUnprocessable()->assertJsonValidationErrors('simulation');
        $this->checkout(headers: ['X-Mock-Payment' => 'fail'])->assertUnprocessable();
    }

    public function test_retries_return_original_result_without_consuming_new_cart_or_inventory(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 5]);
        $this->add($product);
        $first = $this->checkout()->assertCreated();
        $this->add($product, 2);
        $this->checkout()->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($first->json());
        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/api/cart')->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_keys_are_customer_scoped_and_case_sensitive(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $this->asCustomer();
        $this->add($product);
        $first = $this->checkout('Same-Key')->assertCreated();
        $this->add($product);
        $this->checkout('same-key')->assertCreated();
        $this->asCustomer();
        $this->add($product);
        $this->checkout('Same-Key')->assertCreated()->assertJsonMissing(['order_number' => $first->json('data.order_number')]);
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_order_snapshots_survive_catalog_changes_discount_deletion_and_product_hard_deletion(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 5]);
        $discount = Discount::factory()->create();
        $product->discounts()->attach($discount);
        $this->add($product);
        $response = $this->checkout()->assertCreated();
        $snapshot = $response->json('data.items.0');
        $product->update(['name' => 'New Name', 'sku' => 'NEW-SKU', 'price' => '999.00']);
        $discount->delete();
        $this->getJson('/api/orders/'.$response->json('data.id'))->assertJsonPath('data.items.0', $snapshot);
        $product->forceDelete();
        $snapshot['product_id'] = null;
        $this->getJson('/api/orders/'.$response->json('data.id'))->assertJsonPath('data.items.0', $snapshot);
    }

    public function test_order_history_is_paginated_owner_only_and_payments_cannot_be_viewed_cross_user(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $this->add($product);
            $ids[] = $this->checkout('history-'.$i)->assertCreated()->json('data.id');
        }
        $this->getJson('/api/orders?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('meta.total', 3);
        $this->getJson('/api/orders?per_page=101')->assertUnprocessable();
        $this->asCustomer(User::factory()->create(['is_admin' => true]));
        $this->getJson('/api/orders')->assertJsonCount(0, 'data');
        $this->getJson('/api/orders/'.$ids[0])->assertNotFound();
        $this->getJson('/api/orders/'.$ids[0].'/payment')->assertNotFound();
        $this->patchJson('/api/orders/'.$ids[0], ['status' => 'cancelled'])->assertMethodNotAllowed();
        $this->forgetAuthentication();
        $this->get('/api/orders')->assertUnauthorized();
        $this->get('/api/orders/'.$ids[0])->assertUnauthorized();
        $this->get('/api/orders/'.$ids[0].'/payment')->assertUnauthorized();
    }

    public function test_order_total_overflow_is_rejected_without_persistence(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['price' => '9999999999.99', 'stock_quantity' => 5]);
        $this->add($product, 2);
        $this->checkout()->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_zero_total_checkout_and_half_cent_rounding_reconcile(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['price' => '0.05', 'stock_quantity' => 10]);
        $discount = Discount::factory()->create(['value' => '10.00']);
        $product->discounts()->attach($discount);
        $this->add($product, 3);
        $this->checkout()->assertCreated()->assertJsonPath('data.subtotal', '0.15')
            ->assertJsonPath('data.discount_total', '0.03')->assertJsonPath('data.grand_total', '0.12');
        $discount->update(['value' => '100.00']);
        $this->add($product);
        $this->checkout('free-order')->assertCreated()->assertJsonPath('data.grand_total', '0.00')->assertJsonPath('data.payment.amount', '0.00');
    }
}
