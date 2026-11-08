<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalculateProductPriceRequest;
use App\Http\Resources\DiscountResource;
use App\Http\Resources\ProductPriceResource;
use App\Models\Product;
use App\Services\Discount\ProductPricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductPriceController extends Controller
{
    public function show(Request $request, Product $product, ProductPricingService $pricing): ProductPriceResource
    {
        return new ProductPriceResource($pricing->price($product, $request->user()));
    }

    public function discounts(Request $request, Product $product, ProductPricingService $pricing): AnonymousResourceCollection
    {
        return DiscountResource::collection($pricing->eligibleDiscounts($product, $request->user()));
    }

    public function calculate(CalculateProductPriceRequest $request, ProductPricingService $pricing): ProductPriceResource
    {
        $product = Product::query()->findOrFail($request->validated('product_id'));

        return new ProductPriceResource($pricing->price($product, $request->user()));
    }
}
