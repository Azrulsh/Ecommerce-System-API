<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Discount extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'value', 'is_active', 'starts_at', 'expires_at', 'requires_loyalty'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'is_active' => 'boolean', 'requires_loyalty' => 'boolean',
            'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }
}
