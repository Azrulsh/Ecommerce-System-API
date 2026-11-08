<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::orderBy('name')->orderBy('id')->paginate($request->validated('per_page', 15))->withQueryString());
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        return new CategoryResource(Category::create($request->validated()));
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->validated());

        return new CategoryResource($category->refresh());
    }

    public function destroy(Category $category): Response
    {
        DB::transaction(function () use ($category) {
            $locked = Category::lockForUpdate()->findOrFail($category->id);
            abort_if($locked->products()->withTrashed()->exists(), 409, 'Categories containing products cannot be deleted.');
            $locked->delete();
        }, 3);

        return response()->noContent();
    }
}
