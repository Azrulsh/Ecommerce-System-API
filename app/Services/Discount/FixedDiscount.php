<?php

namespace App\Services\Discount;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

// second/third question
class FixedDiscount implements DiscountStrategyInterface
{
    private float $discountAmount;

    public function __construct(float $discountAmount)
    {
        $this->discountAmount = $discountAmount;
    }

    public function calculate(float $originalPrice): float
    {
        if ($this->discountAmount > $originalPrice) {
            throw new BadRequestHttpException(
                'Fixed discount must lesst than origin price'
            );
        }

        return $originalPrice - $this->discountAmount;
    }
}
