<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The super admin's platform settings: Google sign-in keys and the SMTP server. */
class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->success(PlatformSettings::forAdmin());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signup' => ['sometimes', 'array'],
            'signup.enabled' => ['sometimes', 'boolean'],
            'google' => ['sometimes', 'array'],
            'google.enabled' => ['sometimes', 'boolean'],
            'google.client_id' => ['nullable', 'string', 'max:255', 'required_if_accepted:google.enabled'],
            'google.client_secret' => ['nullable', 'string', 'max:255'],
            'smtp' => ['sometimes', 'array'],
            'smtp.enabled' => ['sometimes', 'boolean'],
            'smtp.host' => ['nullable', 'string', 'max:255', 'required_if_accepted:smtp.enabled'],
            'smtp.port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp.encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'smtp.username' => ['nullable', 'string', 'max:255'],
            'smtp.password' => ['nullable', 'string', 'max:255'],
            'smtp.from_address' => ['nullable', 'email', 'max:255', 'required_if_accepted:smtp.enabled'],
            'smtp.from_name' => ['nullable', 'string', 'max:255'],
        ], [
            'google.client_id.required_if_accepted' => 'Client ID is required to turn on Google sign-in.',
            'smtp.host.required_if_accepted' => 'SMTP host is required to turn on SMTP.',
            'smtp.from_address.required_if_accepted' => 'From address is required to turn on SMTP.',
            'smtp.from_address.email' => 'Enter a valid email address.',
        ]);

        // switching Google sign-in on needs a secret, either sent now or saved before
        if (($data['google']['enabled'] ?? false) && blank($data['google']['client_secret'] ?? null) && blank(PlatformSettings::get('google.client_secret'))) {
            throw ValidationException::withMessages(['google.client_secret' => ['Client secret is required to turn on Google sign-in.']]);
        }

        PlatformSettings::save($data);

        return $this->success(PlatformSettings::forAdmin(), 'Settings saved');
    }

    /** Sends a test email through the saved SMTP server, so the super admin can see the settings work. */
    public function testMail(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email']], ['to.required' => 'Email is required.', 'to.email' => 'Enter a valid email address.']);

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
