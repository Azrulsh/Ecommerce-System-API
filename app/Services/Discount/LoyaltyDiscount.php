<?php

namespace App\Services\Discount;

// second question
class LoyaltyDiscount implements DiscountStrategyInterface
{
    public function calculate(float $originalPrice): float
    {
        return $originalPrice - (0.85 * $originalPrice);
    }
}
