<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** The default driver: writes the message to the log instead of sending it. The number is masked, as the logging rule asks. */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS (log driver)', ['to' => self::mask($phone), 'length' => mb_strlen($message), 'message' => $message]);
    }

    /** 01712345678 → 017*****678 */
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 6) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3).str_repeat('*', strlen($digits) - 6).substr($digits, -3);
    }
}
