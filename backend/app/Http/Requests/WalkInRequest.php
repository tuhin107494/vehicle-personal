<?php

namespace App\Http\Requests;

class WalkInRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Mobile number is required.',
            'phone.regex' => 'Enter a valid mobile number (7 to 20 digits).',
        ];
    }
}
