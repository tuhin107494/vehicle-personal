<?php

namespace App\Http\Requests;

/** Editing an investor sale: the same fields as making one, except the investor (cancel and add again to change it). */
class UpdateInvestorSaleRequest extends InvestorSaleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['investor_id']);

        return $rules;
    }
}
