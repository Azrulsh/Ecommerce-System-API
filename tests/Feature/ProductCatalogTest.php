<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'category_id' => Category::factory()->create()->id,
            'sku' => 'LAPTOP-001', 'name' => 'Laptop', 'description' => 'A laptop',
            'price' => '1299.90', 'stock_quantity' => 10, 'is_active' => true,
        ], $overrides);
    }

    public function test_lists_only_active_non_deleted_products_with_category_and_decimal_prices(): void
    {
        $visible = Product::factory()->create(['price' => '19.90']);
        Product::factory()->create(['is_active' => false]);
        Product::factory()->create()->delete();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)->assertJsonPath('data.0.price', '19.90')
            ->assertJsonPath('data.0.category.id', $visible->category_id)->assertJsonPath('meta.total', 1);
    }

    public function test_product_details_and_missing_inactive_or_deleted_products(): void
    {
        $product = Product::factory()->create();
        $this->getJson('/api/products/'.$product->id)->assertOk()->assertJsonPath('data.id', $product->id);
        $product->update(['is_active' => false]);
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
        $product->delete();
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
        $this->getJson('/api/products/99999999')->assertNotFound();
    }

    public function test_creates_product_with_decimal_price_and_default_active_status(): void
    {
        $payload = $this->payload();
        unset($payload['is_active']);
        $this->postJson('/api/products', $payload)->assertCreated()
            ->assertJsonPath('data.price', '1299.90')->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('products', ['sku' => 'LAPTOP-001', 'price' => '1299.90']);
    }

    public function test_put_replaces_required_fields_and_allows_unchanged_sku(): void
    {
        $product = Product::factory()->create();
        $payload = $this->payload(['sku' => $product->sku, 'name' => 'Updated']);
        $this->putJson('/api/products/'.$product->id, $payload)->assertOk()->assertJsonPath('data.name', 'Updated');
        $this->putJson('/api/products/'.$product->id, ['name' => 'Incomplete'])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'sku', 'price', 'stock_quantity']);
    }

    public function test_patch_updates_only_supplied_fields_including_inactive_products(): void
    {
        $product = Product::factory()->create(['is_active' => false, 'price' => '10.00']);
        $this->patchJson('/api/products/'.$product->id, ['stock_quantity' => 0, 'is_active' => true])
            ->assertOk()->assertJsonPath('data.price', '10.00')->assertJsonPath('data.stock_quantity', 0)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_delete_soft_deletes_product(): void
    {
        $product = Product::factory()->create();
        $this->deleteJson('/api/products/'.$product->id)->assertNoContent();
        $this->assertSoftDeleted($product);
        $this->deleteJson('/api/products/'.$product->id)->assertNotFound();
    }

    public function test_lists_categories_in_name_order(): void
    {
        Category::factory()->create(['name' => 'Zebra']);
        $first = Category::factory()->create(['name' => 'Appliances']);
        $this->getJson('/api/categories')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonStructure(['data' => [['id', 'name', 'slug', 'description']]]);
    }

    public function test_searches_names_and_treats_wildcards_literally(): void
    {
        $match = Product::factory()->create(['name' => 'Laptop 100%']);
        Product::factory()->create(['name' => 'Desktop 1000']);
        $this->getJson('/api/products?search=laptop')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/products?search='.urlencode('100%'))->assertJsonCount(1, 'data');
    }

    public function test_combines_category_search_and_inclusive_price_filters(): void
    {
        $category = Category::factory()->create(['slug' => 'electronics']);
        $match = Product::factory()->for($category)->create(['name' => 'Laptop', 'price' => '100.00']);
        Product::factory()->for($category)->create(['name' => 'Laptop Pro', 'price' => '300.01']);
        Product::factory()->create(['name' => 'Laptop', 'price' => '100.00']);
        $this->getJson('/api/products?search=laptop&category=electronics&min_price=100&max_price=300')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/products?category=unknown')->assertOk()->assertJsonCount(0, 'data');
    }

    public static function sorts(): array
    {
        return [['name_asc', 'Alpha'], ['name_desc', 'Zulu'], ['price_asc', 'Zulu'], ['price_desc', 'Alpha']];
    }

    #[DataProvider('sorts')]
    public function test_sorts_products(string $sort, string $first): void
    {
        Product::factory()->create(['name' => 'Alpha', 'price' => '20.00']);
        Product::factory()->create(['name' => 'Zulu', 'price' => '5.00']);
        $this->getJson('/api/products?sort='.$sort)->assertOk()->assertJsonPath('data.0.name', $first);
    }

    public function test_pagination_is_stable_and_keeps_filters(): void
    {
        $products = Product::factory()->count(3)->create(['name' => 'Same']);
        $this->getJson('/api/products?search=Same&per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $products[1]->id)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 1);
    }

    public static function invalidProducts(): array
    {
        return [
            ['price', '-0.01'], ['price', '1.001'], ['price', '10000000000.00'], ['price', 'abc'],
            ['stock_quantity', -1], ['stock_quantity', 1.5], ['stock_quantity', 4294967296],
            ['category_id', 99999999], ['name', ''], ['sku', ''], ['is_active', 'yes'],
        ];
    }

    #[DataProvider('invalidProducts')]
    public function test_rejects_invalid_product_fields(string $field, mixed $value): void
    {
        $this->postJson('/api/products', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $product = Product::factory()->create();
        $this->patchJson('/api/products/'.$product->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_rejects_duplicate_sku_even_after_soft_delete(): void
    {
        $existing = Product::factory()->create();
        $product = Product::factory()->create();
        $this->patchJson('/api/products/'.$product->id, ['sku' => $existing->sku])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $existing->delete();
        $this->postJson('/api/products', $this->payload(['sku' => $existing->sku]))->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public static function invalidQueries(): array
    {
        return [['sort=invalid', 'sort'], ['min_price=-1', 'min_price'], ['min_price=20&max_price=10', 'max_price'],
            ['per_page=101', 'per_page'], ['per_page=0', 'per_page'], ['page=0', 'page'], ['search[]=x', 'search']];
    }

    #[DataProvider('invalidQueries')]
    public function test_validates_listing_parameters(string $query, string $field): void
    {
        $this->getJson('/api/products?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function restrictedEnvironments(): array
    {
        return [['production'], ['staging']];
    }

    #[DataProvider('restrictedEnvironments')]
    public function test_all_write_methods_are_forbidden_outside_local_testing(string $environment): void
    {
        $product = Product::factory()->create();
        $this->app->instance('env', $environment);
        $this->postJson('/api/products', [])->assertForbidden();
        $this->putJson('/api/products/'.$product->id, [])->assertForbidden();
        $this->patchJson('/api/products/'.$product->id, [])->assertForbidden();
        $this->deleteJson('/api/products/'.$product->id)->assertForbidden();
        $this->getJson('/api/products')->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_local_environment_allows_writes(): void
    {
        $payload = $this->payload();
        $this->app->instance('env', 'local');
        $this->postJson('/api/products', $payload)->assertCreated();
    }

    public function test_missing_products_cannot_be_modified(): void
    {
        $this->patchJson('/api/products/99999999', ['name' => 'Missing'])->assertNotFound();
        $this->deleteJson('/api/products/99999999')->assertNotFound();
    }

    public function test_api_errors_are_json_without_accept_header(): void
    {
        $this->get('/api/products?per_page=0')->assertUnprocessable()->assertHeader('Content-Type', 'application/json');
        $this->post('/api/products', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->get('/api/products/99999999')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    public function test_product_and_category_relationships(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $this->assertSame($category->id, $product->category->id);
        $this->assertSame($product->id, $category->products->sole()->id);
    }
}
