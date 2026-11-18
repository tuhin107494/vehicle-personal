<?php

namespace App\Http\Requests\Gateway;

use App\Http\Requests\ApiRequest;

/** A refund the super admin asks for. `offline` records money returned outside the gateway (a bank transfer back) without calling it. */
class RefundRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'amount_paisa' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'offline' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Say why the money is refunded; it goes to the gateway and the audit log.'];
    }
}
