<?php

namespace App\Services\Sms;

/** One SMS provider. Only SmsService talks to a gateway; no other code does. */
interface SmsGateway
{
    /** The driver name stored on the log ("smsnetbd", "bulksmsbd", "log"). */
    public function name(): string;

    /**
     * Sends one SMS to a number already normalized to 8801XXXXXXXXX.
     *
     * @return string the provider's message id ('' when it gives none)
     *
     * @throws SmsException when the provider refuses it or cannot be reached
     */
    public function send(string $phone, string $message): string;

    /** The account balance in taka, or null when the provider has no balance API or it could not be read. */
    public function balance(): ?float;
}
