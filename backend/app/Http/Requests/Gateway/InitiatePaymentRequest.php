<?php

namespace App\Http\Requests\Gateway;

use App\Http\Requests\ApiRequest;
use App\Services\Gateway\GatewayRegistry;
use Illuminate\Validation\Rule;

/** A shop pressing Pay: the gateway and a key the browser made for this one click, so a repeat returns the same attempt. */
class InitiatePaymentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'gateway' => ['required', 'string', Rule::in(GatewayRegistry::codes())],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function messages(): array
    {
        return ['gateway.in' => 'Choose one of the payment methods offered.', 'idempotency_key.regex' => 'The payment key may only hold letters, digits, dashes and underscores.'];
    }
}
