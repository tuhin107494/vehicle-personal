<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PermissionRequest extends ApiRequest
{
    public function rules(): array
    {
        $permission = $this->route('permission');

        return [
            'key' => $permission ? ['prohibited'] : ['required', 'regex:/^[a-z_]+\.[a-z_]+$/', Rule::unique('permissions', 'key')],
            'name' => ['required', 'string', 'max:255'],
            'group' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'Use the format group.action, for example reports.export.',
            'key.unique' => 'A permission with this key already exists.',
            'key.prohibited' => 'The key cannot be changed after the permission is created.',
            'name.required' => 'Permission name is required.',
            'group.required' => 'Group is required.',
        ];
    }
}
