<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class InvestorSaleRequest extends ApiRequest
{
    /** An amount on the plan of 0: the investor collects from the customer directly, so there is no schedule or profit to give. */
    protected function noPlan(): bool
    {
        return $this->filled('plan_amount') && is_numeric($this->input('plan_amount')) && (float) $this->input('plan_amount') === 0.0;
    }

    public function rules(): array
    {
        $plan = fn (array $rules) => $this->noPlan() ? ['exclude'] : $rules;

        return [
            'investor_id' => ['required', $this->shopExists('investors')],
            // one more customer besides the sale's buyer (a co-buyer or guarantor)
            'customer_id' => ['nullable', $this->shopExists('customers')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            // entered like a sale: the price and the down payment; the investor funds the rest
            'sale_price' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'down_payment' => ['required', 'numeric', 'min:0', 'lt:sale_price'],
            // the investor's amount (the principal the shop pays back with interest): empty is the price less the down payment
            // 0: no plan, the investor collects from the customer directly
            'plan_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            // what the investor gives the shop of it now; the rest is their due (empty: all of it now)
            'investor_down_payment' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'method' => ['nullable', Rule::in(['cash', 'bank', 'mobile'])],
            'memo_no' => ['nullable', 'string', 'max:100'],
            'guarantor_name' => ['nullable', 'string', 'max:100'],
            'guarantor_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            // the investor's profit: a flat percentage of the amount, or the amount itself
            'profit_rate' => $plan(['required_without:profit_amount', 'nullable', 'numeric', 'min:0', 'max:100']),
            'profit_amount' => $plan(['required_without:profit_rate', 'nullable', 'numeric', 'min:0', 'max:999999999999']),
            'installment_count' => $plan(['required', 'integer', 'min:1', 'max:120']),
            'frequency' => $plan(['nullable', Rule::in(['monthly', 'weekly'])]),
            'first_due_date' => $plan(['required', 'date', 'after:date']),
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
            'profit_rate.max' => 'Investor profit can be at most 100% a year.',
            'profit_amount.required_without' => 'Enter the investor profit percentage or amount.',
            'installment_count.required' => 'Number of installments is required.',
            'first_due_date.required' => 'First installment date is required.',
            'first_due_date.after' => 'First installment date must be after the sale date.',
        ];
    }
}
