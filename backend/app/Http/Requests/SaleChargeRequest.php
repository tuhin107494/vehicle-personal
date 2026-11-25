<?php

namespace App\Http\Requests;

/** A charge added to a sale (a late fee, papers...), new or edited. */
class SaleChargeRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1'],
            'charged_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'purpose.required' => 'Enter what the charge is for.',
            'amount.required' => 'Enter the amount.',
            'amount.min' => 'Amount must be greater than zero.',
            'charged_on.before_or_equal' => 'Date cannot be in the future.',
        ];
    }
}
