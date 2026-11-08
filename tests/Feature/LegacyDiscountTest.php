<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyDiscountTest extends TestCase
{
    public static function calculations(): array
    {
        return [['fixed', 20, 80], ['fixed', 100, 0], ['fixed', 0, 100],
            ['percentage', 20, 80], ['percentage', 0, 100], ['percentage', 100, 0], ['loyalty', null, 15]];
    }

    #[DataProvider('calculations')]
    public function test_preserves_calculations_and_response_keys(string $type, ?int $value, int $expected): void
    {
        $response = $this->getJson('/discount?'.http_build_query(['type' => $type, 'original' => 100, 'value' => $value]));
        $response->assertOk()->assertExactJson(['original' => 100, 'discounted_amount' => $expected]);
    }

    public static function invalidDiscounts(): array
    {
        return [['fixed', 101, 'Fixed discount must lesst than origin price'],
            ['percentage', -1, 'percentage is between 0 percent and 100 percent'],
            ['percentage', 101, 'percentage is between 0 percent and 100 percent'],
            ['invalid', 10, 'Invalid discount type.']];
    }

    #[DataProvider('invalidDiscounts')]
    public function test_preserves_http_400_errors(string $type, int $value, string $message): void
    {
        $this->getJson('/discount?'.http_build_query(['type' => $type, 'original' => 100, 'value' => $value]))
            ->assertBadRequest()->assertJsonPath('message', $message);
    }
}
