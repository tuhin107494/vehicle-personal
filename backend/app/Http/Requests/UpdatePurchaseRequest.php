<?php

namespace App\Http\Requests;

class UpdatePurchaseRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', $this->shopExists('suppliers')],
            'customer_id' => ['sometimes', $this->shopExists('customers')],
            'purchase_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'price' => ['sometimes', 'numeric', 'min:1'],
            'paid_amount' => ['sometimes', 'numeric', 'min:0'],
            'cost_extra' => ['sometimes', 'numeric', 'min:0'],
            'memo_no' => ['nullable', 'string', 'max:100', $this->shopUnique('purchases', 'memo_no')->where('supplier_id', $this->route('purchase')->supplier_id)->ignore($this->route('purchase')->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
