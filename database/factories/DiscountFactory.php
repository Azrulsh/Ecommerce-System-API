<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DiscountFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Seasonal 10% discount', 'type' => 'percentage', 'value' => '10.00',
            'is_active' => true, 'starts_at' => null, 'expires_at' => null, 'requires_loyalty' => false];
    }
}
