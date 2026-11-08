<?php

namespace App\Services\Discount;

// third question
class DiscountService
{
    private DiscountStrategyInterface $strategy;

    public function __construct(DiscountStrategyInterface $strategy)
    {
        $this->strategy = $strategy;
    }

    public function applyDiscount(float $price): float
    {
        return $this->strategy->calculate($price);
    }

    public function applyDiscountDecimal(string $price): string
    {
        if (! $this->strategy instanceof DecimalDiscountStrategyInterface) {
            throw new \LogicException('Exact pricing requires a decimal discount strategy.');
        }

        return $this->strategy->calculateDecimal($price);
    }
}
