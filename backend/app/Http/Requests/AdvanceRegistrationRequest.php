<?php

namespace App\Http\Requests;

use App\Models\Sale;
use Illuminate\Validation\Rule;

class AdvanceRegistrationRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Sale::REGISTRATION_STEPS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Select the registration step.',
        ];
    }
}
