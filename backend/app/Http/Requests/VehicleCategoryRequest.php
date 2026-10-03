<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class VehicleCategoryRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('vehicle_categories', 'name')->where(fn ($q) => $q->whereNull('shop_id')->orWhere('shop_id', $this->user()->shop_id ?? 0))->ignore($this->route('category')?->id)],
            'default_brand' => ['nullable', 'string', 'max:100'],
            'default_model' => ['nullable', 'string', 'max:100'],
            'purchase_price' => ['nullable', 'numeric', 'min:1'],
            'default_color' => ['nullable', 'string', 'max:50'],
            'default_cc' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_price.min' => 'Price must be at least 1.',
            'name.required' => 'Category name is required.',
            'name.unique' => 'A category with this name already exists.',
        ];
    }
}
