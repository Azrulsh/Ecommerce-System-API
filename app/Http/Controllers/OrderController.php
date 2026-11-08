<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(IndexOrderRequest $request): AnonymousResourceCollection
    {
        return OrderResource::collection($request->user()->orders()->with('items', 'payment')
            ->orderByDesc('id')->paginate($request->validated('per_page', 15))->withQueryString());
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load('items', 'payment'));
    }

    public function payment(Order $order): PaymentResource
    {
        return new PaymentResource($order->payment()->firstOrFail());
    }
}
