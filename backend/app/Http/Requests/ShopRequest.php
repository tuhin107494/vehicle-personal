<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ShopRequest extends ApiRequest
{
    public function rules(): array
    {
        $shop = $this->route('shop');
        $ownerId = $shop?->owner?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'owner_name' => [$shop ? 'sometimes' : 'required', 'string', 'max:255'],
            'owner_email' => [$shop ? 'sometimes' : 'required', 'email', Rule::unique('users', 'email')->ignore($ownerId)],
            'owner_password' => [$shop ? 'nullable' : 'required', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Shop name is required.',
            'owner_name.required' => 'Owner name is required.',
            'owner_email.required' => 'Owner email is required.',
            'owner_email.unique' => 'This email is already used by another user.',
            'owner_password.required' => 'Set a password for the owner (at least 8 characters).',
            'owner_password.min' => 'Password must be at least 8 characters.',
        ];
    }
}
