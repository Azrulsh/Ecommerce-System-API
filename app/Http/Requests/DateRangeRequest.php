<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    public function rules(): array
    {
        return ['from_date' => ['sometimes', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'date_format:Y-m-d', ...($this->has('from_date') ? ['after_or_equal:from_date'] : [])]];
    }
}
