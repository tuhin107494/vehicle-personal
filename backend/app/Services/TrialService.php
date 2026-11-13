<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The free trial of a shop that signed itself up. All trial rules live here; to drop trials, see the removal notes in docs/trial.md.
 */
class TrialService
{
    public const DAYS = 7;

    public const ENDED_MESSAGE = 'Your free trial has ended. Contact support to continue.';

    /** Starts the trial when a signed-up shop opens. A shop that already has one keeps it. */
    public function start(Shop $shop): void
    {
        if ($shop->trial_ends_at === null) {
            $shop->update(['trial_ends_at' => now()->addDays(self::DAYS)]);
        }
    }

    /** Sets the day a shop's trial ends (the whole day counts), or clears it so the shop has no time limit. */
    public function set(Shop $shop, ?string $date): void
    {
        $shop->update(['trial_ends_at' => $date === null ? null : Carbon::parse($date)->endOfDay()]);
    }

    public function ended(?Shop $shop): bool
    {
        return $shop?->trial_ends_at !== null && $shop->trial_ends_at->isPast();
    }

    /** Adds days to the trial, counted from today when it has already ended, or from its end date when it is still running. */
    public function extend(Shop $shop, int $days): Shop
    {
        return DB::transaction(function () use ($shop, $days) {
            $shop = Shop::lockForUpdate()->findOrFail($shop->id);

            if ($shop->trial_ends_at === null) {
                throw new BusinessRuleException('This shop has no trial to extend.');
            }

            $from = $shop->trial_ends_at->isFuture() ? $shop->trial_ends_at : now();
            $shop->update(['trial_ends_at' => $from->copy()->addDays($days)]);

            return $shop;
        });
    }
}
