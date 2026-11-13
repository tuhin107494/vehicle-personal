<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;

/** The super admin giving a shop time without a payment. The plan is needed only for a shop that has no subscription yet. */
class ExtendSubscriptionRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'ends_at' => ['required', 'date', 'after:today'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['ends_at.after' => 'The new end date must be in the future.'];
    }
}
