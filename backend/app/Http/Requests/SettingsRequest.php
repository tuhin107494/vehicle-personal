<?php

namespace App\Http\Requests;

use App\Models\VehicleCategory;
use Illuminate\Contracts\Validation\Validator;

class SettingsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'purchase.vehicle_type' => ['nullable', $this->category()],
            'sale.vehicle_type' => ['nullable', $this->category()],
            'purchase.from_customer' => ['nullable', 'boolean'],
            'purchase.prices' => ['nullable', 'array'],
            'purchase.prices.*' => ['nullable', 'numeric', 'min:1'],
            'sale.prices' => ['nullable', 'array'],
            'sale.prices.*' => ['nullable', 'numeric', 'min:1'],
            'collections.recall_days' => ['nullable', 'integer', 'min:1', 'max:360'],
            'collections.keep_selection' => ['nullable', 'boolean'],
            'booking.days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'booking.on_cancel' => ['nullable', 'in:refund,keep'],
            'booking.on_expire' => ['nullable', 'in:refund,keep'],
            'type_defaults' => ['nullable', 'array'],
            'type_defaults.*' => ['array'],
            'type_defaults.*.brand' => ['nullable', 'string', 'max:100'],
            'type_defaults.*.model' => ['nullable', 'string', 'max:100'],
            'type_defaults.*.color' => ['nullable', 'string', 'max:50'],
            'type_defaults.*.cc' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'type_defaults.*.year' => ['nullable', 'integer', 'min:2017', 'max:2030'],
            'type_defaults.*.fuel_type' => ['nullable', 'string', 'max:50'],
            'type_defaults.*.mileage' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $known = VehicleCategory::pluck('key')->all();

            foreach (array_keys((array) $this->input('type_defaults', [])) as $type) {
                if (! in_array($type, $known, true)) {
                    $v->errors()->add('type_defaults', 'A default needs a valid vehicle type.');
                }
            }

            foreach (['purchase', 'sale'] as $group) {
                foreach (array_keys((array) $this->input("$group.prices", [])) as $type) {
                    if (! in_array($type, $known, true)) {
                        $v->errors()->add("$group.prices", 'A price needs a valid vehicle type.');
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'purchase.vehicle_type.exists' => 'Select a valid vehicle type.',
            'sale.vehicle_type.exists' => 'Select a valid vehicle type.',
            'purchase.prices.*.min' => 'Price must be at least 1.',
            'sale.prices.*.min' => 'Price must be at least 1.',
        ];
    }
}
