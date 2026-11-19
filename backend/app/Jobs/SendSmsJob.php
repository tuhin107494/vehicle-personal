<?php

namespace App\Jobs;

use App\Services\Sms\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one logged SMS through SmsService, on the "sms" queue. A gateway failure is tried 3 times with back-off, then shows as failed. */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds before the 2nd and 3rd try */
    public array $backoff = [60, 300];

    public function __construct(public int $smsLogId) {}

    public function handle(SmsService $sms): void
    {
        $sms->deliver($this->smsLogId);
    }

    public function failed(?Throwable $e): void
    {
        app(SmsService::class)->markFailed($this->smsLogId, $e?->getMessage() ?? 'Sending failed.');
    }
}
