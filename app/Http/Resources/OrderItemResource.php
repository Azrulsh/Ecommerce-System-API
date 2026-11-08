<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id, 'sku_snapshot' => $this->sku_snapshot,
            'product_name_snapshot' => $this->product_name_snapshot, 'quantity' => $this->quantity,
            'unit_original_price' => $this->unit_original_price, 'unit_discount' => $this->unit_discount,
            'unit_final_price' => $this->unit_final_price, 'line_total' => $this->line_total,
            'applied_discount_snapshot' => $this->applied_discount_snapshot];
    }
}
