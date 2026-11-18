<?php

namespace App\Http\Requests\Gateway;

use App\Http\Requests\ApiRequest;

class RejectAttemptRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Say why the transfer is rejected; the shop will see it.'];
    }
}
