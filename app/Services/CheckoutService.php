<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Discount\Money;
use App\Services\Discount\ProductPricingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(private readonly CartService $carts, private readonly ProductPricingService $pricing, private readonly MockPaymentService $payments) {}

    public function checkout(User $user, string $key, ?string $simulation = null): array
    {
        return DB::transaction(function () use ($user, $key, $simulation) {
            // Serializes customer-scoped idempotency, even before a cart exists.
            $customer = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = $customer->orders()->where('checkout_idempotency_key', $key)->first();
            if ($existing) {
                return ['order' => $existing->load('items', 'payment'), 'replayed' => true];
            }
            $this->carts->cart($customer);
            $cart = $customer->cart()->lockForUpdate()->firstOrFail();
            $items = $cart->items()->orderBy('product_id')->get();
            abort_if($items->isEmpty(), 409, 'Cannot checkout an empty cart.');
            abort_if($items->count() > 100, 409, 'Cart exceeds the checkout item limit.');
            // One indexed lookup at a time makes lock acquisition order explicit.
            $products = new Collection;
            foreach ($items as $item) {
                $product = Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->first();
                abort_if(! $product || $product->trashed() || ! $product->is_active, 409, 'A cart product is unavailable.');
                abort_if($item->quantity < 1 || $item->quantity > 1000, 409, 'A cart quantity is outside checkout limits.');
                abort_if($item->quantity > $product->stock_quantity, 409, 'Insufficient stock for '.$product->sku.'.');
                $products->put($product->id, $product);
            }
            $products->load('discounts');
            $prices = $this->pricing->prices($products, $customer);
            $subtotal = $savings = $grandTotal = 0;
            $snapshots = [];
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                $price = $prices->get($product->id);
                $original = Money::minor($price['original_price']);
                $discount = Money::minor($price['discount_amount']);
                $final = Money::minor($price['final_price']);
                $lineTotal = $final * $item->quantity;
                $subtotal += $original * $item->quantity;
                $savings += $discount * $item->quantity;
                $grandTotal += $lineTotal;
                abort_if($subtotal > 999999999999 || $savings > 999999999999 || $grandTotal > 999999999999, 409, 'Order totals exceed DECIMAL(12,2) limits.');
                $applied = $price['applied_discount'];
                $snapshots[] = ['product_id' => $product->id, 'sku_snapshot' => $product->sku,
                    'product_name_snapshot' => $product->name, 'quantity' => $item->quantity,
                    'unit_original_price' => $price['original_price'], 'unit_discount' => $price['discount_amount'],
                    'unit_final_price' => $price['final_price'], 'line_total' => Money::decimal($lineTotal),
                    'applied_discount_snapshot' => $applied ? ['id' => $applied->id, 'name' => $applied->name,
                        'type' => $applied->type, 'value' => $applied->value] : null];
            }
            abort_unless($subtotal - $savings === $grandTotal, 409, 'Order totals do not reconcile.');
            $order = $customer->orders()->create(['order_number' => 'ORD-'.Str::ulid(), 'status' => OrderStatus::Placed,
                'currency' => 'MYR', 'subtotal' => Money::decimal($subtotal), 'discount_total' => Money::decimal($savings),
                'grand_total' => Money::decimal($grandTotal), 'checkout_idempotency_key' => $key]);
            $order->items()->createMany($snapshots);
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                $product->stock_quantity -= $item->quantity;
                $product->save();
            }
            $this->payments->pay($order, $simulation);
            $cart->items()->whereIn('id', $items->modelKeys())->delete();

            return ['order' => $order->load('items', 'payment'), 'replayed' => false];
        }, 3);
    }
}
