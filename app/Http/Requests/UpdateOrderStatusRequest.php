<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['simulation' => $this->header('X-Mock-Refund')]);
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(OrderStatus::class)],
            'simulation' => app()->environment(['local', 'testing']) && $this->input('status') === 'cancelled'
                ? ['nullable', Rule::in(['success', 'fail'])] : ['prohibited'],
            'subtotal' => ['prohibited'], 'discount_total' => ['prohibited'], 'grand_total' => ['prohibited'],
            'stock_restored_at' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
