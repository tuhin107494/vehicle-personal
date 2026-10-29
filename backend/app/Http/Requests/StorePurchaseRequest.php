<?php

namespace App\Http\Requests;

use App\Support\ShopSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends ApiRequest
{
    public function rules(): array
    {
        $existing = $this->filled('vehicle_id');

        return [
            'vehicle_id' => ['nullable', $this->shopExists('vehicles')],
            'chassis_no' => [Rule::requiredIf(! $existing), 'nullable', 'string', 'max:100', $this->shopUnique('vehicles', 'chassis_no')],
            'engine_no' => [Rule::requiredIf(! $existing), 'nullable', 'string', 'max:100', $this->shopUnique('vehicles', 'engine_no')],
            'registration_no' => ['nullable', 'string', 'max:100', $this->shopUnique('vehicles', 'registration_no')->ignore($this->input('vehicle_id'))],
            'vehicle_type' => [Rule::requiredIf(! $existing), 'nullable', $this->category()],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:2017', 'max:2030'],
            'color' => ['nullable', 'string', 'max:50'],
            'cc' => ['nullable', 'integer', 'min:1'],
            'fuel_type' => ['nullable', 'string', 'max:50'],
            'registration_date' => ['nullable', 'date', 'before_or_equal:today'],
            'tax_token_expiry' => ['nullable', 'date'],
            'fitness_expiry' => ['nullable', 'date'],
            'insurance_expiry' => ['nullable', 'date'],
            'papers_received' => ['nullable', 'boolean'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'condition_notes' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', Rule::in(['supplier', 'customer'])],
            // a supplier for "from supplier", a customer for a second-hand vehicle bought back
            'supplier_id' => [Rule::requiredIf($this->input('source', 'supplier') !== 'customer'), 'nullable', $this->shopExists('suppliers')],
            'customer_id' => [Rule::requiredIf($this->input('source') === 'customer'), 'nullable', $this->shopExists('customers')],
            'memo_no' => ['nullable', 'string', 'max:100', $this->shopUnique('purchases', 'memo_no')->where('supplier_id', $this->input('supplier_id'))],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'price' => ['required', 'numeric', 'min:1'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'cost_extra' => ['nullable', 'numeric', 'min:0'],
            'due_adjustment' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'adjusted_sale_id' => ['nullable', $this->shopExists('sales')],
            'payment_method' => ['nullable', Rule::in(['cash', 'bank', 'mobile'])],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('source') === 'customer' && ! ShopSettings::purchaseFromCustomer()) {
                $v->errors()->add('source', 'Buying from customers is turned off in Settings.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'chassis_no.required' => 'Chassis number is required for a new vehicle.',
            'chassis_no.unique' => 'This chassis number is already in our records. Buy it back from a customer on the second-hand tab.',
            'vehicle_type.required' => 'Select the vehicle type for a new vehicle.',
            'engine_no.required' => 'Engine number is required for a new vehicle.',
            'engine_no.unique' => 'This engine number already exists.',
            'supplier_id.required' => 'Select the supplier.',
            'customer_id.required' => 'Select the customer.',
            'price.required' => 'Purchase price is required.',
            'registration_date.before_or_equal' => 'Registration date cannot be in the future.',
            'memo_no.unique' => 'This memo number is already recorded for this seller.',
            'due_adjustment.lte' => 'The due adjustment cannot be more than the purchase price.',
            'paid_amount.lte' => 'Paid amount cannot be more than the price.',
            'purchase_date.before_or_equal' => 'Purchase date cannot be in the future.',
        ];
    }
}
