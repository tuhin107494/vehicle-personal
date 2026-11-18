<?php

namespace App\Http\Requests\Gateway;

use App\Http\Requests\ApiRequest;
use App\Services\Gateway\GatewayRegistry;
use Illuminate\Validation\Rule;

/** The super admin adding a gateway the code supports: its name and the keys for either mode (checked against the gateway's schema in the service). */
class StoreGatewayConfigRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', Rule::in(GatewayRegistry::codes()), Rule::unique('gateway_configs', 'code')],
            'general' => ['required', 'array'],
            'general.display_name' => ['required', 'string', 'max:100'],
            'general.description' => ['nullable', 'string', 'max:1000'],
            'sandbox' => ['nullable', 'array'],
            'sandbox.*' => ['nullable', 'string', 'max:10000'],
            'live' => ['nullable', 'array'],
            'live.*' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.in' => 'Choose one of the gateways the app supports.',
            'code.unique' => 'This gateway was already added. Open Configure to change it.',
            'general.display_name.required' => 'Enter the name shops will see at checkout.',
        ];
    }
}
