<?php

namespace App\Http\Requests\Gateway;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** The super admin's configure form: general fields, and the credential fields of either mode (checked against the gateway's own schema in the service). */
class UpdateGatewayConfigRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'general' => ['nullable', 'array'],
            'general.display_name' => ['sometimes', 'required', 'string', 'max:100'],
            'general.description' => ['nullable', 'string', 'max:1000'],
            'general.is_active' => ['sometimes', 'boolean'],
            'general.sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'general.active_mode' => ['sometimes', Rule::in(['sandbox', 'live'])],
            'general.min_amount_paisa' => ['nullable', 'integer', 'min:0'],
            'general.max_amount_paisa' => ['nullable', 'integer', 'min:0', 'gte:general.min_amount_paisa'],
            'general.fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'general.fee_fixed_paisa' => ['nullable', 'integer', 'min:0'],
            'general.webhook_ips' => ['nullable', 'string', 'max:500', 'regex:/^[0-9a-fA-F:.,\s]*$/'],
            'sandbox' => ['nullable', 'array'],
            'sandbox.*' => ['nullable', 'string', 'max:10000'],
            'live' => ['nullable', 'array'],
            'live.*' => ['nullable', 'string', 'max:10000'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'general.max_amount_paisa.gte' => 'The maximum amount cannot be below the minimum.',
            'general.webhook_ips.regex' => 'List IP addresses separated by commas.',
        ];
    }
}
