<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Validation\Rule;

class ShopUserRequest extends ApiRequest
{
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::in(PermissionCatalog::ASSIGNABLE_ROLES)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'email.required' => 'Email is required.',
            'email.unique' => 'This email is already used by another user.',
            'password.required' => 'Set a password (at least 8 characters).',
            'password.min' => 'Password must be at least 8 characters.',
            'role.in' => 'Role must be Admin or Moderator.',
        ];
    }
}
