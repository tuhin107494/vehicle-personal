<?php

namespace App\Http\Requests;

class CustomerImportRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to import.',
            'file.mimes' => 'The file must be a CSV.',
            'file.max' => 'The file must be 5 MB or smaller.',
        ];
    }
}
