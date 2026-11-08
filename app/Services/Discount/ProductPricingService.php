<?php

namespace App\Services\Discount;

use App\Models\Discount;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ProductPricingService
{
    public function __construct(private readonly LoyaltyEligibility $loyalty) {}

    public function eligibleDiscounts(Product $product, ?Authenticatable $customer = null): Collection
    {
        abort_unless($product->exists && $product->is_active && ! $product->trashed(), 404);
        $now = CarbonImmutable::now('UTC');
        $loyal = $customer !== null && $this->loyalty->eligible($customer);

        return $product->discounts()->orderBy('discounts.id')->get()->filter(function (Discount $discount) use ($product, $now, $loyal) {
            if (! $discount->is_active
                || ($discount->starts_at && $now->lt($discount->starts_at))
                || ($discount->expires_at && $now->gte($discount->expires_at))
                || (($discount->type === 'loyalty' || $discount->requires_loyalty) && ! $loyal)) {
                return false;
            }
            try {
                $this->finalPrice($product, $discount);
            } catch (InvalidArgumentException $exception) {
                return false;
            }

            return true;
        })->values();
    }

    public function price(Product $product, ?Authenticatable $customer = null): array
    {
        $original = $product->price;
        $final = $original;
        $applied = null;
        foreach ($this->eligibleDiscounts($product, $customer) as $discount) {
            $candidate = $this->finalPrice($product, $discount);
            if (Money::minor($candidate) < Money::minor($final)
                || ($applied === null && $candidate === $final)) {
                $final = $candidate;
                $applied = $discount;
            }
        }

        return ['product_id' => $product->id, 'original_price' => $original,
            'discount_amount' => Money::decimal(Money::minor($original) - Money::minor($final)),
            'final_price' => $final, 'applied_discount' => $applied];
    }

    private function finalPrice(Product $product, Discount $discount): string
    {
        return (new DiscountService(new DecimalDiscountStrategy($discount->type, $discount->value)))
            ->applyDiscountDecimal($product->price);
    }
}
