<?php

namespace App\Http\Requests;

/** Signing in with Google: the code Google sent back. */
class GoogleCodeRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string']];
    }
}
