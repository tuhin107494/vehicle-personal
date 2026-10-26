<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\ConfirmSignupEmail;
use App\Services\ShopService;
use App\Support\GoogleOAuth;
use App\Support\PlatformSettings;
use App\Support\SignupConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public sign-up: a new shop with its owner. Either way the email is proven before anyone gets in:
 * - email and password: the shop waits until the owner opens the link emailed to them, which opens it and signs them in;
 * - Google: Google has proven the email, so the shop opens at once and the owner is signed in.
 */
class SignupController extends Controller
{
    private const SHOP_RULES = [
        'shop_name' => ['required', 'string', 'max:255'],
        'phone' => ['required', 'string', 'max:30'],
    ];

    private const MESSAGES = [
        'name.required' => 'Your name is required.',
        'shop_name.required' => 'Shop name is required.',
        'phone.required' => 'Phone is required.',
        'email.required' => 'Email is required.',
        'email.email' => 'Enter a valid email address.',
        'password.required' => 'Password is required.',
        'password.min' => 'Password must be at least 8 characters.',
        'password.confirmed' => 'The two passwords do not match.',
    ];

    public function __construct(private ShopService $shops) {}

    public function store(Request $request): JsonResponse
    {
        $this->ensureOpen();

        $data = $request->validate(self::SHOP_RULES + [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], self::MESSAGES);

        if ($existing = User::with('shop')->where('email', $data['email'])->first()) {
            throw ValidationException::withMessages(['email' => [SignupConfirmation::awaiting($existing)
                ? 'This email is waiting for confirmation. Check your inbox, or ask for a new link.'
                : 'This email already has an account. Sign in instead.']]);
        }

        $shop = $this->shops->create(
            ['name' => $data['shop_name'], 'phone' => $data['phone'], 'is_active' => false, 'approved_at' => null],
            ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'is_active' => false],
        );

        $shop->owner->setRelation('shop', $shop)->notify(new ConfirmSignupEmail);

        return $this->success(['email' => $data['email']], 'Check your inbox: we sent a confirmation link to '.$data['email'].'.', 201);
    }

    /** The link from the confirmation email: proves the email, opens the shop and signs the owner in. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer'], 'expires' => ['required', 'integer'], 'token' => ['required', 'string']]);

        $user = SignupConfirmation::user((int) $data['id'], (int) $data['expires'], $data['token']);

        if (! $user) {
            throw ValidationException::withMessages(['token' => ['This confirmation link is invalid, has expired or was already used. Sign in, or ask for a new link.']]);
        }

        DB::transaction(function () use ($user) {
            $user->shop->update(['is_active' => true, 'approved_at' => now()]);
            $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
        });

        $this->tellSuperAdmins($user->shop, 'confirmed their email');

        return $this->success($user->newSession(), 'Email confirmed. Welcome! Your shop is ready.');
    }

    /** Sends the confirmation link again. The answer is the same for every email, so it cannot be used to find accounts. */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']], ['email.required' => 'Email is required.', 'email.email' => 'Enter a valid email address.']);

        $user = User::with('shop')->where('email', $data['email'])->first();

        if ($user && SignupConfirmation::awaiting($user)) {
            $user->notify(new ConfirmSignupEmail);
        }

        return $this->success(null, 'If '.$data['email'].' is waiting for confirmation, a new link is on its way.');
    }

    /** Sign-up with Google in place of a password: the name and email come from the Google account. */
    public function google(Request $request): JsonResponse
    {
        $this->ensureOpen();

        $data = $request->validate(self::SHOP_RULES + ['code' => ['required', 'string']], self::MESSAGES);
        $person = GoogleOAuth::person($data['code']);

        $existing = User::with('shop')->where('email', $person['email'])->first();

        if ($existing && ! SignupConfirmation::awaiting($existing)) {
            throw ValidationException::withMessages(['email' => [$person['email'].' already has an account. Sign in instead.']]);
        }

        // Google has proved the email is theirs, so the shop opens at once and they are signed in.
        // The account signs in with Google; the random password only fills the column (a reset link can set a real one).
        $shop = DB::transaction(function () use ($existing, $data, $person) {
            if ($existing) {
                // an unconfirmed email sign-up with this address: whoever made it never proved the email, the Google owner just did
                self::deleteSignup($existing->shop);
            }

            return $this->shops->create(
                ['name' => $data['shop_name'], 'phone' => $data['phone'], 'is_active' => true, 'approved_at' => now()],
                ['name' => $person['name'], 'email' => $person['email'], 'password' => Str::random(40), 'is_active' => true],
            );
        });
        $owner = $shop->owner;
        $owner->forceFill(['email_verified_at' => now()])->save();

        $this->tellSuperAdmins($shop, 'signed up with Google');

        return $this->success($owner->load('shop')->newSession(), 'Welcome! Your shop is ready.', 201);
    }

    /** Deletes a signed-up shop that never opened, with its owner (users are not removed with their shop on their own). */
    public static function deleteSignup(Shop $shop): void
    {
        DB::transaction(function () use ($shop) {
            $shop->users()->delete();
            $shop->delete();
        });
    }

    private function ensureOpen(): void
    {
        if (! PlatformSettings::get('signup.enabled')) {
            abort(403, 'Sign-up is closed. Contact support to open an account.');
        }
    }

    /** Emails the super admins about a shop that just opened. A mail failure must not lose the sign-up, so it is only logged. */
    private function tellSuperAdmins(Shop $shop, string $how): void
    {
        $to = User::where('role', 'super_admin')->where('is_active', true)->pluck('email')->all();
        if (! $to) {
            return;
        }

        $owner = $shop->owner;
        $body = "A new shop {$how} and is open.\n\nShop: {$shop->name}\nPhone: {$shop->phone}\nOwner: {$owner->name} <{$owner->email}>\n\n"
            ."To stop it, edit the shop in Shops and untick \"Shop is active\".\n".rtrim(config('app.frontend_url'), '/').'/shops';

        rescue(fn () => Mail::raw($body, fn ($m) => $m->to($to)->subject('New shop signed up: '.$shop->name)));
    }
}
