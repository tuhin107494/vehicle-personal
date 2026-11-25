<?php

namespace App\Http\Requests;

/** Only an email: asking for a password reset link, or for a new sign-up confirmation link. */
class EmailRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
