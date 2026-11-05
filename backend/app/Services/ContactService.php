<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Emails a message from the landing page's contact form to the support email, and to nobody else. */
class ContactService
{
    /** @param  array{name: string, email: string, message: string}  $data */
    public function send(array $data): void
    {
        $to = PlatformSettings::get('landing.support_email');

        if (blank($to)) {
            throw new BusinessRuleException('Support is unavailable for now. It will be available soon.');
        }

        // no mail server: say so, because the visitor would otherwise wait for a reply that never comes
        if (! PlatformSettings::canSendMail()) {
            throw new BusinessRuleException('The contact form is not available right now. Please email us directly.');
        }

        $body = "Name: {$data['name']}\nEmail: {$data['email']}\n\n{$data['message']}\n\nReply to this email to answer them.";

        try {
            // the visitor goes in Reply-To only: sending From their address would fail SPF and DKIM, and lets anyone forge a sender
            Mail::raw($body, fn ($m) => $m->to($to)->replyTo($data['email'], $data['name'])->subject('Website message from '.$data['name']));
        } catch (Throwable $e) {
            Log::error('Contact message could not be sent', ['error' => $e::class]);

            throw new BusinessRuleException('Your message could not be sent right now. Please try again, or email us directly.');
        }
    }
}
