<?php

namespace App\Http\Requests;

class ShopProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Shop name is required.'];
    }
}
