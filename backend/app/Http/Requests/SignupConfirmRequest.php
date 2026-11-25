<?php

namespace App\Http\Requests;

/** The parts of a sign-up confirmation link. */
class SignupConfirmRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
            'expires' => ['required', 'integer'],
            'token' => ['required', 'string'],
        ];
    }
}
