<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $this->asCustomer($user);
    }

    public function test_every_admin_endpoint_requires_authentication_and_admin_role_before_binding(): void
    {
        $routes = [['GET', '/categories'], ['POST', '/categories'], ['PATCH', '/categories/99999999'],
            ['DELETE', '/categories/99999999'], ['GET', '/discounts'], ['POST', '/discounts'],
            ['GET', '/discounts/99999999'], ['PUT', '/discounts/99999999'], ['DELETE', '/discounts/99999999'],
            ['POST', '/discounts/99999999/products'], ['DELETE', '/discounts/99999999/products/1'],
            ['GET', '/orders'], ['GET', '/orders/99999999'], ['PATCH', '/orders/99999999/status'], ['GET', '/sales-summary']];
        foreach ($routes as [$method, $path]) {
            $this->json($method, '/api/admin'.$path)->assertUnauthorized();
        }
        $this->asCustomer();
        foreach ($routes as [$method, $path]) {
            $this->json($method, '/api/admin'.$path)->assertForbidden();
        }
    }

    public function test_admin_category_crud_and_validation(): void
    {
        $this->admin();
        $id = $this->postJson('/api/admin/categories', ['name' => 'Outdoor', 'slug' => 'outdoor', 'description' => 'Outdoor supplies'])
            ->assertCreated()->json('data.id');
        $this->getJson('/api/admin/categories?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->patchJson("/api/admin/categories/$id", ['name' => 'Outdoor Living'])->assertOk()->assertJsonPath('data.name', 'Outdoor Living');
        $this->putJson("/api/admin/categories/$id", ['name' => 'Outdoor', 'slug' => 'outdoor'])->assertOk();
        $this->postJson('/api/admin/categories', ['name' => 'Duplicate', 'slug' => 'outdoor'])->assertUnprocessable();
        $this->postJson('/api/admin/categories', ['name' => '', 'slug' => 'BAD slug'])->assertUnprocessable();
        $this->deleteJson("/api/admin/categories/$id")->assertNoContent();
        $this->assertDatabaseMissing('categories', ['id' => $id]);
    }

    public function test_categories_with_existing_or_soft_deleted_products_cannot_be_deleted(): void
    {
        $this->admin();
        $product = Product::factory()->create();
        $this->deleteJson("/api/admin/categories/$product->category_id")->assertConflict();
        $product->delete();
        $this->deleteJson("/api/admin/categories/$product->category_id")->assertConflict();
    }

    public function test_discount_crud_preserves_assignments_and_delete_deactivates(): void
    {
        $this->admin();
        $products = Product::factory()->count(2)->create(['price' => '100.00']);
        $id = $this->postJson('/api/admin/discounts', ['name' => 'Sale', 'type' => 'percentage', 'value' => '10.00'])
            ->assertCreated()->assertJsonPath('data.value', '10.00')->json('data.id');
        $this->postJson("/api/admin/discounts/$id/products", ['product_ids' => $products->modelKeys()])->assertOk();
        $this->postJson("/api/admin/discounts/$id/products", ['product_ids' => [$products[0]->id]])->assertOk();
        $this->patchJson("/api/admin/discounts/$id", ['value' => '20.00'])->assertOk()->assertJsonCount(2, 'data.product_ids');
        $this->putJson("/api/admin/discounts/$id", ['name' => 'Voucher', 'type' => 'fixed', 'value' => '25.00'])->assertOk()->assertJsonCount(2, 'data.product_ids');
        $this->getJson('/api/admin/discounts?per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/discounts/$id")->assertOk();
        $this->deleteJson("/api/admin/discounts/$id/products/{$products[0]->id}")->assertNoContent();
        $this->deleteJson("/api/admin/discounts/$id/products/{$products[0]->id}")->assertNoContent();
        $this->deleteJson("/api/admin/discounts/$id")->assertNoContent();
        $this->assertFalse(Discount::findOrFail($id)->is_active);
        $this->assertSame([$products[1]->id], Discount::findOrFail($id)->products()->pluck('products.id')->all());
    }

    public function test_discount_validation_reuses_business_rules_and_partial_date_validation(): void
    {
        $this->admin();
        foreach ([['percentage', '100.01'], ['loyalty', '84.99'], ['fixed', '-1.00'], ['fixed', '1.001'], ['unknown', '2.00']] as [$type, $value]) {
            $this->postJson('/api/admin/discounts', ['name' => 'Invalid', 'type' => $type, 'value' => $value])->assertUnprocessable();
        }
        $id = $this->postJson('/api/admin/discounts', ['name' => 'VIP', 'type' => 'loyalty', 'value' => '85', 'requires_loyalty' => false,
            'starts_at' => '2026-01-01T00:00:00Z', 'expires_at' => '2027-01-01T00:00:00Z'])
            ->assertCreated()->assertJsonPath('data.requires_loyalty', true)->json('data.id');
        $this->patchJson("/api/admin/discounts/$id", ['expires_at' => '2025-01-01T00:00:00Z'])->assertUnprocessable();
        $product = Product::factory()->create(['price' => '10.00']);
        $fixed = Discount::factory()->create(['type' => 'fixed', 'value' => '20.00']);
        $this->postJson("/api/admin/discounts/$fixed->id/products", ['product_ids' => [$product->id]])->assertUnprocessable();
        $this->postJson("/api/admin/discounts/$id/products", ['product_ids' => [999999999]])->assertUnprocessable();
        $this->postJson("/api/admin/discounts/$id/products", ['product_ids' => [$product->id, $product->id]])->assertUnprocessable();
        $product->delete();
        $this->postJson("/api/admin/discounts/$id/products", ['product_ids' => [$product->id]])->assertUnprocessable();
    }

    public function test_admin_requests_are_rate_limited(): void
    {
        $this->admin();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/admin/categories')->assertOk();
        }
        $this->getJson('/api/admin/categories')->assertStatus(429);
    }
}
