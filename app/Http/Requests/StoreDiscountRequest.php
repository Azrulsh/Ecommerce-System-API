<?php

namespace App\Http\Requests;

use App\Services\Discount\DiscountAdministrationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    public function rules(): array
    {
        return app(DiscountAdministrationService::class)->validationRules($this->all());
    }
}
