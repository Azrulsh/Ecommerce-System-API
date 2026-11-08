<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\User;
use App\Services\Discount\Money;
use App\Services\Discount\ProductPricingService;

class CartPricingService
{
    public function __construct(private readonly ProductPricingService $pricing) {}

    public function summarize(Cart $cart, User $user): array
    {
        abort_unless($cart->user_id === $user->id, 404);
        $cart->load('items.product.category', 'items.product.discounts');
        $products = $cart->items->pluck('product')->filter(fn ($product) => $product && ! $product->trashed() && $product->is_active)->unique('id');
        $prices = $this->pricing->prices($products, $user);
        $subtotal = $savings = $total = 0;
        $hasUnpriced = false;
        $ready = true;
        $items = [];
        foreach ($cart->items as $item) {
            $product = $item->product;
            $availability = match (true) {
                ! $product || $product->trashed() => 'deleted',
                ! $product->is_active => 'inactive',
                $item->quantity > $product->stock_quantity => 'insufficient_stock',
                default => 'available',
            };
            $price = $prices->get($item->product_id);
            $lineSubtotal = $product ? Money::minor($product->price) * $item->quantity : 0;
            $lineDiscount = $price ? Money::minor($price['discount_amount']) * $item->quantity : null;
            $lineTotal = $price ? Money::minor($price['final_price']) * $item->quantity : null;
            $subtotal += $lineSubtotal;
            $savings += $lineDiscount ?? 0;
            $total += $lineTotal ?? 0;
            $hasUnpriced = $hasUnpriced || $price === null;
            $ready = $ready && $availability === 'available';
            $items[] = ['id' => $item->id, 'product' => $product, 'quantity' => $item->quantity,
                'availability' => $availability, 'available_stock' => $product?->stock_quantity ?? 0,
                'unit_original_price' => $product?->price, 'unit_final_price' => $price['final_price'] ?? null,
                'applied_discount' => $price['applied_discount'] ?? null,
                'line_subtotal' => Money::decimal($lineSubtotal),
                'line_discount_total' => $lineDiscount === null ? null : Money::decimal($lineDiscount),
                'line_final_total' => $lineTotal === null ? null : Money::decimal($lineTotal)];
        }

        return ['id' => $cart->id, 'items' => $items, 'subtotal' => Money::decimal($subtotal),
            'total_savings' => $hasUnpriced ? null : Money::decimal($savings),
            'grand_total' => $hasUnpriced ? null : Money::decimal($total), 'is_ready' => $ready];
    }
}
