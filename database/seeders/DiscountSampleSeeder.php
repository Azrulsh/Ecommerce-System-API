<?php

namespace Database\Seeders;

use App\Models\Discount;
use App\Models\Product;
use App\Services\Discount\DiscountAdministrationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DiscountSampleSeeder extends Seeder
{
    public function run(DiscountAdministrationService $admin): void
    {
        $expected = match (app()->environment()) {
            'local' => 'ecommerce-api',
            'testing' => 'ecommerce_api_testing',
            default => throw new RuntimeException('Sample discounts are restricted to local/testing.'),
        };
        $connection = config('database.connections.mysql');
        if (config('database.default') !== 'mysql'
            || ($connection['database'] ?? null) !== $expected
            || ($connection['host'] ?? null) !== '127.0.0.1'
            || (string) ($connection['port'] ?? '') !== '3306'
            || ! empty($connection['url'])
            || ! empty($connection['read'])
            || ! empty($connection['write'])
            || DB::selectOne('SELECT DATABASE() AS name')->name !== $expected) {
            throw new RuntimeException('Sample discount database target mismatch.');
        }

        $catalog = [
            'electronics' => 'SAMPLE-ELEC-', 'fashion' => 'SAMPLE-FASH-',
            'gaming' => 'SAMPLE-GAME-', 'home-living' => 'SAMPLE-HOME-',
        ];
        $samples = [
            ['Electronics Sale', 'percentage', '10.00', 'electronics'],
            ['Fashion Promotion', 'percentage', '20.00', 'fashion'],
            ['Gaming Voucher', 'fixed', '50.00', 'gaming'],
            ['Home Living Deal', 'fixed', '30.00', 'home-living'],
            ['VIP Loyalty Discount', 'loyalty', '85.00', null],
        ];
        $now = CarbonImmutable::now('UTC');

        DB::transaction(function () use ($admin, $samples, $catalog, $now) {
            foreach ($samples as [$name, $type, $value, $slug]) {
                // Names are reserved sample identities. Never update existing rows or their assignments.
                if (Discount::query()->where('name', $name)->exists()) {
                    continue;
                }
                $products = Product::query()->where('is_active', true)->where('stock_quantity', '>', 0)
                    ->whereHas('category', function ($categories) use ($slug, $catalog) {
                        $categories->whereIn('slug', $slug === null ? array_keys($catalog) : [$slug]);
                    })
                    ->where(function ($products) use ($catalog) {
                        foreach ($catalog as $category => $prefix) {
                            $products->orWhere(function ($products) use ($category, $prefix) {
                                $products->where('sku', 'like', $prefix.'%')
                                    ->whereHas('category', fn ($categories) => $categories->where('slug', $category));
                            });
                        }
                    })
                    ->when($type === 'fixed', fn ($products) => $products->where('price', '>=', $value))
                    ->orderBy('id')->get();

                if ($products->isEmpty()) {
                    throw new RuntimeException('No suitable seeded products found for '.$name.'. Run the catalog sample setup first.');
                }
                $discount = $admin->create([
                    'name' => $name, 'type' => $type, 'value' => $value,
                    'is_active' => true, 'requires_loyalty' => $type === 'loyalty',
                    'starts_at' => $now->startOfDay(), 'expires_at' => $now->startOfDay()->addDays(30),
                ]);
                $admin->assignProducts($discount, $products->modelKeys());
            }
        });
    }
}
