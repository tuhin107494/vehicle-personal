<?php

namespace App\Http\Requests;

use App\Models\VehicleColor;
use Illuminate\Validation\Rule;

class VehicleColorRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => ucfirst(trim((string) $this->input('name')))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', Rule::notIn(VehicleColor::STANDARD), $this->shopUnique('vehicle_colors', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter the colour name.',
            'name.not_in' => 'This colour is already in the list.',
            'name.unique' => 'This colour is already in the list.',
        ];
    }
}
