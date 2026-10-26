<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopService;
use App\Support\GoogleOAuth;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public sign-up: a new shop with its owner. With email and password the shop starts switched off and waits until the
 * super admin approves it (switches it on in Shops). With Google, whose email is proven, the shop opens at once and the
 * owner is signed in.
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
        'email.unique' => 'This email already has an account. Sign in instead.',
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], self::MESSAGES);

        $shop = $this->shops->create(
            ['name' => $data['shop_name'], 'phone' => $data['phone'], 'is_active' => false, 'approved_at' => null],
            ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'is_active' => false],
        );

        $this->tellSuperAdmins($shop);

        return $this->success(null, 'Account created. Your shop is waiting for approval; you can sign in once it is approved.', 201);
    }

    /** Sign-up with Google in place of a password: the name and email come from the Google account. */
    public function google(Request $request): JsonResponse
    {
        $this->ensureOpen();

        $data = $request->validate(self::SHOP_RULES + ['code' => ['required', 'string']], self::MESSAGES);
        $person = GoogleOAuth::person($data['code']);

        if (User::where('email', $person['email'])->exists()) {
            throw ValidationException::withMessages(['email' => [$person['email'].' already has an account. Sign in instead.']]);
        }

        // Google has proved the email is theirs, so the shop opens at once and they are signed in.
        // The account signs in with Google; the random password only fills the column (a reset link can set a real one).
        $shop = $this->shops->create(
            ['name' => $data['shop_name'], 'phone' => $data['phone'], 'is_active' => true, 'approved_at' => now()],
            ['name' => $person['name'], 'email' => $person['email'], 'password' => Str::random(40), 'is_active' => true],
        );
        $owner = $shop->owner;
        $owner->forceFill(['email_verified_at' => now()])->save();

        $this->tellSuperAdmins($shop, waiting: false);

        return $this->success($owner->load('shop')->newSession(), 'Welcome! Your shop is ready.', 201);
    }

    private function ensureOpen(): void
    {
        if (! PlatformSettings::get('signup.enabled')) {
            abort(403, 'Sign-up is closed. Contact support to open an account.');
        }
    }

    /** Emails the super admins about a new shop: one waiting for approval, or (Google sign-up) one already open. A mail failure must not lose the sign-up, so it is only logged. */
    private function tellSuperAdmins(Shop $shop, bool $waiting = true): void
    {
        $to = User::where('role', 'super_admin')->where('is_active', true)->pluck('email')->all();
        if (! $to) {
            return;
        }

        $owner = $shop->owner;
        $details = "Shop: {$shop->name}\nPhone: {$shop->phone}\nOwner: {$owner->name} <{$owner->email}>\n\n";
        $url = rtrim(config('app.frontend_url'), '/').'/shops';
        [$subject, $body] = $waiting
            ? ['New shop waiting for approval: ', "A new shop signed up and is waiting for approval.\n\n{$details}Approve it in Shops: edit the shop and tick \"Shop is active\".\n$url"]
            : ['New shop signed up with Google: ', "A new shop signed up with Google and is open already.\n\n{$details}To stop it, edit the shop in Shops and untick \"Shop is active\".\n$url"];

        rescue(fn () => Mail::raw($body, fn ($m) => $m->to($to)->subject($subject.$shop->name)));
    }
}
