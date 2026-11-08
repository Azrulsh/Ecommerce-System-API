<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['product_id', 'sku_snapshot', 'product_name_snapshot', 'quantity', 'unit_original_price', 'unit_discount', 'unit_final_price', 'line_total', 'applied_discount_snapshot'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_original_price' => 'decimal:2', 'unit_discount' => 'decimal:2',
            'unit_final_price' => 'decimal:2', 'line_total' => 'decimal:2', 'applied_discount_snapshot' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
