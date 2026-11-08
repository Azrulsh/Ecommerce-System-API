<?php

namespace App\Services\Discount;

interface DecimalDiscountStrategyInterface extends DiscountStrategyInterface
{
    public function calculateDecimal(string $originalPrice): string;
}
