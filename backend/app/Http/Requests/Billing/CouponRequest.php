<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** A discount code: percent (whole percent, at most 100) or fixed (paisa). */
class CouponRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($this->route('coupon'))],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1', Rule::when($this->input('type') === 'percent', ['max:100'], ['max:100000000000'])],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => 'The code may only hold letters, digits, dashes and underscores.', 'code.unique' => 'Another coupon already uses this code.', 'value.max' => 'A percent coupon cannot take off more than 100%.'];
    }
}
