<?php

namespace App\Http\Requests;

class SmsSwitchRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['sms_enabled' => ['required', 'boolean']];
    }
}
