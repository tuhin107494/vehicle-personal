<?php

namespace App\Http\Requests;

class UpdatePurchaseRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'party_id' => ['sometimes', $this->shopExists('parties')],
            'purchase_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'price' => ['sometimes', 'numeric', 'min:1'],
            'paid_amount' => ['sometimes', 'numeric', 'min:0'],
            'cost_extra' => ['sometimes', 'numeric', 'min:0'],
            'memo_no' => ['nullable', 'string', 'max:100', $this->shopUnique('purchases', 'memo_no')->where('party_id', $this->route('purchase')->party_id)->ignore($this->route('purchase')->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
