<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('categories', 'slug')],
            'description' => ['nullable', 'string', 'max:10000']];
    }
}
