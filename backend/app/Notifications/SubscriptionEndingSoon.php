<?php

namespace App\Notifications;

use App\Models\Billing\Subscription;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a shop owner their trial or subscription ends soon (3 days and 1 day before), and what happens then. */
class SubscriptionEndingSoon extends Notification
{
    /** @param int $days whole days left: 0 (today), 1 (tomorrow), 2 or 3 */
    public function __construct(private Subscription $subscription, private int $days) {}

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $what = $this->subscription->status === 'trialing' ? 'free trial' : 'subscription';
        $when = match ($this->days) {
            0 => 'today',
            1 => 'tomorrow',
            default => "in {$this->days} days",
        };

        return (new MailMessage)
            ->subject("Your {$what} ends {$when}")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("The {$what} for {$notifiable->shop?->name} ends {$when}, on ".$this->subscription->ends_at->format('j M Y, g:i A').'.')
            ->line('After that your shop becomes read-only: you can still sign in, view and export your data, but you cannot add or change anything.')
            ->line('Contact support before it ends to keep using the app without a break.')
            ->action('Open your shop', rtrim(config('app.frontend_url'), '/').'/profile');
    }
}
