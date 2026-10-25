<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\SignupConfirmation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The "confirm your email" message after an email-and-password sign-up. */
class ConfirmSignupEmail extends Notification
{
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email for '.config('app.name'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Thanks for signing up '.$notifiable->shop?->name.'. Confirm this is your email to open your shop and sign in.')
            ->action('Confirm email and sign in', SignupConfirmation::url($notifiable))
            ->line('The link works once, for '.SignupConfirmation::HOURS.' hours.')
            ->line('If you did not sign up, ignore this email: no account opens without this link.');
    }
}
