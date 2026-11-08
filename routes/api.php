<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceController;
use App\Http\Middleware\EnsureCatalogWritesAllowed;
use Illuminate\Support\Facades\Route;

Route::get('categories', [CategoryController::class, 'index']);
Route::get('products/{product}/price', [ProductPriceController::class, 'show']);
Route::get('products/{product}/discounts', [ProductPriceController::class, 'discounts']);
Route::post('discounts/calculate', [ProductPriceController::class, 'calculate']);
Route::apiResource('products', ProductController::class)->only(['index', 'show']);
Route::middleware(EnsureCatalogWritesAllowed::class)->group(function () {
    Route::apiResource('products', ProductController::class)->only(['store', 'update', 'destroy']);
});
