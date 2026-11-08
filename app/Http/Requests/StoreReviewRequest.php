<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:5000'],
            'user_id' => ['prohibited'], 'product_id' => ['prohibited']];
    }
}
