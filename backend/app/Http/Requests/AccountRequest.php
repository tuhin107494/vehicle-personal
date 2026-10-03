<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** One form for money going out to a supplier (payable) or coming in from a customer (receivable). */
class AccountRequest extends ApiRequest
{
    public function rules(): array
    {
        $rules = [
            'side' => ['required', Rule::in(['supplier', 'customer'])],
            'party_id' => ['required', 'integer', $this->shopExists('parties')],
        ];

        if ($this->isMethod('post')) {
            $rules += [
                'sale_id' => ['nullable', 'integer', $this->shopExists('sales')],
                'amount' => ['required', 'numeric', 'min:1'],
                'method' => ['required', Rule::in(['cash', 'bank', 'mobile'])],
                'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
                'reference' => ['nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'side.required' => 'Choose supplier or customer.',
            'party_id.required' => 'Select the supplier or customer.',
            'amount.required' => 'Enter the amount.',
            'amount.min' => 'Amount must be greater than zero.',
            'method.required' => 'Choose how it was paid.',
            'paid_at.before_or_equal' => 'Date cannot be in the future.',
        ];
    }
}
