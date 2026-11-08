<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAdminOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderWorkflowService;
use App\Services\SalesSummaryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(IndexAdminOrderRequest $request, SalesSummaryService $reports): AnonymousResourceCollection
    {
        $query = $reports->dateRange(Order::query(), $request->validated())->with('items', 'payment.refund');
        if ($request->has('status')) {
            $query->where('status', $request->validated('status'));
        }

        return OrderResource::collection($query->orderByDesc('id')->paginate($request->validated('per_page', 15))->withQueryString());
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load('items', 'payment.refund'));
    }

    public function update(UpdateOrderStatusRequest $request, Order $order, OrderWorkflowService $workflow): OrderResource
    {
        return new OrderResource($workflow->transition($order, OrderStatus::from($request->validated('status')), $request->validated('simulation')));
    }
}
