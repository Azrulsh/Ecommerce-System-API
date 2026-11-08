<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['product_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'price' => ['prohibited'], 'discount' => ['prohibited'], 'user_id' => ['prohibited'], 'cart_id' => ['prohibited']];
    }
}
