<?php

namespace App\Http\Requests;

class CustomerRequest extends ApiRequest
{
    public function rules(): array
    {
        $id = $this->route('customer')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/', $this->shopUnique('customers', 'phone')->ignore($id)],
            'alt_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'nid' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
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
            'phone.unique' => 'A customer with this mobile number already exists.',
        ];
    }
}
