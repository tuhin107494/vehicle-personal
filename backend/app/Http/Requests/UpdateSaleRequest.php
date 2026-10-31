<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** Editing a sale: the same fields as a new sale, except the vehicle (a wrong vehicle means cancel and sell again). */
class UpdateSaleRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', $this->shopExists('customers')],
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
            'interest_amount' => ['nullable', 'numeric', 'min:0'],
            'investor_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'guarantor_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'guarantor_name' => ['nullable', 'string', 'max:100'],
            'memo_no' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Select the buyer.',
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
            if ($this->input('payment_type') === 'cash' && (float) $this->input('down_payment') !== (float) $this->input('sale_price')) {
                $v->errors()->add('down_payment', 'Cash sales must be paid in full.');
            }

            if (in_array($this->input('payment_type'), ['due', 'installment'], true)
                && (float) $this->input('down_payment') >= (float) $this->input('sale_price')) {
                $v->errors()->add('down_payment', 'Down payment must be less than the sale price for due and installment sales.');
            }

            $booking = $this->route('sale')?->booking;

            if ($booking && $this->filled('sale_date') && $this->date('sale_date')->lt($booking->booked_on)) {
                $v->errors()->add('sale_date', 'The sale date cannot be before the booking date ('.$booking->booked_on->format('d M Y').').');
            }

            if ($booking && (int) $this->input('customer_id') !== $booking->customer_id) {
                $v->errors()->add('customer_id', 'The buyer must be the one on the booking.');
            }
        });
    }
}
