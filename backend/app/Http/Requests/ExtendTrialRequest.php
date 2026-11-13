<?php

namespace App\Http\Requests;

class ExtendTrialRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['days' => ['required', 'integer', 'min:1', 'max:365']];
    }

    public function messages(): array
    {
        return [
            'days.required' => 'Enter how many days to add.',
            'days.integer' => 'Days must be a whole number.',
            'days.min' => 'Add at least 1 day.',
            'days.max' => 'You can add at most 365 days at a time.',
        ];
    }
}
