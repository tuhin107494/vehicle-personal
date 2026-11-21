<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class InvestorSettlementRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'investor_id' => ['required', $this->shopExists('investors')],
            'plan_id' => ['nullable', $this->shopExists('installment_plans')],
            // a payment on an investor sale: the service checks it is the investor's, still active, and not more than is owed
            'investor_sale_id' => ['nullable', 'prohibits:plan_id', $this->shopExists('investor_sales')],
            'type' => ['required', Rule::in(['investment', 'principal_return', 'profit_share', 'withdrawal'])],
            'amount' => ['required', 'numeric', 'min:1'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'investor_id.required' => 'Select an investor.',
            'amount.required' => 'Amount is required.',
            'type.required' => 'Select the settlement type.',
        ];
    }
}
