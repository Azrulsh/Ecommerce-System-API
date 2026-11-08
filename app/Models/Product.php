<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['category_id', 'sku', 'name', 'description', 'price', 'stock_quantity', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'stock_quantity' => 'integer', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function discounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class)->withTimestamps();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
