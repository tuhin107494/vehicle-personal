<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class InvestorSaleRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'investor_id' => ['required', $this->shopExists('investors')],
            // one more customer besides the sale's buyer (a co-buyer or guarantor)
            'customer_id' => ['nullable', $this->shopExists('customers')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            // entered like a sale: the price and the down payment; the investor funds the rest
            'sale_price' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'down_payment' => ['required', 'numeric', 'min:0', 'lt:sale_price'],
            'method' => ['nullable', Rule::in(['cash', 'bank', 'mobile'])],
            'memo_no' => ['nullable', 'string', 'max:100'],
            'guarantor_name' => ['nullable', 'string', 'max:100'],
            'guarantor_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            // the investor's profit: a flat percentage of the amount, or the amount itself
            'profit_rate' => ['required_without:profit_amount', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'profit_amount' => ['required_without:profit_rate', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:120'],
            'frequency' => ['nullable', Rule::in(['monthly', 'weekly'])],
            'first_due_date' => ['required', 'date', 'after:date'],
        ];
    }

    public function messages(): array
    {
        return [
            'investor_id.required' => 'Select the investor.',
            'sale_price.required' => 'Sale price is required.',
            'down_payment.required' => 'Enter the down payment (0 if none).',
            'down_payment.lt' => 'Down payment must be less than the sale price.',
            'guarantor_phone.regex' => 'Enter a valid mobile number (7 to 20 digits).',
            'profit_rate.required_without' => 'Enter the investor profit percentage or amount.',
            'profit_amount.required_without' => 'Enter the investor profit percentage or amount.',
            'installment_count.required' => 'Number of installments is required.',
            'first_due_date.required' => 'First installment date is required.',
            'first_due_date.after' => 'First installment date must be after the sale date.',
        ];
    }
}
