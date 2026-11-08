<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexProductRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(IndexProductRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Product::query()->with('category')->where('is_active', true);

        if (isset($filters['search'])) {
            // Treat LIKE wildcards as literal characters supplied by the customer.
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']);
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }
        if (isset($filters['category'])) {
            $query->whereHas('category', fn ($category) => $category->where('slug', $filters['category']));
        }
        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }
        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        [$column, $direction] = explode('_', $filters['sort'] ?? 'name_asc');
        $query->orderBy($column, $direction)->orderBy('id');

        return ProductResource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function show(Product $product): ProductResource
    {
        abort_unless($product->is_active, 404);

        return new ProductResource($product->load('category'));
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $product = Product::create($request->validated());

        return new ProductResource($product->refresh()->load('category'));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->refresh()->load('category'));
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }
}
