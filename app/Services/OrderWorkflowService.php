<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderWorkflowService
{
    public function __construct(private readonly MockPaymentService $payments) {}

    public function transition(Order $order, OrderStatus $next, ?string $simulation = null): Order
    {
        return DB::transaction(function () use ($order, $next, $simulation) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === $next) {
                abort_if($next === OrderStatus::Cancelled && ! $locked->stock_restored_at, 409, 'Cancellation tracking is inconsistent.');

                return $locked->load('items', 'payment.refund');
            }
            abort_unless($locked->status->canTransitionTo($next), 409, 'Invalid order status transition.');
            if ($next === OrderStatus::Cancelled) {
                abort_if($locked->stock_restored_at !== null, 409, 'Stock has already been restored.');
                $items = $locked->items()->orderBy('product_id')->get();
                $quantities = [];
                foreach ($items as $item) {
                    abort_if($item->product_id === null, 409, 'Cannot restore stock for a hard-deleted product.');
                    $quantities[$item->product_id] = ($quantities[$item->product_id] ?? 0) + $item->quantity;
                }
                ksort($quantities, SORT_NUMERIC);
                $products = [];
                foreach ($quantities as $id => $quantity) {
                    $product = Product::withTrashed()->whereKey($id)->lockForUpdate()->first();
                    abort_if(! $product || $quantity > 4294967295 - $product->stock_quantity, 409, 'Inventory restoration is unavailable or exceeds stock limits.');
                    $products[] = [$product, $quantity];
                }
                $payment = $locked->payment()->lockForUpdate()->first();
                abort_unless($payment && $payment->status === 'paid' && $payment->amount === $locked->grand_total, 409, 'Order has no eligible paid payment.');
                $locked->status = $next;
                $locked->save();
                foreach ($products as [$product, $quantity]) {
                    $product->stock_quantity += $quantity;
                    $product->save();
                }
                $this->payments->refund($payment, $simulation);
                $locked->stock_restored_at = now();
            } else {
                $locked->status = $next;
            }
            $locked->save();

            return $locked->load('items', 'payment.refund');
        }, 3);
    }
}
