<?php

namespace App\Http\Requests;

use App\Models\VehicleCategory;
use App\Services\Sms\SmsSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

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
            'forms.auto_date' => ['nullable', 'boolean'],
            'forms.memo_no' => ['nullable', 'boolean'],
            'forms.removal' => ['nullable', Rule::in(['delete', 'cancel'])],
            'booking.days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'booking.on_cancel' => ['nullable', 'in:refund,keep'],
            'booking.on_expire' => ['nullable', 'in:refund,keep'],
            'sms.enabled' => ['nullable', 'boolean'],
            'sms.language' => ['nullable', Rule::in(['bn', 'en'])],
            'sms.days.upcoming' => ['nullable', 'integer', 'min:1', 'max:30'],
            'sms.days.overdue' => ['nullable', 'integer', 'min:1', 'max:60'],
            'sms.days.final' => ['nullable', 'integer', 'min:2', 'max:180', 'gt:sms.days.overdue'],
            // when the day's run sends, and the hours an SMS may go out (24h clock, Asia/Dhaka)
            'sms.send_time' => ['nullable', 'date_format:H:i'],
            'sms.send_from' => ['nullable', 'integer', 'min:6', 'max:22'],
            'sms.send_until' => ['nullable', 'integer', 'min:7', 'max:23'],
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
            // the run time and the sending hours are checked together with what is saved, as the page saves one at a time
            if ($this->has('sms.send_time') || $this->has('sms.send_from') || $this->has('sms.send_until')) {
                $saved = SmsSettings::forShop((int) $this->user()->shop_id);
                $from = (int) $this->input('sms.send_from', $saved['send_from']);
                $until = (int) $this->input('sms.send_until', $saved['send_until']);
                $time = (string) $this->input('sms.send_time', $saved['send_time']);

                if ($from >= $until) {
                    $v->errors()->add('sms.send_until', 'SMS must be allowed to go out for at least an hour: the end must be after the start.');
                } elseif ($time < sprintf('%02d:00', $from) || $time >= sprintf('%02d:00', $until)) {
                    $v->errors()->add('sms.send_time', "The send time must be within the sending hours ({$from}:00 to {$until}:00).");
                }
            }

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
            'sms.days.upcoming.min' => 'Send the first reminder at least 1 day before.',
            'sms.days.final.gt' => 'The final reminder must come after the overdue reminder.',
            'sms.send_time.date_format' => 'Choose a time like 10:00.',
        ];
    }
}
