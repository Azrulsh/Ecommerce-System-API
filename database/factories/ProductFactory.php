<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'sku' => fake()->unique()->uuid(),
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'price' => sprintf('%d.%02d', fake()->numberBetween(0, 9999), fake()->numberBetween(0, 99)),
            'stock_quantity' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
