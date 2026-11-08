<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateProductPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'is_loyalty_member' => ['prohibited'],
            'requires_loyalty' => ['prohibited'],
            'loyalty' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
