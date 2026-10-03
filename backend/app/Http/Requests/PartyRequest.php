<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PartyRequest extends ApiRequest
{
    public function rules(): array
    {
        $id = $this->route('party')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/', $this->shopUnique('parties', 'phone')->ignore($id)],
            'alt_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'nid' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:50'],
            'bank_info' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['customer', 'supplier', 'both'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'phone.required' => 'Mobile number is required.',
            'phone.regex' => 'Enter a valid mobile number (7 to 20 digits).',
            'alt_phone.regex' => 'Enter a valid mobile number (7 to 20 digits).',
            'phone.unique' => 'A customer or supplier with this mobile number already exists.',
            'type.required' => 'Select whether this is a customer, supplier or both.',
        ];
    }
}
