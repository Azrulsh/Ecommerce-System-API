<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeRequest;
use App\Http\Resources\SalesSummaryResource;
use App\Services\SalesSummaryService;
use Illuminate\Support\Facades\DB;

class SalesSummaryController extends Controller
{
    public function __invoke(DateRangeRequest $request, SalesSummaryService $reports): SalesSummaryResource
    {
        return new SalesSummaryResource(DB::transaction(fn () => $reports->summarize($request->validated())));
    }
}
