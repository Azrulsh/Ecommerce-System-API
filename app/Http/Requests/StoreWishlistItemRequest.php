<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWishlistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['product_id' => ['required', 'integer', 'min:1'], 'user_id' => ['prohibited']];
    }
}
