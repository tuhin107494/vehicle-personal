<?php

namespace App\Http\Requests;

/** A new shop signing up with an email and password. */
class SignupRequest extends ApiRequest
{
    /** The shop's details, asked on every way of signing up. */
    public const SHOP_RULES = [
        'shop_name' => ['required', 'string', 'max:255'],
        'phone' => ['required', 'string', 'max:30'],
    ];

    public const MESSAGES = [
        'name.required' => 'Your name is required.',
        'shop_name.required' => 'Shop name is required.',
        'phone.required' => 'Phone is required.',
        'email.required' => 'Email is required.',
        'email.email' => 'Enter a valid email address.',
        'password.required' => 'Password is required.',
        'password.min' => 'Password must be at least 8 characters.',
        'password.confirmed' => 'The two passwords do not match.',
    ];

    public function rules(): array
    {
        return self::SHOP_RULES + [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return self::MESSAGES;
    }
}
