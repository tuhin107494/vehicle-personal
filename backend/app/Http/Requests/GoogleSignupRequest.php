<?php

namespace App\Http\Requests;

/** A new shop signing up with Google: the shop's details and the code Google sent back. */
class GoogleSignupRequest extends ApiRequest
{
    public function rules(): array
    {
        return SignupRequest::SHOP_RULES + ['code' => ['required', 'string']];
    }

    public function messages(): array
    {
        return SignupRequest::MESSAGES;
    }
}
