<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class ReviewsAndWishlistTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    public function test_reviews_are_public_paginated_and_expose_only_safe_reviewer_fields(): void
    {
        $product = Product::factory()->create();
        foreach ([2, 5] as $rating) {
            $user = $this->asCustomer();
            $this->postJson("/api/products/$product->id/reviews", ['rating' => $rating, 'comment' => 'Useful product.'])
                ->assertCreated()->assertJsonPath('data.reviewer.id', $user->id);
        }
        $this->forgetAuthentication();
        $response = $this->getJson("/api/products/$product->id/reviews?per_page=1")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.average_rating', '3.50')->assertJsonPath('summary.review_count', 2);
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0.reviewer')));
        $product->update(['is_active' => false]);
        $this->getJson("/api/products/$product->id/reviews")->assertNotFound();
    }

    public function test_review_ownership_uniqueness_validation_and_admin_moderation(): void
    {
        $product = Product::factory()->create();
        $this->postJson("/api/products/$product->id/reviews", ['rating' => 4])->assertUnauthorized();
        $owner = $this->asCustomer();
        foreach ([0, 6, 2.5, 'bad'] as $rating) {
            $this->postJson("/api/products/$product->id/reviews", ['rating' => $rating])->assertUnprocessable();
        }
        $this->postJson("/api/products/$product->id/reviews", ['rating' => 4, 'user_id' => 1])->assertUnprocessable();
        $id = $this->postJson("/api/products/$product->id/reviews", ['rating' => 4])->assertCreated()->json('data.id');
        $this->postJson("/api/products/$product->id/reviews", ['rating' => 3])->assertConflict();
        $this->patchJson("/api/reviews/$id", ['rating' => 5, 'comment' => 'Updated'])->assertOk()->assertJsonPath('data.rating', 5);
        $this->asCustomer();
        $this->patchJson("/api/reviews/$id", ['rating' => 1])->assertNotFound();
        $this->deleteJson("/api/reviews/$id")->assertNotFound();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->asCustomer($admin);
        $this->patchJson("/api/reviews/$id", ['rating' => 1])->assertNotFound();
        $this->deleteJson("/api/reviews/$id")->assertNoContent();
        $this->assertDatabaseMissing('reviews', ['id' => $id]);
        $this->asCustomer($owner);
        $id = $this->postJson("/api/products/$product->id/reviews", ['rating' => 2])->assertCreated()->json('data.id');
        $this->deleteJson("/api/reviews/$id")->assertNoContent();
    }

    public function test_unavailable_products_cannot_receive_reviews(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create(['is_active' => false]);
        $this->postJson("/api/products/$product->id/reviews", ['rating' => 3])->assertNotFound();
        $product->delete();
        $this->postJson("/api/products/$product->id/reviews", ['rating' => 3])->assertNotFound();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_empty_reviews_have_null_average_and_oversized_comments_are_rejected(): void
    {
        $product = Product::factory()->create();
        $this->getJson("/api/products/$product->id/reviews")->assertOk()
            ->assertJsonPath('summary.average_rating', null)->assertJsonPath('summary.review_count', 0);
        $this->asCustomer();
        $this->postJson("/api/products/$product->id/reviews", ['rating' => 5, 'comment' => str_repeat('x', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors('comment');
    }

    public function test_wishlist_is_idempotent_isolated_paginated_and_retains_unavailable_items(): void
    {
        $product = Product::factory()->create();
        $this->getJson('/api/wishlist')->assertUnauthorized();
        $owner = $this->asCustomer();
        $this->postJson('/api/wishlist/items', ['product_id' => $product->id])->assertCreated();
        $this->postJson('/api/wishlist/items', ['product_id' => $product->id])->assertOk();
        $this->assertDatabaseCount('wishlist_items', 1);
        $this->asCustomer();
        $this->getJson('/api/wishlist')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/wishlist/items/$product->id")->assertNotFound();
        $product->update(['is_active' => false]);
        $this->postJson('/api/wishlist/items', ['product_id' => $product->id])->assertNotFound();
        $this->asCustomer($owner);
        $this->getJson('/api/wishlist?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_available', false);
        $product->delete();
        $this->getJson('/api/wishlist')->assertOk()->assertJsonPath('data.0.is_available', false);
        $this->deleteJson("/api/wishlist/items/$product->id")->assertNoContent();
        $this->assertDatabaseCount('wishlist_items', 0);
    }

    public function test_wishlist_rejects_forged_ownership_and_invalid_pagination(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create();
        $this->postJson('/api/wishlist/items', ['product_id' => $product->id, 'user_id' => 99])->assertUnprocessable();
        $this->postJson('/api/wishlist/items', ['product_id' => 0])->assertUnprocessable();
        $this->postJson('/api/wishlist/items', ['product_id' => 999999999])->assertNotFound();
        $this->getJson('/api/wishlist?per_page=101')->assertUnprocessable();
        $this->getJson("/api/products/$product->id/reviews?page=0")->assertUnprocessable();
    }

    public function test_review_and_wishlist_mutations_are_rate_limited(): void
    {
        $this->asCustomer();
        $product = Product::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/products/$product->id/reviews", ['rating' => 0])->assertUnprocessable();
        }
        $this->postJson("/api/products/$product->id/reviews", ['rating' => 5])->assertStatus(429);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/wishlist/items', ['product_id' => 0])->assertUnprocessable();
        }
        $this->postJson('/api/wishlist/items', ['product_id' => $product->id])->assertStatus(429);
    }
}
