<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

class CancelSubscriptionRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['immediately' => ['nullable', 'boolean']];
    }
}
