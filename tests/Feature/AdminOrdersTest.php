<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderWorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    private function order(string $price = '19.99', int $quantity = 3, bool $discounted = false): array
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => $price, 'stock_quantity' => 20]);
        if ($discounted) {
            $product->discounts()->attach(Discount::factory()->create(['type' => 'percentage', 'value' => '10.00']));
        }
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
        $order = app(CheckoutService::class)->checkout($user, 'admin-order')['order'];

        return [$order, $product, $user];
    }

    private function admin(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->asCustomer($admin);
    }

    private function changeStatus(Order $order, string $status, array $headers = [])
    {
        return $this->patchJson("/api/admin/orders/$order->id/status", ['status' => $status], $headers);
    }

    public function test_cancellation_restores_stock_once_refunds_exact_amount_and_preserves_snapshots(): void
    {
        [$order, $product, $owner] = $this->order(discounted: true);
        $snapshots = $order->items->toArray();
        $paymentReference = $order->payment->reference;
        $this->admin();
        $this->changeStatus($order, 'processing')->assertOk();
        $first = $this->changeStatus($order, 'cancelled')->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.grand_total', '53.97')->assertJsonPath('data.payment.status', 'refunded')
            ->assertJsonPath('data.payment.refund.amount', '53.97');
        $this->assertNotNull($first->json('data.stock_restored_at'));
        $this->assertSame(20, $product->fresh()->stock_quantity);
        $this->changeStatus($order, 'cancelled')->assertOk()->assertExactJson($first->json());
        $this->assertSame(20, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame($snapshots, $order->fresh()->items->toArray());
        $this->assertSame($paymentReference, $order->payment->fresh()->reference);
        $this->asCustomer($owner);
        $this->getJson("/api/orders/$order->id")->assertOk()->assertJsonPath('data.payment.refund.amount', '53.97');
    }

    public function test_failed_refund_rolls_back_order_inventory_payment_and_tracking_and_allows_retry(): void
    {
        [$order, $product] = $this->order();
        $this->admin();
        $this->changeStatus($order, 'cancelled', ['X-Mock-Refund' => 'fail'])->assertConflict();
        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_restored_at);
        $this->assertSame(17, $product->fresh()->stock_quantity);
        $this->assertSame('paid', $order->payment->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
        $this->changeStatus($order, 'cancelled', ['X-Mock-Refund' => 'success'])->assertOk();
        $this->assertSame(20, $product->fresh()->stock_quantity);
    }

    public function test_status_transitions_cannot_skip_reverse_or_cancel_shipped_completed_orders(): void
    {
        [$order, $product] = $this->order();
        $this->admin();
        $this->changeStatus($order, 'completed')->assertConflict();
        $this->changeStatus($order, 'shipped')->assertConflict();
        $this->changeStatus($order, 'processing')->assertOk();
        $this->changeStatus($order, 'placed')->assertConflict();
        $this->changeStatus($order, 'shipped')->assertOk();
        $this->changeStatus($order, 'cancelled')->assertConflict();
        $this->changeStatus($order, 'completed')->assertOk();
        $this->changeStatus($order, 'cancelled')->assertConflict();
        $this->changeStatus($order, 'processing')->assertConflict();
        $this->assertSame(17, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cancellation_supports_soft_deleted_products_but_rejects_missing_inventory_and_overflow(): void
    {
        [$order, $product] = $this->order();
        $this->admin();
        $product->delete();
        $this->changeStatus($order, 'cancelled')->assertOk();
        $this->assertSame(20, Product::withTrashed()->findOrFail($product->id)->stock_quantity);
        [$missing, $deleted] = $this->order();
        $deleted->forceDelete();
        $this->changeStatus($missing, 'cancelled')->assertConflict();
        $this->assertSame(OrderStatus::Placed, $missing->fresh()->status);
        [$overflow, $full] = $this->order();
        $full->update(['stock_quantity' => 4294967295]);
        $this->changeStatus($overflow, 'cancelled')->assertConflict();
        $this->assertSame(OrderStatus::Placed, $overflow->fresh()->status);
    }

    public function test_invalid_status_financial_writes_and_production_simulation_are_rejected(): void
    {
        [$order] = $this->order();
        $this->admin();
        $this->changeStatus($order, 'paid')->assertUnprocessable();
        $this->patchJson("/api/admin/orders/$order->id/status", ['status' => 'processing', 'grand_total' => '0.00'])->assertUnprocessable();
        $this->changeStatus($order, 'cancelled', ['X-Mock-Refund' => 'unknown'])->assertUnprocessable();
        $this->app->instance('env', 'production');
        $this->changeStatus($order, 'cancelled', ['X-Mock-Refund' => 'fail'])->assertUnprocessable();
        $this->changeStatus($order, 'cancelled')->assertOk();
    }

    public function test_admin_order_listing_filters_paginates_and_customers_have_no_admin_access(): void
    {
        [$order, , $owner] = $this->order();
        [$other] = $this->order();
        $order->forceFill(['created_at' => '2026-03-02 23:59:59'])->save();
        $other->forceFill(['created_at' => '2026-03-03 00:00:00'])->save();
        $this->asCustomer($owner);
        $this->getJson("/api/orders/$other->id")->assertNotFound();
        $this->getJson('/api/admin/orders')->assertForbidden();
        $this->changeStatus($order, 'cancelled')->assertForbidden();
        $this->admin();
        $this->getJson('/api/admin/orders?from_date=2026-03-02&to_date=2026-03-02&status=placed&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $order->id);
        $this->getJson("/api/admin/orders/$other->id")->assertOk();
        $this->getJson('/api/admin/orders?status=unknown')->assertUnprocessable();
        $this->getJson('/api/admin/orders?from_date=2026-03-03&to_date=2026-03-02')->assertUnprocessable();
        $this->getJson('/api/admin/orders?per_page=101')->assertUnprocessable();
    }

    public function test_sales_summary_uses_exact_paid_snapshots_and_excludes_cancelled_refunded_sales(): void
    {
        [$paid, $product] = $this->order(discounted: true);
        [$cancelled] = $this->order('100.00', 2);
        app(OrderWorkflowService::class)->transition($cancelled, OrderStatus::Cancelled);
        $product->update(['price' => '999.00', 'name' => 'New name']);
        $this->admin();
        $this->getJson('/api/admin/sales-summary')->assertOk()
            ->assertJsonPath('data.total_orders', 2)->assertJsonPath('data.cancelled_orders', 1)
            ->assertJsonPath('data.gross_sales', '59.97')->assertJsonPath('data.discounts_given', '6.00')
            ->assertJsonPath('data.net_sales', '53.97')->assertJsonCount(1, 'data.top_selling_products')
            ->assertJsonPath('data.top_selling_products.0.quantity_sold', 3)
            ->assertJsonPath('data.top_selling_products.0.revenue', '53.97');
        $paid->payment->update(['status' => 'failed']);
        $this->getJson('/api/admin/sales-summary')->assertOk()->assertJsonPath('data.net_sales', '0.00')->assertJsonCount(0, 'data.top_selling_products');
    }

    public function test_summary_filters_utc_dates_and_handles_aggregates_larger_than_single_order_decimal(): void
    {
        [$first] = $this->order('0.01', 1);
        [$second] = $this->order('0.02', 1);
        foreach ([$first, $second] as $order) {
            $order->forceFill(['subtotal' => '9999999999.99', 'discount_total' => '0.00', 'grand_total' => '9999999999.99',
                'created_at' => '2026-01-31 23:59:59'])->save();
            $order->payment->update(['amount' => '9999999999.99']);
        }
        $this->admin();
        $this->getJson('/api/admin/sales-summary?from_date=2026-01-31&to_date=2026-01-31')->assertOk()
            ->assertJsonPath('data.total_orders', 2)->assertJsonPath('data.net_sales', '19999999999.98');
        $this->getJson('/api/admin/sales-summary?from_date=2026-02-01')->assertOk()
            ->assertJsonPath('data.total_orders', 0)->assertJsonPath('data.net_sales', '0.00');
        $this->getJson('/api/admin/sales-summary?from_date=bad')->assertUnprocessable();
        $this->getJson('/api/admin/sales-summary?from_date=2026-02-01&to_date=2026-01-31')->assertUnprocessable();
    }

    public function test_ineligible_payment_and_invalid_refund_configuration_leave_cancellation_unchanged(): void
    {
        [$order, $product] = $this->order();
        $this->admin();
        $order->payment->update(['amount' => '0.01']);
        $this->changeStatus($order, 'cancelled')->assertConflict();
        $order->payment->update(['amount' => $order->grand_total, 'status' => 'failed']);
        $this->changeStatus($order, 'cancelled')->assertConflict();
        $order->payment->update(['status' => 'paid']);
        config(['checkout.mock_refund_outcome' => 'invalid']);
        $this->changeStatus($order, 'cancelled')->assertStatus(503);
        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_restored_at);
        $this->assertSame(17, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_reporting_groups_snapshot_skus_and_excludes_refunds_even_on_non_cancelled_orders(): void
    {
        [$first, $product] = $this->order('0.01', 3);
        [$second] = $this->order('0.02', 2);
        $second->items()->update(['sku_snapshot' => $product->sku]);
        foreach (['processing', 'shipped', 'completed'] as $status) {
            app(OrderWorkflowService::class)->transition($first, OrderStatus::from($status));
        }
        $this->admin();
        $this->getJson('/api/admin/sales-summary')->assertOk()->assertJsonPath('data.completed_orders', 1)
            ->assertJsonPath('data.net_sales', '0.07')->assertJsonCount(1, 'data.top_selling_products')
            ->assertJsonPath('data.top_selling_products.0.quantity_sold', 5)
            ->assertJsonPath('data.top_selling_products.0.revenue', '0.07');
        $second->payment->refund()->create(['reference' => 'historical-refund', 'amount' => '0.04', 'status' => 'refunded', 'refunded_at' => now()]);
        $this->getJson('/api/admin/sales-summary')->assertOk()->assertJsonPath('data.total_orders', 2)
            ->assertJsonPath('data.net_sales', '0.03')->assertJsonPath('data.top_selling_products.0.quantity_sold', 3);
    }

    public function test_multi_item_orders_are_counted_once_and_cancellation_restores_all_inventory(): void
    {
        $customer = User::factory()->create();
        $cart = $customer->cart()->create();
        $products = Product::factory()->count(2)->create(['price' => '12.34', 'stock_quantity' => 8]);
        foreach ($products as $product) {
            $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);
        }
        $order = app(CheckoutService::class)->checkout($customer, 'multi-item')['order'];
        $this->admin();
        $this->getJson('/api/admin/sales-summary')->assertOk()->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.gross_sales', '49.36')->assertJsonPath('data.net_sales', '49.36')
            ->assertJsonCount(2, 'data.top_selling_products');
        $this->changeStatus($order, 'cancelled')->assertOk()->assertJsonPath('data.payment.refund.amount', '49.36');
        foreach ($products as $product) {
            $this->assertSame(8, $product->fresh()->stock_quantity);
        }
        $this->getJson('/api/admin/sales-summary')->assertOk()->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.net_sales', '0.00')->assertJsonCount(0, 'data.top_selling_products');
    }
}
