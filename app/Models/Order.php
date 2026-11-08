<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = ['order_number', 'user_id', 'status', 'currency', 'subtotal', 'discount_total', 'grand_total', 'checkout_idempotency_key'];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'grand_total' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
