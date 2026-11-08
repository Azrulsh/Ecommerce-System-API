<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignDiscountProductsRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\StoreDiscountRequest;
use App\Http\Requests\UpdateDiscountRequest;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use App\Services\Discount\DiscountAdministrationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DiscountController extends Controller
{
    public function __construct(private readonly DiscountAdministrationService $discounts) {}

    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return DiscountResource::collection(Discount::with(['products' => fn ($products) => $products->withTrashed()])
            ->orderBy('id')->paginate($request->validated('per_page', 15))->withQueryString());
    }

    public function show(Discount $discount): DiscountResource
    {
        return new DiscountResource($discount->load(['products' => fn ($products) => $products->withTrashed()]));
    }

    public function store(StoreDiscountRequest $request): DiscountResource
    {
        return new DiscountResource($this->discounts->create($request->validated()));
    }

    public function update(UpdateDiscountRequest $request, Discount $discount): DiscountResource
    {
        return $this->show($this->discounts->update($discount, $request->validated()));
    }

    public function destroy(Discount $discount): Response
    {
        $this->discounts->deactivate($discount);

        return response()->noContent();
    }

    public function assign(AssignDiscountProductsRequest $request, Discount $discount): DiscountResource
    {
        $this->discounts->assignProducts($discount, $request->validated('product_ids'));

        return $this->show($discount);
    }

    public function remove(Discount $discount, int $productId): Response
    {
        $this->discounts->removeProducts($discount, [$productId]);

        return response()->noContent();
    }
}
