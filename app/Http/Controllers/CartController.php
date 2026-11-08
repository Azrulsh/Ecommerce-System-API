<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\CartItem;
use App\Services\CartPricingService;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts, private readonly CartPricingService $pricing) {}

    public function show(Request $request): CartResource
    {
        return new CartResource($this->pricing->summarize($this->carts->cart($request->user()), $request->user()));
    }

    public function store(StoreCartItemRequest $request): JsonResponse
    {
        $cart = $this->carts->add($request->user(), (int) $request->validated('product_id'), (int) $request->validated('quantity'));

        return (new CartResource($this->pricing->summarize($cart, $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateCartItemRequest $request, CartItem $item): CartResource
    {
        $cart = $this->carts->update($request->user(), $item, (int) $request->validated('quantity'));

        return new CartResource($this->pricing->summarize($cart, $request->user()));
    }

    public function destroy(Request $request, CartItem $item): Response
    {
        $this->carts->remove($request->user(), $item);

        return response()->noContent();
    }
}
