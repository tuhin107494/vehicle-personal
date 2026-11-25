<?php

namespace App\Http\Requests;

class ResetPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
