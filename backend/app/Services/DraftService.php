<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Draft;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;

/**
 * A sale draft is a booking in the making: once a vehicle is chosen in it, the vehicle is held ("booked") so nobody else sells it,
 * until the draft is deleted, another vehicle is chosen, or the draft becomes the sale.
 */
class DraftService
{
    public function save(array $data, User $user, ?Draft $draft = null): Draft
    {
        return DB::transaction(function () use ($data, $user, $draft) {
            $draft = $draft ? Draft::lockForUpdate()->findOrFail($draft->id) : new Draft(['created_by' => $user->id]);
            $draft->fill(array_intersect_key($data, array_flip(['type', 'label', 'data'])));

            $wanted = $draft->type === 'sale' ? (int) ($draft->data['values']['vehicle_id'] ?? 0) ?: null : null;
            $held = $draft->vehicle_id;

            if ($wanted !== $held) {
                if ($wanted) {
                    $vehicle = Vehicle::lockForUpdate()->find($wanted);

                    if (! $vehicle || $vehicle->status !== 'in_stock') {
                        throw new BusinessRuleException('This vehicle is not available: it is sold or already booked.');
                    }

                    $vehicle->update(['status' => 'booked']);
                }

                $this->release($held, $draft->id);
                $draft->vehicle_id = $wanted;
            }

            // a sale draft is kept for the shop's booking days from its last save, then it deletes itself
            $draft->expires_on = $draft->type === 'sale' ? today()->addDays(ShopSettings::bookingDays()) : null;
            $draft->save();

            return $draft;
        });
    }

    /** Drafts past their date are deleted, and the vehicles they held go back in stock. */
    public function expireDue(): int
    {
        $due = Draft::whereNotNull('expires_on')->whereDate('expires_on', '<', today())->get();
        $due->each(fn (Draft $d) => $this->delete($d));

        return $due->count();
    }

    public function delete(Draft $draft): void
    {
        DB::transaction(function () use ($draft) {
            $draft->delete();
            $this->release($draft->vehicle_id, $draft->id);
        });
    }

    /** The vehicle goes back in stock unless a booking or another draft still holds it (or it has been sold). */
    private function release(?int $vehicleId, ?int $exceptDraftId): void
    {
        if (! $vehicleId) {
            return;
        }

        $stillHeld = Booking::where('vehicle_id', $vehicleId)->where('status', 'active')->exists()
            || Draft::where('vehicle_id', $vehicleId)->where('id', '!=', $exceptDraftId)->exists();

        if (! $stillHeld) {
            Vehicle::where('id', $vehicleId)->where('status', 'booked')->update(['status' => 'in_stock']);
        }
    }
}
