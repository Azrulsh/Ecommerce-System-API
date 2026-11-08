<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use App\Services\Discount\DiscountAdministrationService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSampleSeeder;
use Database\Seeders\DiscountSampleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscountSampleSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sample_discounts_have_valid_relationships_dates_and_exact_prices(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        $this->seed(CatalogSampleSeeder::class);
        $before = Product::orderBy('id')->get()->toJson();
        $categories = Category::orderBy('id')->get()->toJson();
        $this->seed(DiscountSampleSeeder::class);
        $this->assertDatabaseCount('discounts', 5);
        $this->assertDatabaseCount('discount_product', 67);
        foreach (['Electronics Sale' => 8, 'Fashion Promotion' => 9, 'Gaming Voucher' => 7,
            'Home Living Deal' => 9, 'VIP Loyalty Discount' => 34] as $name => $count) {
            $discount = Discount::where('name', $name)->firstOrFail();
            $this->assertSame($count, $discount->products()->count());
            $this->assertTrue($discount->is_active);
            $this->assertSame('2026-11-08 00:00:00', $discount->starts_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-12-08 00:00:00', $discount->expires_at->format('Y-m-d H:i:s'));
        }
        foreach ([['SAMPLE-ELEC-001', '249.90', '2249.10'], ['SAMPLE-FASH-001', '7.98', '31.92'],
            ['SAMPLE-GAME-001', '50.00', '169.00'], ['SAMPLE-HOME-001', '30.00', '49.00']] as [$sku, $saving, $final]) {
            $product = Product::where('sku', $sku)->firstOrFail();
            $this->getJson('/api/products/'.$product->id.'/price')->assertOk()
                ->assertJsonPath('data.discount_amount', $saving)->assertJsonPath('data.final_price', $final);
        }
        $loyaltyOnly = Product::where('sku', 'SAMPLE-GAME-005')->firstOrFail();
        $this->getJson('/api/products/'.$loyaltyOnly->id.'/price?is_loyalty_member=1')
            ->assertJsonPath('data.final_price', '49.90')->assertJsonPath('data.applied_discount', null);
        $this->getJson('/api/products/'.$loyaltyOnly->id.'/discounts')->assertExactJson(['data' => []]);
        $this->assertSame($before, Product::orderBy('id')->get()->toJson());
        $this->assertSame($categories, Category::orderBy('id')->get()->toJson());
    }

    public function test_rerunning_preserves_manual_edits_expiration_and_assignments_without_duplicates(): void
    {
        $this->seed(CatalogSampleSeeder::class);
        $this->seed(DiscountSampleSeeder::class);
        $discount = Discount::where('name', 'Electronics Sale')->firstOrFail();
        $discount->update(['value' => '5.00', 'is_active' => false, 'expires_at' => '2020-01-01 00:00:00']);
        $discount->products()->detach($discount->products()->firstOrFail());
        $rows = Discount::orderBy('id')->get()->toJson();
        $pivots = DB::table('discount_product')->orderBy('discount_id')->orderBy('product_id')->get()->toJson();
        $this->travelTo(CarbonImmutable::now('UTC')->addDays(40));
        $this->seed(DiscountSampleSeeder::class);
        $this->assertDatabaseCount('discounts', 5);
        $this->assertDatabaseCount('discount_product', 66);
        $this->assertSame($rows, Discount::orderBy('id')->get()->toJson());
        $this->assertSame($pivots, DB::table('discount_product')->orderBy('discount_id')->orderBy('product_id')->get()->toJson());
    }

    public function test_wrong_category_inactive_deleted_out_of_stock_and_underpriced_products_are_excluded(): void
    {
        $this->seed(CatalogSampleSeeder::class);
        Product::where('sku', 'SAMPLE-GAME-001')->update(['price' => '49.99']);
        Product::where('sku', 'SAMPLE-ELEC-001')->update(['category_id' => Category::where('slug', 'gaming')->sole()->id]);
        Product::where('sku', 'SAMPLE-ELEC-002')->firstOrFail()->delete();
        $this->seed(DiscountSampleSeeder::class);
        $gaming = Discount::where('name', 'Gaming Voucher')->sole();
        $this->assertFalse($gaming->products()->where('sku', 'SAMPLE-GAME-001')->exists());
        $electronics = Discount::where('name', 'Electronics Sale')->sole();
        $this->assertSame(6, $electronics->products()->count());
        $this->assertFalse($electronics->products()->whereIn('sku', ['SAMPLE-ELEC-001', 'SAMPLE-ELEC-002', 'SAMPLE-ELEC-009', 'SAMPLE-ELEC-010'])->exists());
    }

    public function test_missing_catalog_fails_without_partial_discount_creation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No suitable seeded products');
        try {
            $this->seed(DiscountSampleSeeder::class);
        } finally {
            $this->assertDatabaseCount('discounts', 0);
        }
    }

    public function test_production_execution_is_rejected(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sample discounts are restricted to local/testing.');
        $this->app->make(DiscountSampleSeeder::class)->run($this->app->make(DiscountAdministrationService::class));
    }

    public function test_partial_catalog_failure_rolls_back_earlier_discount_and_assignment(): void
    {
        $category = Category::factory()->create(['slug' => 'electronics']);
        Product::factory()->for($category)->create(['sku' => 'SAMPLE-ELEC-001', 'stock_quantity' => 1]);
        try {
            $this->seed(DiscountSampleSeeder::class);
            $this->fail('Expected missing Fashion samples to stop the seeder.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Fashion Promotion', $exception->getMessage());
        }
        $this->assertDatabaseCount('discounts', 0);
        $this->assertDatabaseCount('discount_product', 0);
    }

    public function test_unexpected_database_configuration_is_rejected_before_seeding(): void
    {
        config(['database.connections.mysql.database' => 'ecommerce-api']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sample discount database target mismatch.');
        $this->app->make(DiscountSampleSeeder::class)->run($this->app->make(DiscountAdministrationService::class));
    }
}
