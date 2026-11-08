<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignDiscountProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    public function rules(): array
    {
        return ['product_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct']];
    }
}
