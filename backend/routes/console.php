<?php

use App\Http\Controllers\Api\SignupController;
use App\Jobs\PublishOutbox;
use App\Models\Shop;
use App\Services\Billing\RenewalService;
use App\Services\DraftService;
use App\Services\Gateway\ReconciliationService;
use App\Support\SignupConfirmation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
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

// Sale drafts past their date: deleted, and the vehicle each held goes back in stock.
Artisan::command('drafts:expire', function () {
    $this->info('Deleted '.app(DraftService::class)->expireDue().' expired draft(s).');
})->purpose('Delete sale drafts past their date and free the vehicles they held');

// Subscriptions: statuses move by the clock, the next invoice is raised and owners are reminded before a period ends.
Artisan::command('billing:roll-statuses', function (RenewalService $renewals) {
    $moved = $renewals->rollStatuses();
    $this->info($moved ? collect($moved)->map(fn (int $n, string $status) => "$n → $status")->implode(', ') : 'Nothing to move.');
})->purpose('Move subscriptions past their end or grace date to past_due, cancelled or expired');

Artisan::command('billing:issue-renewals', function (RenewalService $renewals) {
    $this->info('Raised '.$renewals->issueRenewalInvoices().' renewal invoice(s).');
})->purpose('Raise the next period\'s invoice for subscriptions ending within '.RenewalService::INVOICE_DAYS_BEFORE.' days');

Artisan::command('billing:send-reminders', function (RenewalService $renewals) {
    $this->info('Queued '.$renewals->sendReminders().' renewal reminder(s).');
})->purpose('Email and text shop owners '.implode(', ', RenewalService::REMINDER_DAYS).' days before their subscription ends');

Artisan::command('billing:sync-usage', function (RenewalService $renewals) {
    $this->info('Synced usage for '.$renewals->syncUsage().' shop(s).');
})->purpose('Write the cached feature usage counters to the database');

// Payments: what webhooks missed is asked about again, and yesterday's payments are checked against the gateway and the ledger.
Artisan::command('payments:reconcile-pending', function (ReconciliationService $reconciliation) {
    $this->info('Queued '.$reconciliation->reconcilePending().' verification(s).');
})->purpose('Verify payment attempts still open after '.ReconciliationService::PENDING_MINUTES.' minutes');

Artisan::command('payments:compare-settlements {--date= : The day to check (Y-m-d), yesterday by default}', function (ReconciliationService $reconciliation) {
    $day = $this->option('date') ? Carbon::parse($this->option('date')) : today()->subDay();
    $totals = $reconciliation->compareSettlements($day);
    $this->info(sprintf('%s: %d payment(s) checked, %d flagged, %d could not be checked.', $day->toDateString(), $totals['checked'], $totals['flagged'], $totals['unreachable']));
})->purpose('Compare a day\'s payments with the gateway and the ledger, and flag mismatches');

Schedule::command('signups:prune')->daily();
Schedule::command('drafts:expire')->daily();

// the outbox publisher is nudged after every payment; this is the safety net for anything left pending
Schedule::job(new PublishOutbox)->everyMinute();
Schedule::command('payments:reconcile-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('billing:roll-statuses')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('billing:sync-usage')->hourly()->withoutOverlapping();
Schedule::command('billing:issue-renewals')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('payments:compare-settlements')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('billing:send-reminders')->dailyAt('09:00')->withoutOverlapping();
