<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'items' => CartItemResource::collection($this->resource['items']),
            'subtotal' => $this->resource['subtotal'], 'total_savings' => $this->resource['total_savings'],
            'grand_total' => $this->resource['grand_total'], 'is_ready' => $this->resource['is_ready']];
    }
}
