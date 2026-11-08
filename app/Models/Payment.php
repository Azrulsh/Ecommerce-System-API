<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    protected $fillable = ['reference', 'amount', 'status', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
}
