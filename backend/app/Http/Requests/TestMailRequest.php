<?php

namespace App\Http\Requests;

/** Where to send the SMTP test email. */
class TestMailRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['to' => ['required', 'email']];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'Email is required.',
            'to.email' => 'Enter a valid email address.',
        ];
    }
}
