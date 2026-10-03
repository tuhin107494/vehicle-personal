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
            // the person: a customer when collecting, a supplier when paying
            'customer_id' => ['required_if:side,customer', 'nullable', 'integer', $this->shopExists('customers')],
            'supplier_id' => ['required_if:side,supplier', 'nullable', 'integer', $this->shopExists('suppliers')],
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
            'customer_id.required_if' => 'Select the customer.',
            'supplier_id.required_if' => 'Select the supplier.',
            'amount.required' => 'Enter the amount.',
            'amount.min' => 'Amount must be greater than zero.',
            'method.required' => 'Choose how it was paid.',
            'paid_at.before_or_equal' => 'Date cannot be in the future.',
        ];
    }
}
