<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', $this->shopExists('vehicles')],
            'party_id' => ['required', $this->shopExists('parties')],
            'sale_date' => ['required', 'date', 'before_or_equal:today'],
            'sale_price' => ['required', 'numeric', 'min:1'],
            'payment_type' => ['required', Rule::in(['cash', 'due', 'installment'])],
            'down_payment' => ['required', 'numeric', 'min:0', 'lte:sale_price'],
            'method' => ['nullable', Rule::in(['cash', 'bank', 'mobile'])],
            'promised_date' => ['nullable', 'date', 'after:sale_date'],
            'promise_note' => ['nullable', 'string', 'max:1000'],
            'investor_id' => ['nullable', $this->shopExists('investors')],
            'installment_count' => ['required_if:payment_type,installment', 'nullable', 'integer', 'min:1', 'max:120'],
            'first_due_date' => ['required_if:payment_type,installment', 'nullable', 'date', 'after:sale_date'],
            'frequency' => ['nullable', Rule::in(['monthly', 'weekly'])],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'guarantor_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'], // the interest in money, instead of a rate
            'investor_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Select a vehicle.',
            'party_id.required' => 'Select the buyer.',
            'guarantor_phone.regex' => 'Enter a valid mobile number (7 to 20 digits).',
            'sale_price.required' => 'Sale price is required.',
            'sale_date.before_or_equal' => 'Sale date cannot be in the future.',
            'down_payment.lte' => 'Down payment cannot be more than the sale price.',
            'promised_date.after' => 'Promise date must be after the sale date.',
            'installment_count.required_if' => 'Number of installments is required.',
            'first_due_date.required_if' => 'First installment date is required.',
            'first_due_date.after' => 'First installment date must be after the sale date.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('payment_type') === 'cash' && ! $this->filled('down_payment')) {
            $this->merge(['down_payment' => $this->input('sale_price')]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->has('vehicle_id') || ! $this->filled('vehicle_id')) {
                return;
            }

            if (Vehicle::find($this->input('vehicle_id'))?->status !== 'in_stock') {
                $v->errors()->add('vehicle_id', 'This vehicle is not in stock, so it cannot be sold.');
            }

            if ($this->input('payment_type') === 'cash' && (float) $this->input('down_payment') !== (float) $this->input('sale_price')) {
                $v->errors()->add('down_payment', 'Cash sales must be paid in full.');
            }

            if (in_array($this->input('payment_type'), ['due', 'installment'], true)
                && (float) $this->input('down_payment') >= (float) $this->input('sale_price')) {
                $v->errors()->add('down_payment', 'Down payment must be less than the sale price for due and installment sales.');
            }
        });
    }
}
