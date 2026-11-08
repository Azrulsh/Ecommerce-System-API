<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginationRequest;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    public function index(PaginationRequest $request, Product $product): AnonymousResourceCollection
    {
        abort_unless($product->is_active, 404);
        $average = $product->reviews()->avg('rating');

        return ReviewResource::collection($product->reviews()->with('user:id,name')->orderByDesc('id')
            ->paginate($request->validated('per_page', 15))->withQueryString())->additional([
                'summary' => ['average_rating' => $average === null ? null : number_format((float) $average, 2, '.', ''),
                    'review_count' => $product->reviews()->count()],
            ]);
    }

    public function store(StoreReviewRequest $request, Product $product): JsonResponse
    {
        $review = DB::transaction(function () use ($request, $product) {
            $locked = Product::where('is_active', true)->lockForUpdate()->findOrFail($product->id);
            abort_if($locked->reviews()->where('user_id', $request->user()->id)->exists(), 409, 'You have already reviewed this product.');
            $review = new Review($request->safe()->only(['rating', 'comment']));
            $review->user()->associate($request->user());
            $review->product()->associate($locked);
            $review->save();

            return $review;
        }, 3);

        return (new ReviewResource($review->load('user:id,name')))->response()->setStatusCode(201);
    }

    public function update(UpdateReviewRequest $request, Review $review): ReviewResource
    {
        abort_unless($review->product && ! $review->product->trashed() && $review->product->is_active, 404);
        $review->update($request->safe()->only(['rating', 'comment']));

        return new ReviewResource($review->refresh()->load('user:id,name'));
    }

    public function destroy(Review $review): Response
    {
        $review->delete();

        return response()->noContent();
    }
}
