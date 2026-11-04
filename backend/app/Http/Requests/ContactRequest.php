<?php

namespace App\Http\Requests;

use App\Rules\ReceivesMail;

class ContactRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            // the name goes into the email's subject, so a line break must never get through
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'email:rfc', 'max:255', app(ReceivesMail::class)],
            'message' => ['required', 'string', 'min:10', 'max:1000'],
            'leave_blank' => ['nullable', 'string', 'max:255'], // a field people never see: see LandingController::contact()
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Your name is required.',
            'name.not_regex' => 'Enter your name on one line.',
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'message.required' => 'Message is required.',
            'message.min' => 'Write at least 10 characters.',
            'message.max' => 'Keep your message under 1000 characters.',
        ];
    }
}
