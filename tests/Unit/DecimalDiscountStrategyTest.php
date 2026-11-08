<?php

namespace Tests\Unit;

use App\Services\Discount\DecimalDiscountStrategy;
use App\Services\Discount\DiscountService;
use App\Services\Discount\FixedDiscount;
use App\Services\Discount\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalDiscountStrategyTest extends TestCase
{
    public static function calculations(): array
    {
        return [
            ['fixed', '20.00', '100.00', '80.00'],
            ['fixed', '0.10', '0.30', '0.20'],
            ['fixed', '0000.10', '0000.30', '0.20'],
            ['fixed', '100.00', '100.00', '0.00'],
            ['percentage', '10.00', '1299.90', '1169.91'],
            ['percentage', '33.33', '19.99', '13.33'],
            ['percentage', '10.00', '0.05', '0.04'],
            ['percentage', '0.00', '100.00', '100.00'],
            ['percentage', '100.00', '100.00', '0.00'],
            ['percentage', '99.99', '9999999999.99', '1000000.00'],
            ['loyalty', '85.00', '100.00', '15.00'],
            ['loyalty', '85.00', '0.10', '0.01'],
            ['loyalty', '85.00', '0.00', '0.00'],
        ];
    }

    #[DataProvider('calculations')]
    public function test_exact_calculations(string $type, string $value, string $original, string $expected): void
    {
        $service = new DiscountService(new DecimalDiscountStrategy($type, $value));
        $this->assertSame($expected, $service->applyDiscountDecimal($original));
    }

    public static function invalidValues(): array
    {
        return [['fixed', '-1'], ['percentage', '100.01'], ['percentage', '-0.01'],
            ['loyalty', '15.00'], ['unknown', '1.00'], ['fixed', '1.001']];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_values(string $type, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DecimalDiscountStrategy($type, $value);
    }

    public function test_rejects_fixed_value_exceeding_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DecimalDiscountStrategy('fixed', '1.01'))->calculateDecimal('1.00');
    }

    public function test_legacy_strategy_cannot_silently_fall_back_to_float_for_exact_pricing(): void
    {
        $this->expectException(\LogicException::class);
        (new DiscountService(new FixedDiscount(1)))->applyDiscountDecimal('10.00');
    }

    public function test_adapter_preserves_legacy_strategy_calculations(): void
    {
        foreach (['fixed' => ['20.00', 80.0], 'percentage' => ['20.00', 80.0], 'loyalty' => ['85.00', 15.0]] as $type => [$value, $expected]) {
            $this->assertSame($expected, (new DiscountService(new DecimalDiscountStrategy($type, $value)))->applyDiscount(100));
        }
    }

    public function test_money_rejects_excess_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::minor('0.001');
    }
}
