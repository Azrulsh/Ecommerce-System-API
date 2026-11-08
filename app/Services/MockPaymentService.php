<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
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

    public function refund(Payment $payment, ?string $simulation = null): Refund
    {
        abort_if($simulation !== null && ! app()->environment(['local', 'testing']), 422, 'Refund simulation headers are disabled in this environment.');

        return DB::transaction(function () use ($payment, $simulation) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($locked->order->status === OrderStatus::Cancelled, 409, 'Only eligible cancelled orders can be refunded.');
            if ($locked->status === 'refunded') {
                return $locked->refund()->firstOrFail();
            }
            abort_unless($locked->status === 'paid', 409, 'Payment is not eligible for refund.');
            $outcome = $simulation ?? config('checkout.mock_refund_outcome');
            abort_unless(in_array($outcome, ['success', 'fail'], true), 503, 'Mock refund configuration is invalid.');
            abort_if($outcome === 'fail', 409, 'Mock refund declined. Cancellation was rolled back.');
            $refund = $locked->refund()->create(['reference' => 'MOCK-REFUND-'.Str::uuid(), 'amount' => $locked->amount,
                'status' => 'refunded', 'refunded_at' => now()]);
            $locked->update(['status' => 'refunded']);

            return $refund;
        });
    }
}
