<?php

namespace App\Http\Requests;

class VehicleRequest extends ApiRequest
{
    public function rules(): array
    {
        $id = $this->route('vehicle')?->id;

        return [
            'chassis_no' => ['required', 'string', 'max:100', $this->shopUnique('vehicles', 'chassis_no')->ignore($id)],
            'engine_no' => ['required', 'string', 'max:100', $this->shopUnique('vehicles', 'engine_no')->ignore($id)],
            'registration_no' => ['nullable', 'string', 'max:100', $this->shopUnique('vehicles', 'registration_no')->ignore($id)],
            'vehicle_type' => ['required', $this->category()],
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
        ];
    }

    public function messages(): array
    {
        return [
            'chassis_no.required' => 'Chassis number is required.',
            'chassis_no.unique' => 'A vehicle with this chassis number already exists.',
            'engine_no.required' => 'Engine number is required.',
            'engine_no.unique' => 'A vehicle with this engine number already exists.',
            'vehicle_type.required' => 'Select the vehicle type.',
            'vehicle_type.exists' => 'Select a valid vehicle type.',
            'registration_no.unique' => 'This registration number is already used by another vehicle.',
        ];
    }
}
