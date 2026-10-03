<?php

namespace App\Http\Requests;

class CancelSaleRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to cancel a sale.',
        ];
    }
}
