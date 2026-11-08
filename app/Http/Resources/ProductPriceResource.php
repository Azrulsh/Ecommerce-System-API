<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $discount = $this->resource['applied_discount'];

        return ['product_id' => $this->resource['product_id'],
            'original_price' => $this->resource['original_price'],
            'discount_amount' => $this->resource['discount_amount'],
            'final_price' => $this->resource['final_price'],
            'applied_discount' => $discount ? ['id' => $discount->id, 'name' => $discount->name, 'type' => $discount->type] : null];
    }
}
