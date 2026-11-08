<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WishlistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id,
            'is_available' => $this->product && ! $this->product->trashed() && $this->product->is_active,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toISOString()];
    }
}
