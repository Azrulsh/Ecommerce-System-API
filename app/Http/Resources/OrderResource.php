<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'order_number' => $this->order_number, 'status' => $this->status->value,
            'currency' => $this->currency, 'subtotal' => $this->subtotal, 'discount_total' => $this->discount_total,
            'grand_total' => $this->grand_total, 'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'stock_restored_at' => $this->stock_restored_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
