<?php

namespace App\Http\Requests;

class UpdateDiscountRequest extends StoreDiscountRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
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
