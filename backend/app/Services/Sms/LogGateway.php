<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Sends nothing: writes the SMS to the log. The default until a real gateway is set in .env. */
class LogGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $phone, string $message): string
    {
        Log::info('SMS (log driver, not sent)', ['to' => substr($phone, 0, 5).'****'.substr($phone, -3), 'message' => $message]);

        return 'log-'.Str::random(10);
    }

    public function balance(): ?float
    {
        return null;
    }
}
