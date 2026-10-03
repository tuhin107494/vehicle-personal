<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class DraftRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => [$this->route('draft') ? 'prohibited' : 'required', Rule::in(['purchase', 'sale'])],
            'label' => ['nullable', 'string', 'max:255'],
            'data' => ['required', 'array'],
        ];
    }

    /** A draft is the form as typed, so it is deliberately not checked field by field, only kept to a sane size. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if (strlen((string) json_encode($this->input('data'))) > 100000) {
                $v->errors()->add('data', 'This draft is too large to save.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Say whether this is a purchase or a sale draft.',
            'data.required' => 'There is nothing to save.',
        ];
    }
}
