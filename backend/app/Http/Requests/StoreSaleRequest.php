<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Draft;
use App\Models\Vehicle;
use App\Services\BookingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'draft_id' => ['nullable', 'integer', $this->shopExists('drafts')], // the draft this sale completes: it may hold the vehicle being sold
            'booking_id' => ['nullable', 'integer', $this->shopExists('bookings')], // the booking this sale completes: its advance is part of the down payment
            'vehicle_id' => ['required', $this->shopExists('vehicles')],
            'customer_id' => ['required', $this->shopExists('customers')],
            'sale_date' => ['required', 'date', 'before_or_equal:today'],
            'sale_price' => ['required', 'numeric', 'min:1'],
            'payment_type' => ['required', Rule::in(['cash', 'due', 'installment'])],
            'down_payment' => ['required', 'numeric', 'min:0', 'lte:sale_price'], // everything received at the sale, the advance included
            'advance_amount' => ['nullable', 'numeric', 'gt:0'], // money the customer paid earlier, kept as its own payment (a booking has its own advance)
            'advance_date' => ['nullable', 'date', 'before_or_equal:sale_date'],
            'method' => ['nullable', Rule::in(['cash', 'bank', 'mobile'])],
            'promised_date' => ['nullable', 'date', 'after:sale_date'],
            'promise_note' => ['nullable', 'string', 'max:1000'],
            'investor_id' => ['nullable', $this->shopExists('investors')],
            'installment_count' => ['required_if:payment_type,installment', 'nullable', 'integer', 'min:1', 'max:120'],
            'first_due_date' => ['required_if:payment_type,installment', 'nullable', 'date', 'after:sale_date'],
            'frequency' => ['nullable', Rule::in(['monthly', 'weekly'])],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'guarantor_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'guarantor_name' => ['nullable', 'string', 'max:100'],
            'memo_no' => ['nullable', 'string', 'max:100'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'], // the interest in money, instead of a rate
            'investor_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Select a vehicle.',
            'customer_id.required' => 'Select the buyer.',
            'guarantor_phone.regex' => 'Enter a valid mobile number (7 to 20 digits).',
            'sale_price.required' => 'Sale price is required.',
            'sale_date.before_or_equal' => 'Sale date cannot be in the future.',
            'advance_date.before_or_equal' => 'The advance date cannot be after the sale date.',
            'down_payment.lte' => 'Down payment cannot be more than the sale price.',
            'promised_date.after' => 'Promise date must be after the sale date.',
            'installment_count.required_if' => 'Number of installments is required.',
            'first_due_date.required_if' => 'First installment date is required.',
            'first_due_date.after' => 'First installment date must be after the sale date.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('payment_type') === 'cash' && ! $this->filled('down_payment')) {
            $this->merge(['down_payment' => $this->input('sale_price')]);
        }
    }

    /** The vehicle is in stock, or booked only by the draft this sale completes. */
    private function vehicleIsFree(): bool
    {
        $vehicle = Vehicle::find($this->input('vehicle_id'));

        return $vehicle?->status === 'in_stock'
            || ($vehicle?->status === 'booked' && $this->filled('draft_id') && Draft::whereKey($this->input('draft_id'))->where('vehicle_id', $vehicle->id)->exists());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->has('vehicle_id') || ! $this->filled('vehicle_id')) {
                return;
            }

            if ($this->filled('booking_id')) {
                app(BookingService::class)->expireDue(); // a lapsed booking is closed first, so it cannot be completed
                $booking = Booking::find($this->input('booking_id'));

                if (! $booking || $booking->status !== 'active') {
                    $v->errors()->add('booking_id', 'This booking is no longer active.');
                } elseif ($this->filled('sale_date') && $this->date('sale_date')->lt($booking->booked_on)) {
                    $v->errors()->add('sale_date', 'The sale date cannot be before the booking date ('.$booking->booked_on->format('d M Y').').');
                } elseif ((int) $this->input('customer_id') !== $booking->customer_id) {
                    $v->errors()->add('booking_id', 'The buyer must be the one on the booking.');
                } elseif ((int) $this->input('vehicle_id') !== $booking->vehicle_id && ! $this->vehicleIsFree()) {
                    // the buyer may choose another vehicle than the booked one, as long as it is in stock
                    $v->errors()->add('vehicle_id', 'This vehicle is not in stock, so it cannot be sold.');
                } elseif ((float) $this->input('down_payment') < (float) $booking->advance_amount) {
                    $v->errors()->add('down_payment', 'The down payment must include the booking advance of '.number_format((float) $booking->advance_amount, 2).'.');
                }
            } elseif (! $this->vehicleIsFree()) {
                $v->errors()->add('vehicle_id', 'This vehicle is not in stock, so it cannot be sold.');
            }

            if (! $this->filled('booking_id') && $this->filled('advance_amount')) {
                if ((float) $this->input('advance_amount') >= (float) $this->input('sale_price')) {
                    $v->errors()->add('advance_amount', 'The advance must be less than the sale price.');
                } elseif ((float) $this->input('advance_amount') > (float) $this->input('down_payment')) {
                    $v->errors()->add('down_payment', 'The down payment must include the advance of '.number_format((float) $this->input('advance_amount'), 2).'.');
                }
            }

            if ($this->input('payment_type') === 'cash' && (float) $this->input('down_payment') !== (float) $this->input('sale_price')) {
                $v->errors()->add('down_payment', 'Cash sales must be paid in full.');
            }

            if (in_array($this->input('payment_type'), ['due', 'installment'], true)
                && (float) $this->input('down_payment') >= (float) $this->input('sale_price')) {
                $v->errors()->add('down_payment', 'Down payment must be less than the sale price for due and installment sales.');
            }
        });
    }
}
