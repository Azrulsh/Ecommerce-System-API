<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['slug'][4] = Rule::unique('categories', 'slug')->ignore($this->route('category'));
        if ($this->isMethod('patch')) {
            foreach (['name', 'slug'] as $field) {
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return $rules;
    }
}
