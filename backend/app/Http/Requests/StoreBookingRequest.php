<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

class StoreBookingRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'vehicle_id' => ['nullable', 'integer', $this->shopExists('vehicles')], // left out: the vehicle is chosen when the booking becomes a sale
            'customer_id' => ['required', 'integer', $this->shopExists('customers')],
            'agreed_price' => ['nullable', 'numeric', 'min:1'], // the sale price; left out: it is set when the booking becomes a sale
            'advance_amount' => ['nullable', 'numeric', 'min:0'], // none is allowed: the vehicle is held without money taken
            'advance_method' => ['nullable', 'in:cash,bank,mobile'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'], // no date: no deadline
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Choose the customer.',
            'expires_on.after_or_equal' => 'The deadline cannot be in the past.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled('agreed_price') && $this->filled('advance_amount') && ! $v->errors()->has('advance_amount') && (float) $this->input('advance_amount') >= (float) $this->input('agreed_price')) {
                $v->errors()->add('advance_amount', 'The advance must be less than the sale price.');
            }
        });
    }
}
