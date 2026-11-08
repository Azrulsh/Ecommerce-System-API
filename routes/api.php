<?php

use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\DiscountController as AdminDiscountController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\SalesSummaryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\WishlistController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\OptionalSanctumAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('categories', [CategoryController::class, 'index']);
Route::get('products/{product}/reviews', [ReviewController::class, 'index']);
Route::middleware(OptionalSanctumAuthentication::class)->group(function () {
    Route::get('products/{product}/price', [ProductPriceController::class, 'show']);
    Route::get('products/{product}/discounts', [ProductPriceController::class, 'discounts']);
    Route::post('discounts/calculate', [ProductPriceController::class, 'calculate']);
});
Route::apiResource('products', ProductController::class)->only(['index', 'show']);
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:registration');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('products/{product}/reviews', [ReviewController::class, 'store'])->middleware('throttle:reviews');
    Route::patch('reviews/{review}', [ReviewController::class, 'update'])->middleware(['can:update,review', 'throttle:reviews']);
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->middleware(['can:delete,review', 'throttle:reviews']);
    Route::get('wishlist', [WishlistController::class, 'index']);
    Route::post('wishlist/items', [WishlistController::class, 'store'])->middleware('throttle:wishlist');
    Route::delete('wishlist/items/{productId}', [WishlistController::class, 'destroy'])->whereNumber('productId')->middleware('throttle:wishlist');
    Route::post('checkout', [CheckoutController::class, 'store']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('can:view,order');
    Route::get('orders/{order}/payment', [OrderController::class, 'payment'])->middleware('can:view,order');
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'user']);
    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'store']);
    Route::patch('cart/items/{item}', [CartController::class, 'update'])->middleware('can:update,item');
    Route::delete('cart/items/{item}', [CartController::class, 'destroy'])->middleware('can:delete,item');
    Route::post('products', [ProductController::class, 'store'])->middleware('can:create,App\Models\Product');
    Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update'])->middleware('can:update,product');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('can:delete,product');
});

Route::prefix('admin')->middleware(['auth:sanctum', EnsureAdmin::class, 'throttle:admin'])->group(function () {
    Route::apiResource('categories', AdminCategoryController::class)->except('show')->names('admin.categories');
    Route::apiResource('discounts', AdminDiscountController::class)->names('admin.discounts');
    Route::post('discounts/{discount}/products', [AdminDiscountController::class, 'assign']);
    Route::delete('discounts/{discount}/products/{productId}', [AdminDiscountController::class, 'remove'])->whereNumber('productId');
    Route::get('orders', [AdminOrderController::class, 'index']);
    Route::get('orders/{order}', [AdminOrderController::class, 'show']);
    Route::patch('orders/{order}/status', [AdminOrderController::class, 'update']);
    Route::get('sales-summary', SalesSummaryController::class);
});
