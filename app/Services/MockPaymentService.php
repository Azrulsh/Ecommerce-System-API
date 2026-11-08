<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;

class MockPaymentService
{
    public function pay(Order $order, ?string $simulation = null): Payment
    {
        abort_if($simulation !== null && ! app()->environment(['local', 'testing']), 422, 'Payment simulation headers are disabled in this environment.');
        $outcome = $simulation ?? config('checkout.mock_payment_outcome');
        abort_unless(in_array($outcome, ['success', 'fail'], true), 503, 'Mock payment configuration is invalid.');
        abort_if($outcome === 'fail', 409, 'Mock payment declined. Checkout was rolled back.');

        return $order->payment()->create(['reference' => 'MOCK-'.Str::uuid(), 'amount' => $order->grand_total,
            'status' => 'paid', 'paid_at' => now()]);
    }
}
