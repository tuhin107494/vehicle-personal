<?php

namespace App\Http\Requests;

class RolePermissionsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ];
    }
}
