<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type,
            'value' => $this->value, 'is_active' => $this->is_active,
            'starts_at' => $this->starts_at?->utc()->toISOString(),
            'expires_at' => $this->expires_at?->utc()->toISOString(),
            'requires_loyalty' => $this->requires_loyalty,
            'product_ids' => $this->whenLoaded('products', fn () => $this->products->modelKeys())];
    }
}
