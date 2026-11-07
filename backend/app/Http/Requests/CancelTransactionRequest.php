<?php

namespace App\Http\Requests;

class CancelTransactionRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Enter the reason for cancelling.',
        ];
    }
}
