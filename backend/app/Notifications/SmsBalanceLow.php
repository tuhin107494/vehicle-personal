<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the super admins the SMS gateway's balance is low, so due alerts do not stop. An admin alert, never a customer SMS. */
class SmsBalanceLow extends Notification
{
    public function __construct(private string $gateway, private float $balance, private float $limit) {}

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SMS balance is low')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("The {$this->gateway} SMS balance is Tk ".number_format($this->balance, 2).', below the Tk '.number_format($this->limit, 2).' alert level.')
            ->line('Top it up soon: when it runs out, customers stop getting their due alerts.');
    }
}
