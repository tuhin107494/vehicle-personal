<?php

use App\Http\Controllers\Api\SignupController;
use App\Models\Shop;
use App\Support\SignupConfirmation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Email sign-ups never confirmed: delete them, so a made-up or someone else's email is free again.
Artisan::command('signups:prune', function () {
    $shops = Shop::whereNull('approved_at')
        ->where('created_at', '<', now()->subDays(SignupConfirmation::PRUNE_AFTER_DAYS))
        ->whereHas('owner', fn ($q) => $q->whereNull('email_verified_at'))
        ->get();

    $shops->each(fn (Shop $shop) => SignupController::deleteSignup($shop));
    $this->info("Deleted {$shops->count()} unconfirmed sign-up(s).");
})->purpose('Delete email sign-ups never confirmed within '.SignupConfirmation::PRUNE_AFTER_DAYS.' days');

Schedule::command('signups:prune')->daily();
