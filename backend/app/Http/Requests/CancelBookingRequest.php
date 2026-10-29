<?php

namespace App\Http\Requests;

class CancelBookingRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            // left out: the shop's policy decides (refund it all, or keep it all)
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
