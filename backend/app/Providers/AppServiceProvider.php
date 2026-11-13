<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Support\PlatformSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // text messages: only the log driver exists so far; a provider driver is added here, chosen by services.sms.driver
        $this->app->bind(SmsSender::class, LogSmsSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // N+1 guard: outside production, loading a relation one row at a time in a loop throws, so a missing with() is caught while developing and in tests
        Model::preventLazyLoading(! $this->app->isProduction());

        // 5 sign-in tries a minute for each email + address, so passwords cannot be guessed in bulk
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        // a new confirmation link: 3 a minute for each email + address, so nobody can flood an inbox with them
        RateLimiter::for('signup-resend', fn (Request $request) => Limit::perMinute(3)->by('resend|'.strtolower((string) $request->input('email')).'|'.$request->ip()));
        // the contact form sends real mail: 3 a minute and 10 an hour for each address, so nobody can flood the support inbox
        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perMinute(3)->by('contact-minute|'.$request->ip()),
            Limit::perHour(10)->by('contact-hour|'.$request->ip()),
        ]);
        // starting a payment talks to a gateway: 10 a minute for each shop and 20 for each address, so a loop or a script cannot flood it
        RateLimiter::for('payment-initiate', fn (Request $request) => [
            Limit::perMinute(10)->by('pay-shop|'.($request->user()?->shop_id ?: $request->ip())),
            Limit::perMinute(20)->by('pay-ip|'.$request->ip()),
        ]);
        // gateways call back: 120 a minute for each address, on top of the signature check
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(120)->by('webhook|'.$request->ip()));
        // every other request: 120 a minute for each signed-in user (or address)
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // the reset link in the email opens the web app's reset page, not the API
        // mail goes through the super admin's SMTP server when one is switched on; checked only when something is about to send mail
        $this->app->resolving('mail.manager', fn () => PlatformSettings::applyMail());

        // nobody reads the noreply address, so out-of-office and similar robots must not answer it
        Event::listen(MessageSending::class, fn (MessageSending $event) => $event->message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated'));

        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/').'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));
    }
}
