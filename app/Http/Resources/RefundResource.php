<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'amount' => $this->amount,
            'status' => $this->status, 'refunded_at' => $this->refunded_at->toISOString()];
    }
}
