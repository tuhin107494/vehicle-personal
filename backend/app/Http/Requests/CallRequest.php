<?php

namespace App\Http\Requests;

/** A call made, or a call mark taken back, about a customer's installment or promised due. */
class CallRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:installment,promise'],
            'id' => ['required', 'integer'],
            'meet_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(360)->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
