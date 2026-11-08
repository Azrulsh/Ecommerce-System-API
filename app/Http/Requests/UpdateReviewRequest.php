<?php

namespace App\Http\Requests;

class UpdateReviewRequest extends StoreReviewRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['rating' => ['sometimes', 'required', 'integer', 'between:1,5']]);
    }
}
