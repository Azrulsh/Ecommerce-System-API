<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceController;
use App\Http\Middleware\OptionalSanctumAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('categories', [CategoryController::class, 'index']);
Route::middleware(OptionalSanctumAuthentication::class)->group(function () {
    Route::get('products/{product}/price', [ProductPriceController::class, 'show']);
    Route::get('products/{product}/discounts', [ProductPriceController::class, 'discounts']);
    Route::post('discounts/calculate', [ProductPriceController::class, 'calculate']);
});
Route::apiResource('products', ProductController::class)->only(['index', 'show']);
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:registration');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
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
