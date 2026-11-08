<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\CatalogSampleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogSampleSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_samples_are_idempotent_and_preserve_existing_edits_and_deleted_products(): void
    {
        $this->seed(CatalogSampleSeeder::class);
        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('products', 40);
        $this->assertSame(36, Product::where('is_active', true)->count());
        $this->assertSame(4, Product::where('is_active', false)->count());
        foreach (Category::withCount('products')->get() as $category) {
            $this->assertSame(10, $category->products_count);
        }

        $product = Product::where('sku', 'SAMPLE-ELEC-001')->firstOrFail();
        $product->update(['price' => '1999.90', 'stock_quantity' => 1]);
        $deleted = Product::where('sku', 'SAMPLE-FASH-001')->firstOrFail();
        $deleted->delete();
        Category::where('slug', 'electronics')->update(['description' => 'Edited category']);
        $this->seed(CatalogSampleSeeder::class);
        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('products', 40);
        $this->assertSame('1999.90', $product->fresh()->price);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSoftDeleted($deleted);
        $this->assertDatabaseHas('categories', ['slug' => 'electronics', 'description' => 'Edited category']);
    }

    public function test_sample_seeder_refuses_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sample catalog is restricted to local/testing.');
        $this->app->make(CatalogSampleSeeder::class)->run();
    }
}
