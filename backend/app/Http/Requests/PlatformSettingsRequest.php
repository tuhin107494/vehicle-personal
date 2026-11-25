<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** The platform's settings the super admin saves: sign-up, Google sign-in, SMTP and the landing page contacts. */
class PlatformSettingsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'signup' => ['sometimes', 'array'],
            'signup.enabled' => ['sometimes', 'boolean'],
            'google' => ['sometimes', 'array'],
            'google.enabled' => ['sometimes', 'boolean'],
            'google.client_id' => ['nullable', 'string', 'max:255', 'required_if_accepted:google.enabled'],
            'google.client_secret' => ['nullable', 'string', 'max:255'],
            'smtp' => ['sometimes', 'array'],
            'smtp.enabled' => ['sometimes', 'boolean'],
            'smtp.host' => ['nullable', 'string', 'max:255', 'required_if_accepted:smtp.enabled'],
            'smtp.port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp.encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'smtp.username' => ['nullable', 'string', 'max:255'],
            'smtp.password' => ['nullable', 'string', 'max:255'],
            'smtp.from_address' => ['nullable', 'email', 'max:255', 'required_if_accepted:smtp.enabled'],
            'smtp.from_name' => ['nullable', 'string', 'max:255'],
            'landing' => ['sometimes', 'array'],
            'landing.support_email' => ['nullable', 'email', 'max:255'],
            'landing.support_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{3,20}$/'],
            'landing.whatsapp' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'landing.address' => ['nullable', 'string', 'max:255'],
            'landing.support_hours' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'google.client_id.required_if_accepted' => 'Client ID is required to turn on Google sign-in.',
            'smtp.host.required_if_accepted' => 'SMTP host is required to turn on SMTP.',
            'smtp.from_address.required_if_accepted' => 'From address is required to turn on SMTP.',
            'smtp.from_address.email' => 'Enter a valid email address.',
            'landing.support_email.email' => 'Enter a valid email address.',
            'landing.support_phone.regex' => 'Enter a valid phone number.',
            'landing.whatsapp.regex' => 'Enter the number with its country code and no spaces, for example +8801712345678.',
        ];
    }
}
