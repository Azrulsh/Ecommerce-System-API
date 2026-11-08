<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $result = $checkout->checkout($request->user(), $request->validated('idempotency_key'), $request->validated('simulation'));

        return (new OrderResource($result['order']))->response()->setStatusCode($result['replayed'] ? 200 : 201)
            ->header('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }
}
