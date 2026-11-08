<?php

namespace App\Services\Discount;

use InvalidArgumentException;

final class DecimalDiscountStrategy implements DecimalDiscountStrategyInterface
{
    public function __construct(private readonly string $type, private readonly string $value)
    {
        $amount = Money::minor($value);
        if (! in_array($type, ['fixed', 'percentage', 'loyalty'], true)
            || ($type === 'percentage' && $amount > 10000)
            || ($type === 'loyalty' && $amount !== 8500)) {
            throw new InvalidArgumentException('Invalid discount type or value.');
        }
    }

    // Compatibility adapter only; new product pricing always uses calculateDecimal.
    public function calculate(float $originalPrice): float
    {
        $strategy = match ($this->type) {
            'fixed' => new FixedDiscount((float) $this->value),
            'percentage' => new PercentageDiscount((float) $this->value),
            'loyalty' => new LoyaltyDiscount,
        };

        return $strategy->calculate($originalPrice);
    }

    public function calculateDecimal(string $originalPrice): string
    {
        $price = Money::minor($originalPrice);
        $value = Money::minor($this->value);
        if ($this->type === 'fixed' && $value > $price) {
            throw new InvalidArgumentException('Fixed discount cannot exceed product price.');
        }
        // Round percentage savings half-up to the nearest cent using integer basis points.
        $saving = $this->type === 'fixed' ? $value : intdiv($price * $value + 5000, 10000);

        return Money::decimal($price - $saving);
    }
}
