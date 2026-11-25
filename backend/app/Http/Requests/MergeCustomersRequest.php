<?php

namespace App\Http\Requests;

/** The duplicate customers to merge into the one in the URL. */
class MergeCustomersRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'duplicate_ids' => ['required', 'array', 'min:1', 'max:20'],
            'duplicate_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['duplicate_ids.required' => 'Choose at least one duplicate customer to merge.'];
    }
}
