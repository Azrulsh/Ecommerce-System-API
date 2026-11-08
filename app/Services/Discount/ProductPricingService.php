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
        return $this->eligibleFor($product, $customer !== null && $this->loyalty->eligible($customer), CarbonImmutable::now('UTC'));
    }

    public function prices(Collection $products, ?Authenticatable $customer = null): Collection
    {
        $loyal = $customer !== null && $this->loyalty->eligible($customer);
        $now = CarbonImmutable::now('UTC');

        return $products->mapWithKeys(fn (Product $product) => [
            $product->id => $this->priceFromDiscounts($product, $this->eligibleFor($product, $loyal, $now)),
        ]);
    }

    private function eligibleFor(Product $product, bool $loyal, CarbonImmutable $now): Collection
    {
        abort_unless($product->exists && $product->is_active && ! $product->trashed(), 404);

        $discounts = $product->relationLoaded('discounts') ? $product->discounts->sortBy('id') : $product->discounts()->orderBy('discounts.id')->get();

        return $discounts->filter(function (Discount $discount) use ($product, $now, $loyal) {
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
        return $this->priceFromDiscounts($product, $this->eligibleDiscounts($product, $customer));
    }

    private function priceFromDiscounts(Product $product, Collection $discounts): array
    {
        $original = $product->price;
        $final = $original;
        $applied = null;
        foreach ($discounts as $discount) {
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
