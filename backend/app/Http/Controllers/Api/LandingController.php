<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Services\ContactService;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/** The public landing page: its contact details and contact form. No sign-in needed. */
class LandingController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->success(PlatformSettings::forLanding());
    }

    public function contact(ContactRequest $request, ContactService $contact): JsonResponse
    {
        $data = $request->validated();

        // only a bot fills the hidden field; it gets the normal answer, so it does not learn to skip it
        if (filled($data['leave_blank'] ?? null)) {
            Log::info('Contact message dropped: the hidden field was filled');

            return $this->success(null, 'Message sent');
        }

        $contact->send($data);

        return $this->success(null, 'Message sent');
    }
}
