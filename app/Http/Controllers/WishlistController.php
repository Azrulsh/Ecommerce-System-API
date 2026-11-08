<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginationRequest;
use App\Http\Requests\StoreWishlistItemRequest;
use App\Http\Resources\WishlistItemResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return WishlistItemResource::collection($request->user()->wishlistItems()->with('product.category')->orderByDesc('id')
            ->paginate($request->validated('per_page', 15))->withQueryString());
    }

    public function store(StoreWishlistItemRequest $request): JsonResponse
    {
        $item = DB::transaction(function () use ($request) {
            $product = Product::where('is_active', true)->lockForUpdate()->findOrFail($request->validated('product_id'));

            return $request->user()->wishlistItems()->firstOrCreate(['product_id' => $product->id]);
        }, 3);

        return (new WishlistItemResource($item->load('product.category')))->response()->setStatusCode($item->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $productId): Response
    {
        $request->user()->wishlistItems()->where('product_id', $productId)->firstOrFail()->delete();

        return response()->noContent();
    }
}
