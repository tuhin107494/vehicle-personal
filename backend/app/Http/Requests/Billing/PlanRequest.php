<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\ApiRequest;
use App\Models\Billing\Plan;
use App\Support\FeatureCatalog;
use Illuminate\Validation\Rule;

/** The super admin adding or editing a plan. `features` is key => value: "1"/"0" for a switch, a number or null (unlimited) for a limit. */
class PlanRequest extends ApiRequest
{
    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans', 'slug')->ignore($plan)],
            'price_paisa' => ['required', 'integer', 'min:0', 'max:100000000000'],
            // the regular price before discount, display only; it can never be below what the shop pays
            'original_price_paisa' => ['nullable', 'integer', 'min:0', 'max:100000000000', 'gte:price_paisa'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_interval' => ['required', Rule::in(array_keys(Plan::INTERVALS))],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'description' => ['nullable', 'string', 'max:1000'],
            'features' => ['nullable', 'array'],
            ...collect(FeatureCatalog::all())->mapWithKeys(fn (array $f) => ["features.{$f[0]}" => $f[1] === FeatureCatalog::BOOLEAN ? ['nullable', 'boolean'] : ['nullable', 'integer', 'min:0', 'max:1000000']])->all(),
        ];
    }

    public function messages(): array
    {
        return ['original_price_paisa.gte' => 'The regular price cannot be lower than the price after discount.', 'billing_interval.in' => 'Choose monthly, 3 months, 6 months or yearly.', 'slug.regex' => 'The slug may only hold lowercase letters, digits and dashes.', 'slug.unique' => 'Another plan already uses this slug.'];
    }

    /** Only the keys the code knows are kept; the request rules above already type each one. */
    public function validatedFeatures(): array
    {
        return array_intersect_key($this->validated('features') ?? [], array_flip(FeatureCatalog::keys()));
    }
}
