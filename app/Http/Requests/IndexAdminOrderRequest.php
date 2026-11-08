<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Validation\Rule;

class IndexAdminOrderRequest extends DateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', Rule::enum(OrderStatus::class)]]);
    }
}
