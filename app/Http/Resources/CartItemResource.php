<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $data['product'] = $data['product'] ? new ProductResource($data['product']) : null;
        $discount = $data['applied_discount'];
        $data['applied_discount'] = $discount ? ['id' => $discount->id, 'name' => $discount->name, 'type' => $discount->type] : null;

        return $data;
    }
}
