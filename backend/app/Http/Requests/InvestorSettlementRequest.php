<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class InvestorSettlementRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'investor_id' => ['required', $this->shopExists('investors')],
            // only a plan this investor finances: their figures must not take in money from someone else's deal
            'plan_id' => ['nullable', $this->shopExists('installment_plans')->where('investor_id', (int) $this->input('investor_id'))],
            // a payment on an investor sale: the service checks it is the investor's, still active, and not more than is owed
            'investor_sale_id' => ['nullable', 'prohibits:plan_id', $this->shopExists('investor_sales')],
            'type' => ['required', Rule::in(['investment', 'principal_return', 'profit_share', 'withdrawal'])],
            'amount' => ['required', 'numeric', 'min:1'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000', 'not_regex:/^\s*Auto:/i'], // "Auto:" marks the settlements the system makes
        ];
    }

    public function messages(): array
    {
        return [
            'investor_id.required' => 'Select an investor.',
            'amount.required' => 'Amount is required.',
            'type.required' => 'Select the settlement type.',
            'plan_id.exists' => 'This plan is not financed by this investor.',
            'note.not_regex' => 'A note cannot start with "Auto:".',
        ];
    }
}
