<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** A shop choosing a plan: subscribe, upgrade or downgrade, with an optional coupon code. */
class ChangePlanRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return ['plan_id.exists' => 'This plan is not available.'];
    }
}
