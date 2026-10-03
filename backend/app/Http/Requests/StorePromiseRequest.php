<?php

namespace App\Http\Requests;

class StorePromiseRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'promised_date' => ['required', 'date', 'after_or_equal:today'],
            'promised_amount' => ['nullable', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'promised_date.required' => 'Promise date is required.',
            'promised_date.after_or_equal' => 'Promise date cannot be in the past.',
        ];
    }
}
