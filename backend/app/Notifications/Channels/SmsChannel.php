<?php

namespace App\Notifications\Channels;

use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * Lets a Notification go out as a text: the notification gives the words in toSms(), the notifiable gives the number
 * through routeNotificationForSms(). Nothing is sent when either is blank.
 */
class SmsChannel
{
    public function __construct(private SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms') || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }

        $phone = $notifiable->routeNotificationFor('sms', $notification);
        $message = $notification->toSms($notifiable);

        if (blank($phone) || blank($message)) {
            return;
        }

        $this->sender->send((string) $phone, (string) $message);
    }
}
