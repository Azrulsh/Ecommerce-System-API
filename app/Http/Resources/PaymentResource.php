<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'order_id' => $this->order_id, 'reference' => $this->reference,
            'amount' => $this->amount, 'status' => $this->status, 'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString()];
    }
}
