<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function advance(Sale $sale, string $to, ?string $note, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $to, $note, $user) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'active') {
                throw new BusinessRuleException('Registration cannot be changed on a cancelled sale.');
            }

            $steps = Sale::REGISTRATION_STEPS;
            $from = array_search($sale->registration_status, $steps, true);
            $target = array_search($to, $steps, true);

            if ($target === $from + 1) {
                // forward one step
            } elseif ($target === $from - 1) {
                if (! $note) {
                    throw new BusinessRuleException('A note is required when moving registration back a step.');
                }
            } else {
                throw new BusinessRuleException('Registration can only move to the next step, or back one step with a note.');
            }

            $sale->registrationLogs()->create([
                'from_status' => $sale->registration_status,
                'to_status' => $to,
                'changed_at' => now(),
                'note' => $note,
                'changed_by' => $user->id,
            ]);

            $sale->update(['registration_status' => $to]);

            return $sale;
        });
    }
}
