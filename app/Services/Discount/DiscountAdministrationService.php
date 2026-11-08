<?php

namespace App\Services\Discount;

use App\Models\Discount;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Trusted application service. Future admin controllers must authorize before invoking it.
class DiscountAdministrationService
{
    public function create(array $attributes): Discount
    {
        return Discount::create($this->validated($attributes));
    }

    public function update(Discount $discount, array $attributes): Discount
    {
        return DB::transaction(function () use ($discount, $attributes) {
            $locked = Discount::query()->lockForUpdate()->findOrFail($discount->id);
            $data = $this->validated(array_replace($locked->only([
                'name', 'type', 'value', 'is_active', 'starts_at', 'expires_at', 'requires_loyalty',
            ]), $attributes));
            $products = $locked->products()->orderBy('products.id')->lockForUpdate()->get();
            $this->validateProductPrices($data, $products);
            $locked->update($data);

            return $locked->refresh();
        });
    }

    public function deactivate(Discount $discount): Discount
    {
        // Deactivation must remain possible even if a catalog price changed since assignment.
        return DB::transaction(function () use ($discount) {
            $locked = Discount::query()->lockForUpdate()->findOrFail($discount->id);
            $locked->update(['is_active' => false]);

            return $locked->refresh();
        });
    }

    public function assignProducts(Discount $discount, array $productIds): void
    {
        $ids = Validator::make(['product_ids' => $productIds], [
            'product_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
        ])->validate()['product_ids'];

        DB::transaction(function () use ($discount, $ids) {
            $locked = Discount::query()->lockForUpdate()->findOrFail($discount->id);
            $data = $this->validated($locked->only([
                'name', 'type', 'value', 'is_active', 'starts_at', 'expires_at', 'requires_loyalty',
            ]));
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== count($ids)) {
                throw ValidationException::withMessages(['product_ids' => 'Every product must exist and must not be deleted.']);
            }
            $this->validateProductPrices($data, $products);
            $locked->products()->syncWithoutDetaching($products->modelKeys());
        });
    }

    private function validated(array $attributes): array
    {
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['fixed', 'percentage', 'loyalty'])],
            'value' => ['required', 'numeric', 'decimal:0,2', 'regex:/^\d+(?:\.\d{1,2})?$/D', 'min:0', 'max:9999999999.99'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', ...(! empty($attributes['starts_at']) ? ['after:starts_at'] : [])],
            'requires_loyalty' => ['sometimes', 'boolean'],
        ])->validate();

        if (($data['type'] === 'percentage' && Money::minor((string) $data['value']) > 10000)
            || ($data['type'] === 'loyalty' && Money::minor((string) $data['value']) !== 8500)) {
            throw ValidationException::withMessages(['value' => 'Percentages must be 0–100; loyalty discounts must use 85.00.']);
        }
        $data['value'] = Money::decimal(Money::minor((string) $data['value']));
        if ($data['type'] === 'loyalty') {
            $data['requires_loyalty'] = true;
        }
        foreach (['starts_at', 'expires_at'] as $field) {
            $data[$field] = empty($data[$field]) ? null : CarbonImmutable::parse($data[$field], 'UTC')->utc();
        }

        return $data;
    }

    private function validateProductPrices(array $data, iterable $products): void
    {
        if ($data['type'] !== 'fixed') {
            return;
        }
        foreach ($products as $product) {
            if (Money::minor($data['value']) > Money::minor($product->price)) {
                throw ValidationException::withMessages(['value' => 'Fixed discount cannot exceed any assigned product price.']);
            }
        }
    }
}
