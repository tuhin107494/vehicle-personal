<?php

namespace App\Http\Requests;

use App\Services\Sms\SmsService;
use Closure;

class SmsTestRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! SmsService::normalize($value)) {
                    $fail('Enter a Bangladeshi mobile number, for example 01712345678.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return ['phone.required' => 'Enter the mobile number to send the test to.'];
    }
}
