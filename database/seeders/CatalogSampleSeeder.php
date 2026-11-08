<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CatalogSampleSeeder extends Seeder
{
    public function run(): void
    {
        $expected = match (app()->environment()) {
            'local' => 'ecommerce-api',
            'testing' => 'ecommerce_api_testing',
            default => throw new RuntimeException('Sample catalog is restricted to local/testing.'),
        };

        if (config('database.default') !== 'mysql'
            || DB::connection()->getDatabaseName() !== $expected
            || DB::selectOne('SELECT DATABASE() AS name')->name !== $expected) {
            throw new RuntimeException('Sample catalog database target mismatch.');
        }

        // Insert missing samples only: preserve edits and soft-deleted SKU reservations.
        DB::transaction(function () {
            foreach ($this->catalog() as $slug => [$name, $description, $products]) {
                $category = Category::firstOrCreate(['slug' => $slug], compact('name', 'description'));

                foreach ($products as [$sku, $name, $description, $price, $stock, $active]) {
                    Product::withTrashed()->firstOrCreate(['sku' => $sku], [
                        'category_id' => $category->id,
                        'name' => $name,
                        'description' => $description,
                        'price' => $price,
                        'stock_quantity' => $stock,
                        'is_active' => $active,
                    ]);
                }
            }
        });
    }

    private function catalog(): array
    {
        // Prices are sample Malaysian Ringgit amounts, stored as decimal strings.
        return [
            'electronics' => ['Electronics', 'Everyday electronics and mobile accessories.', [
                ['SAMPLE-ELEC-001', '14-inch Everyday Laptop', 'Portable laptop with 16GB RAM and a 512GB SSD for study and work.', '2499.00', 12, true],
                ['SAMPLE-ELEC-002', '15-inch Performance Laptop', 'Laptop with 16GB RAM, a 1TB SSD and a full HD display.', '3599.00', 8, true],
                ['SAMPLE-ELEC-003', '27-inch QHD Monitor', 'IPS desktop monitor with HDMI input and an adjustable stand.', '899.00', 20, true],
                ['SAMPLE-ELEC-004', 'Wireless Noise Cancelling Headphones', 'Over-ear Bluetooth headphones with USB-C charging and a travel case.', '299.90', 35, true],
                ['SAMPLE-ELEC-005', 'True Wireless Earbuds', 'Compact earbuds with a charging case and splash resistance.', '129.90', 50, true],
                ['SAMPLE-ELEC-006', '20000mAh Power Bank', 'Portable USB-C battery pack with dual charging outputs.', '99.00', 42, true],
                ['SAMPLE-ELEC-007', '65W USB-C Charger', 'Compact fast charger with a Malaysian three-pin plug.', '89.90', 60, true],
                ['SAMPLE-ELEC-008', '1TB Portable SSD', 'Pocket-sized solid state drive with a USB-C cable.', '349.00', 18, true],
                ['SAMPLE-ELEC-009', 'Full HD Webcam', '1080p USB webcam with a privacy cover for video calls.', '119.00', 0, true],
                ['SAMPLE-ELEC-010', 'Wi-Fi 6 Router', 'Dual-band home router with gigabit Ethernet ports; currently unavailable.', '259.00', 0, false],
            ]],
            'fashion' => ['Fashion', 'Casual clothing, footwear and everyday accessories.', [
                ['SAMPLE-FASH-001', 'Cotton Crew Neck T-Shirt', 'Breathable navy cotton T-shirt in a regular unisex fit, size M.', '39.90', 80, true],
                ['SAMPLE-FASH-002', 'Linen Blend Shirt', 'Lightweight sand-coloured long-sleeve shirt, size L.', '89.90', 30, true],
                ['SAMPLE-FASH-003', 'Slim Fit Jeans', 'Dark indigo stretch denim jeans with a 32-inch waist.', '129.00', 25, true],
                ['SAMPLE-FASH-004', 'Everyday Sneakers', 'White lace-up casual shoes with cushioned soles, EU size 42.', '159.00', 22, true],
                ['SAMPLE-FASH-005', 'Canvas Tote Bag', 'Reusable natural cotton tote with an internal pocket.', '29.90', 100, true],
                ['SAMPLE-FASH-006', 'Lightweight Hijab', 'Soft dusty rose chiffon hijab for everyday wear.', '35.00', 55, true],
                ['SAMPLE-FASH-007', 'Classic Baju Kurung Set', 'Two-piece teal baju kurung with a matching skirt, size M.', '189.00', 16, true],
                ['SAMPLE-FASH-008', 'Sports Ankle Socks 3-Pack', 'Three pairs of breathable ankle socks in mixed neutral colours.', '24.90', 90, true],
                ['SAMPLE-FASH-009', 'Compact Crossbody Bag', 'Black crossbody bag with an adjustable strap and zip closure.', '79.90', 28, true],
                ['SAMPLE-FASH-010', 'Lightweight Rain Jacket', 'Packable water-resistant jacket, size L; awaiting seasonal restock.', '119.90', 0, false],
            ]],
            'home-living' => ['Home & Living', 'Practical essentials for comfortable Malaysian homes.', [
                ['SAMPLE-HOME-001', '1.7L Electric Kettle', 'Stainless steel kettle with auto shut-off and a Malaysian plug.', '79.00', 32, true],
                ['SAMPLE-HOME-002', '4L Digital Air Fryer', 'Countertop air fryer with adjustable temperature and a removable basket.', '249.00', 14, true],
                ['SAMPLE-HOME-003', '1.8L Rice Cooker', 'Family rice cooker with a non-stick pot and a keep-warm function.', '139.90', 24, true],
                ['SAMPLE-HOME-004', 'Cotton Bath Towel', 'Soft absorbent grey bath towel measuring 70 by 140 cm.', '39.00', 70, true],
                ['SAMPLE-HOME-005', 'Queen Cotton Bedsheet Set', 'Queen-size fitted sheet with two matching pillowcases.', '149.00', 26, true],
                ['SAMPLE-HOME-006', 'Adjustable LED Desk Lamp', 'Dimmable desk lamp with warm and cool lighting modes.', '69.90', 38, true],
                ['SAMPLE-HOME-007', 'Ceramic Dinner Plate Set', 'Set of four microwave-safe 26 cm ceramic dinner plates.', '89.00', 20, true],
                ['SAMPLE-HOME-008', 'Stackable Storage Box 30L', 'Clear lidded storage box for household organisation.', '34.90', 65, true],
                ['SAMPLE-HOME-009', 'Insulated Water Bottle 750ml', 'Reusable stainless steel bottle with a leak-resistant lid.', '59.90', 48, true],
                ['SAMPLE-HOME-010', 'Compact Standing Fan', 'Three-speed oscillating fan with adjustable height; discontinued sample.', '129.00', 0, false],
            ]],
            'gaming' => ['Gaming', 'Gaming peripherals and desk accessories.', [
                ['SAMPLE-GAME-001', 'Mechanical Gaming Keyboard', 'Wired RGB keyboard with tactile switches and a full-size layout.', '219.00', 24, true],
                ['SAMPLE-GAME-002', 'Lightweight Gaming Mouse', 'Wired optical mouse with adjustable sensitivity and side buttons.', '99.90', 45, true],
                ['SAMPLE-GAME-003', 'Surround Gaming Headset', 'Over-ear wired headset with a flexible boom microphone.', '159.00', 30, true],
                ['SAMPLE-GAME-004', 'Wireless Game Controller', 'Rechargeable Bluetooth controller for compatible PCs and mobile games.', '179.00', 22, true],
                ['SAMPLE-GAME-005', 'Extended Desk Mouse Mat', 'Non-slip stitched-edge desk mat measuring 90 by 40 cm.', '49.90', 75, true],
                ['SAMPLE-GAME-006', 'Ergonomic Gaming Chair', 'Adjustable fabric chair with lumbar cushion and reclining backrest.', '699.00', 9, true],
                ['SAMPLE-GAME-007', '144Hz Gaming Monitor', '24-inch full HD gaming monitor with HDMI and DisplayPort inputs.', '649.00', 15, true],
                ['SAMPLE-GAME-008', 'Handheld Console Carry Case', 'Protective zip case with padded lining and accessory storage.', '69.00', 40, true],
                ['SAMPLE-GAME-009', 'USB Streaming Microphone', 'Desktop cardioid microphone with a mute button and stand.', '189.90', 0, true],
                ['SAMPLE-GAME-010', 'RGB Headset Stand', 'USB-powered desktop headset stand; sample listing awaiting stock.', '79.00', 0, false],
            ]],
        ];
    }
}
