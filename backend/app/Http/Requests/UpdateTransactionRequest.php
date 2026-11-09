<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:1'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(['cash', 'bank', 'mobile'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'memo_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'paid_at.before_or_equal' => 'Date cannot be in the future.',
        ];
    }
}
