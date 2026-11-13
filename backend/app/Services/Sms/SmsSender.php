<?php

namespace App\Services\Sms;

/**
 * Sends one text message. The app only ever talks to this interface; the driver (log today, a Bangladeshi SMS
 * provider later) is chosen in AppServiceProvider from services.sms.driver. A driver throws when the message
 * could not be handed over, so the caller can log it; it never throws for a message the provider accepted.
 */
interface SmsSender
{
    /** @param  string  $phone  the number as the shop entered it; the driver normalises it for its provider */
    public function send(string $phone, string $message): void;
}
