<?php

namespace App\Http\Requests\Gateway;

use App\Http\Requests\ApiRequest;

class ResolveMismatchRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['note.required' => 'Say what was found and done about it.'];
    }
}
