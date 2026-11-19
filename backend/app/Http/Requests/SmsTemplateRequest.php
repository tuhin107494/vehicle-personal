<?php

namespace App\Http\Requests;

class SmsTemplateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'body_bn' => ['required', 'string', 'max:480'],
            'body_en' => ['required', 'string', 'max:480'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'body_bn.required' => 'Enter the Bangla text.',
            'body_en.required' => 'Enter the English text.',
            'body_bn.max' => 'Keep the text to 480 characters or less.',
            'body_en.max' => 'Keep the text to 480 characters or less.',
        ];
    }
}
