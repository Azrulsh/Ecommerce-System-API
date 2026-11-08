<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route policy runs before validation and hides other customers' items.
    }

    public function rules(): array
    {
        return ['quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'price' => ['prohibited'], 'discount' => ['prohibited'], 'user_id' => ['prohibited'], 'cart_id' => ['prohibited'], 'product_id' => ['prohibited']];
    }
}
