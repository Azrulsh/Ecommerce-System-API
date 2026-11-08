<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['sku'] = ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($this->route('product'))];

        if ($this->isMethod('patch')) {
            foreach ($rules as &$fieldRules) {
                if (in_array('required', $fieldRules, true)) {
                    array_unshift($fieldRules, 'sometimes');
                }
            }
        }

        return $rules;
    }
}
