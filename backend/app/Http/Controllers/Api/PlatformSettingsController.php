<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformSettingsRequest;
use App\Http\Requests\TestMailRequest;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The super admin's platform settings: Google sign-in keys, the SMTP server and the landing page's contact details. */
class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->success(PlatformSettings::forAdmin());
    }

    public function update(PlatformSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        // switching Google sign-in on needs a secret, either sent now or saved before
        if (($data['google']['enabled'] ?? false) && blank($data['google']['client_secret'] ?? null) && blank(PlatformSettings::get('google.client_secret'))) {
            throw ValidationException::withMessages(['google.client_secret' => ['Client secret is required to turn on Google sign-in.']]);
        }

        PlatformSettings::save($data);

        return $this->success(PlatformSettings::forAdmin(), 'Settings saved');
    }

    /** Sends a test email through the saved SMTP server, so the super admin can see the settings work. */
    public function testMail(TestMailRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! PlatformSettings::smtpEnabled()) {
            return response()->json(['success' => false, 'message' => 'Turn on SMTP and save the settings first.'], 422);
        }

        PlatformSettings::applyMail();
        Mail::purge('smtp'); // build the mailer again from the settings just applied

        try {
            Mail::mailer('smtp')->raw('This is a test email from '.config('app.name').'. Your SMTP settings work.', fn ($m) => $m->to($data['to'])->subject('SMTP test'));
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not send: '.$e->getMessage()], 422);
        }

        return $this->success(null, 'Test email sent to '.$data['to']);
    }
}
