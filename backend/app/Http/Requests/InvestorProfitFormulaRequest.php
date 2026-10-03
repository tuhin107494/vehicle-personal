<?php

namespace App\Http\Requests;

class InvestorProfitFormulaRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'invested' => ['required', 'numeric', 'gt:0'],
            'profit' => ['required', 'numeric', 'min:0'],
            'months' => ['required', 'integer', 'min:1', 'max:1200'],
        ];
    }

    public function messages(): array
    {
        return [
            'invested.required' => 'Enter the invested amount.',
            'invested.gt' => 'Invested amount must be greater than zero.',
            'profit.required' => 'Enter the profit earned.',
            'months.required' => 'Enter the time.',
            'months.min' => 'Time must be at least one month.',
        ];
    }
}
