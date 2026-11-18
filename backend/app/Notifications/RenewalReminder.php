<?php

namespace App\Notifications;

use App\Models\Billing\Invoice;
use App\Models\Billing\Subscription;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your subscription ends in N days": email and a text to the shop owner, 7, 3 and 1 days before the period ends.
 * Sent from the notifications queue, so the scheduler is not held up by the mail server.
 */
class RenewalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription, public ?Invoice $invoice, public int $daysLeft)
    {
        $this->onQueue('notifications');
    }

    public function via(User $notifiable): array
    {
        return ['mail', SmsChannel::class];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $plan = $this->subscription->plan->name;
        $ends = $this->subscription->ends_at->toFormattedDayDateString();
        $mail = (new MailMessage)
            ->subject(sprintf('%s: your %s plan ends %s', config('app.name'), $plan, $this->when()))
            ->greeting('Hello '.$notifiable->name.',')
            ->line(sprintf('The %s plan for %s ends on %s (%s).', $plan, $notifiable->shop?->name, $ends, $this->when()));

        if ($this->invoice) {
            $mail->line(sprintf('Invoice %s for %s is waiting. Pay it to keep the shop running without a break.', $this->invoice->invoice_no, self::amount($this->invoice)));
        } else {
            $mail->line('Renew before then to keep the shop running without a break.');
        }

        return $mail
            ->action('View billing', self::billingUrl())
            ->line('After the end date there is a short grace period; once that passes, the shop is locked until it is renewed.');
    }

    public function toSms(User $notifiable): string
    {
        $due = $this->invoice ? ' Invoice '.$this->invoice->invoice_no.' ('.self::amount($this->invoice).') is waiting.' : '';

        return sprintf('%s: the %s plan for %s ends %s.%s Renew at %s', config('app.name'), $this->subscription->plan->name, $notifiable->shop?->name, $this->when(), $due, self::billingUrl());
    }

    /** Where the shop pays: the billing page of the dashboard. */
    public static function billingUrl(): string
    {
        return rtrim(config('app.frontend_url'), '/').'/billing';
    }

    private function when(): string
    {
        return match (true) {
            $this->daysLeft <= 0 => 'today',
            $this->daysLeft === 1 => 'tomorrow',
            default => 'in '.$this->daysLeft.' days',
        };
    }

    private static function amount(Invoice $invoice): string
    {
        return $invoice->currency.' '.Money::format($invoice->total_paisa);
    }
}
