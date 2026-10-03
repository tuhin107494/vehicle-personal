<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StorePurchasePaymentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::in(['cash', 'bank', 'mobile', 'advance'])],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount paid.',
            'amount.min' => 'Amount must be greater than zero.',
            'method.required' => 'Choose how it was paid.',
            'paid_at.before_or_equal' => 'Payment date cannot be in the future.',
        ];
    }
}
