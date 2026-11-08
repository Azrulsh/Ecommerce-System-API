<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key'), 'simulation' => $this->header('X-Mock-Payment')]);
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
            'simulation' => app()->environment(['local', 'testing']) ? ['nullable', Rule::in(['success', 'fail'])] : ['prohibited'],
            'price' => ['prohibited'], 'subtotal' => ['prohibited'], 'discount_total' => ['prohibited'],
            'grand_total' => ['prohibited'], 'payment_success' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
