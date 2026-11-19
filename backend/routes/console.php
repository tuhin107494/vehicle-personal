<?php

use App\Http\Controllers\Api\SignupController;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\SmsBalanceLow;
use App\Services\Billing\SubscriptionService;
use App\Services\DraftService;
use App\Services\Sms\SmsService;
use App\Support\SignupConfirmation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
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

// Sale drafts past their date: deleted, and the vehicle each held goes back in stock.
Artisan::command('drafts:expire', function () {
    $this->info('Deleted '.app(DraftService::class)->expireDue().' expired draft(s).');
})->purpose('Delete sale drafts past their date and free the vehicles they held');

// Trials and subscriptions ending soon: the shop owner is emailed 3 days and 1 day before.
Artisan::command('subscriptions:remind', function (SubscriptionService $subscriptions) {
    $this->info('Sent '.$subscriptions->sendExpiryReminders().' reminder(s).');
})->purpose('Email shop owners whose trial or subscription ends in 3 days or 1 day');

// Customers' due alerts: 3 days before, on the day, 1 and 7 days after. Each shop sets its own days and the time it runs (10:00 by default);
// this runs every 5 minutes and runs each shop once a day, at or after that time. One SMS per due per stage.
Artisan::command('sms:due-alerts', function (SmsService $sms) {
    $this->info('Queued '.$sms->queueDueAlerts().' due alert(s).');
})->purpose('Queue the SMS due alerts for today');

// The gateway's balance: the super admins are emailed when it falls below SMS_LOW_BALANCE.
Artisan::command('sms:balance-check', function (SmsService $sms) {
    $limit = (float) config('sms.low_balance');
    $gateway = $sms->gateway();
    $balance = $limit > 0 ? $gateway->balance() : null;

    if ($balance === null || $balance >= $limit) {
        $this->info($balance === null ? 'No balance to check.' : "Balance Tk {$balance} is fine.");

        return;
    }

    Notification::send(User::where('role', 'super_admin')->where('is_active', true)->get(), new SmsBalanceLow($gateway->name(), $balance, $limit));
    $this->warn("Balance Tk {$balance} is low: the super admins were emailed.");
})->purpose('Email the super admins when the SMS balance is low');

Schedule::command('signups:prune')->daily();
Schedule::command('drafts:expire')->daily();
Schedule::command('subscriptions:remind')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('sms:due-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sms:balance-check')->dailyAt('09:30')->timezone('Asia/Dhaka');
