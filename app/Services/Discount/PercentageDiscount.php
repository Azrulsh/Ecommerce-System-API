<?php

namespace App\Services\Discount;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

// second/third question
class PercentageDiscount implements DiscountStrategyInterface
{
    private float $percentage;

    public function __construct(float $percentage)
    {
        $this->percentage = $percentage;
    }

    public function calculate(float $originalPrice): float
    {

        if ($this->percentage < 0 || $this->percentage > 100) {
            throw new BadRequestHttpException(
                'percentage is between 0 percent and 100 percent'
            );
        }

        return $originalPrice - (
            ($this->percentage / 100) * $originalPrice
        );
    }
}
