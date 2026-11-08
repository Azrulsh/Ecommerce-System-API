<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class ProductAuthorizationTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    public function test_admin_can_create_update_and_delete_products_even_in_production(): void
    {
        $this->asCustomer(User::factory()->create(['is_admin' => true]));
        $this->app->instance('env', 'production');
        $category = Category::factory()->create();
        $created = $this->postJson('/api/products', ['category_id' => $category->id, 'sku' => 'ADMIN-TEST',
            'name' => 'Admin Product', 'price' => '99.90', 'stock_quantity' => 10])->assertCreated();
        $id = $created->json('data.id');
        $this->patchJson('/api/products/'.$id, ['price' => '89.90'])->assertOk()->assertJsonPath('data.price', '89.90');
        $this->deleteJson('/api/products/'.$id)->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $id]);
    }

    public function test_regular_customer_cannot_write_and_public_reads_remain_available(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->putJson('/api/products/'.$product->id, [])->assertForbidden();
        $this->patchJson('/api/products/'.$product->id, [])->assertForbidden();
        $this->deleteJson('/api/products/'.$product->id)->assertForbidden();
        $this->forgetAuthentication();
        $this->get('/api/products')->assertOk();
        $this->get('/api/products/'.$product->id)->assertOk();
    }

    public function test_guests_get_401_on_all_protected_routes_without_accept_header(): void
    {
        $product = Product::factory()->create();
        $this->get('/api/user')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->get('/api/cart')->assertUnauthorized();
        $this->post('/api/logout')->assertUnauthorized();
        $this->post('/api/cart/items', [])->assertUnauthorized();
        $this->patch('/api/cart/items/99999999', [])->assertUnauthorized();
        $this->delete('/api/cart/items/99999999')->assertUnauthorized();
        $this->post('/api/products', [])->assertUnauthorized();
        $this->put('/api/products/'.$product->id, [])->assertUnauthorized();
        $this->patch('/api/products/'.$product->id, [])->assertUnauthorized();
        $this->delete('/api/products/'.$product->id)->assertUnauthorized();
    }
}
