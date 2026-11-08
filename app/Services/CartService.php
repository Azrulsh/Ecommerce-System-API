<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function cart(User $user): Cart
    {
        return $user->cart()->firstOrCreate();
    }

    public function add(User $user, int $productId, int $quantity): Cart
    {
        return DB::transaction(function () use ($user, $productId, $quantity) {
            $cart = $this->lockedCart($user);
            $product = $this->availableProduct($productId);
            $item = $cart->items()->where('product_id', $productId)->first();
            $combined = ($item?->quantity ?? 0) + $quantity;
            $this->validateStock($product, $combined);
            abort_if(! $item && $cart->items()->count() >= 100, 409, 'Cart cannot contain more than 100 distinct products.');
            $item ? $item->update(['quantity' => $combined]) : $cart->items()->create(['product_id' => $productId, 'quantity' => $combined]);

            return $cart;
        }, 3);
    }

    public function update(User $user, CartItem $item, int $quantity): Cart
    {
        return DB::transaction(function () use ($user, $item, $quantity) {
            $cart = $this->lockedCart($user);
            $owned = $cart->items()->findOrFail($item->id);
            $product = $this->availableProduct($owned->product_id);
            $this->validateStock($product, $quantity);
            $owned->update(['quantity' => $quantity]);

            return $cart;
        }, 3);
    }

    public function remove(User $user, CartItem $item): void
    {
        DB::transaction(function () use ($user, $item) {
            $cart = $this->lockedCart($user);
            $cart->items()->findOrFail($item->id)->delete();
        }, 3);
    }

    private function lockedCart(User $user): Cart
    {
        $cart = $this->cart($user);

        return Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
    }

    private function availableProduct(int $id): Product
    {
        return Product::query()->where('is_active', true)->lockForUpdate()->findOrFail($id);
    }

    private function validateStock(Product $product, int $quantity): void
    {
        abort_if($quantity < 1 || $quantity > 1000, 422, 'Cart quantities must be between 1 and 1000.');
        abort_if($quantity > $product->stock_quantity, 409, 'Requested quantity exceeds available stock.');
    }
}
