<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = ['reference', 'amount', 'status', 'refunded_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'immutable_datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
