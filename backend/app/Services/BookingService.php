<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;

/**
 * Holds a vehicle for a customer who paid an advance. The vehicle is "booked" (nobody else can buy it) until the booking ends:
 * completed (the sale form turns it into a sale, cash, due or installment, with the advance counted as received), cancelled, or expired (the deadline passed). A closed booking releases the vehicle
 * and says how much of the advance went back and how much the shop kept.
 */
class BookingService
{
    public function create(array $data, User $user): Booking
    {
        $this->expireDue();

        return DB::transaction(function () use ($data, $user) {
            // with no vehicle chosen yet nothing is held; the vehicle is picked when the booking becomes a sale
            $vehicle = ! empty($data['vehicle_id']) ? Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']) : null;

            if ($vehicle && $vehicle->status !== 'in_stock') {
                throw new BusinessRuleException('Only a vehicle that is in stock can be booked.');
            }

            $price = ! empty($data['agreed_price']) ? Money::paisa($data['agreed_price']) : null; // no price yet: it is set at the sale
            $advance = Money::paisa($data['advance_amount'] ?? 0); // a booking can be made with no advance

            if ($advance < 0 || ($price !== null && $advance >= $price)) {
                throw new BusinessRuleException('The advance must be less than the sale price.');
            }

            $booking = Booking::create([
                'vehicle_id' => $vehicle?->id,
                'customer_id' => $data['customer_id'],
                'agreed_price' => $price === null ? null : Money::taka($price),
                'advance_amount' => Money::taka($advance),
                'advance_method' => $data['advance_method'] ?? 'cash',
                'booked_on' => today(),
                'expires_on' => $data['expires_on'] ?? null, // no date: held until it is sold or cancelled (the form suggests the shop's usual days)
                'status' => 'active',
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            $vehicle?->update(['status' => 'booked']);

            return $booking;
        });
    }

    /** An active booking can be corrected: the customer, vehicle (the hold moves to the new one), price, advance, deadline and note. The booking date stays. */
    public function update(Booking $booking, array $data): Booking
    {
        $this->expireDue();

        return DB::transaction(function () use ($booking, $data) {
            $booking = $this->lockActive($booking);
            $vehicleId = ! empty($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;

            if ($vehicleId !== $booking->vehicle_id) {
                $new = $vehicleId ? Vehicle::lockForUpdate()->findOrFail($vehicleId) : null;

                if ($new && $new->status !== 'in_stock') {
                    throw new BusinessRuleException('Only a vehicle that is in stock can be booked.');
                }

                Vehicle::where('id', $booking->vehicle_id)->where('status', 'booked')->update(['status' => 'in_stock']);
                $new?->update(['status' => 'booked']);
            }

            $price = ! empty($data['agreed_price']) ? Money::paisa($data['agreed_price']) : null;
            $advance = Money::paisa($data['advance_amount'] ?? 0);

            if ($advance < 0 || ($price !== null && $advance >= $price)) {
                throw new BusinessRuleException('The advance must be less than the sale price.');
            }

            $booking->update([
                'vehicle_id' => $vehicleId,
                'customer_id' => $data['customer_id'],
                'agreed_price' => $price === null ? null : Money::taka($price),
                'advance_amount' => Money::taka($advance),
                'advance_method' => $data['advance_method'] ?? $booking->advance_method,
                'expires_on' => $data['expires_on'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            return $booking;
        });
    }

    /** The customer backs out: the vehicle is released and the advance is refunded as far as the shop agrees (the shop's policy fills in the default). */
    public function cancel(Booking $booking, ?float $refund, ?string $note): Booking
    {
        return DB::transaction(function () use ($booking, $refund, $note) {
            $booking = $this->lockActive($booking);
            $refund ??= $this->defaultRefund($booking, 'on_cancel');

            return $this->close($booking, 'cancelled', $refund, $note);
        });
    }

    /** Bookings past their deadline are closed as expired, the advance handled by the shop's policy. */
    public function expireDue(): void
    {
        app(DraftService::class)->expireDue(); // lapsed sale drafts go too, so their vehicles are free again

        Booking::where('status', 'active')->whereDate('expires_on', '<', today())->pluck('id')->each(function ($id) {
            DB::transaction(function () use ($id) {
                $booking = Booking::lockForUpdate()->find($id);

                if ($booking && $booking->status === 'active') {
                    $this->close($booking, 'expired', $this->defaultRefund($booking, 'on_expire'), 'Deadline passed');
                }
            });
        });
    }

    private function lockActive(Booking $booking): Booking
    {
        $booking = Booking::lockForUpdate()->findOrFail($booking->id);

        if ($booking->status !== 'active') {
            throw new BusinessRuleException('This booking is already '.$booking->status.'.');
        }

        return $booking;
    }

    private function defaultRefund(Booking $booking, string $when): float
    {
        return ShopSettings::bookingPolicy($when) === 'refund' ? (float) $booking->advance_amount : 0.0;
    }

    private function close(Booking $booking, string $status, float $refund, ?string $note): Booking
    {
        $advance = Money::paisa($booking->advance_amount);
        $back = Money::paisa($refund);

        if ($back < 0 || $back > $advance) {
            throw new BusinessRuleException('The refund must be between zero and the advance ('.Money::format($advance).').');
        }

        $booking->update([
            'status' => $status,
            'refund_amount' => Money::taka($back),
            'kept_amount' => Money::taka($advance - $back),
            'closed_on' => today(),
            'close_note' => $note,
        ]);

        Vehicle::where('id', $booking->vehicle_id)->where('status', 'booked')->update(['status' => 'in_stock']);

        return $booking;
    }
}
