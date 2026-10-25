<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Models\User;
use App\Support\GoogleOAuth;
use App\Support\PermissionCatalog;
use App\Support\PlatformSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Email is required.',
            'password.required' => 'Password is required.',
        ]);

        $user = User::with('shop')->where('email', $credentials['email'])->first();

        // hash the password even when there is no such user, so the answer takes as long and cannot reveal which emails have accounts
        $valid = Hash::check($credentials['password'], $user->password ?? self::dummyHash());

        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        return $this->signIn($user);
    }

    /** What the sign-in and sign-up pages need: whether sign-up is open, and the Google button. The Google client ID is public; the secret never leaves the server. */
    public function config(): JsonResponse
    {
        $google = PlatformSettings::googleEnabled();

        return $this->success([
            'signup_enabled' => (bool) PlatformSettings::get('signup.enabled'),
            'google' => ['enabled' => $google, 'client_id' => $google ? PlatformSettings::get('google.client_id') : null],
        ]);
    }

    /** Signs in with Google. Only existing accounts can sign in this way; new shops use the sign-up page. */
    public function google(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $person = GoogleOAuth::person($request->input('code'));
        $user = User::with('shop')->where('email', $person['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => ['No account uses '.$person['email'].'. Create an account, or ask your shop owner to add you.']]);
        }

        return $this->signIn($user);
    }

    /** Hands out a token once the account and its shop are both active. */
    private function signIn(User $user): JsonResponse
    {
        // a signed-up shop is off until approved: say why, instead of "disabled"
        if ($user->shop?->isPending()) {
            throw ValidationException::withMessages(['email' => ['Your shop is waiting for approval. You can sign in once it is approved.']]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['This account has been disabled. Contact your shop owner.']]);
        }

        if ($user->shop_id && ! $user->shop->is_active) {
            throw ValidationException::withMessages(['email' => ['This shop is currently disabled. Contact support.']]);
        }

        return $this->success([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->userData($user),
        ], 'Logged in');
    }

    /** Emails a reset link. The answer is the same whether or not the address has an account, so it cannot be used to find accounts. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']], ['email.required' => 'Email is required.', 'email.email' => 'Enter a valid email address.']);

        Password::sendResetLink($request->only('email'));

        return $this->success(null, 'If that email has an account, a reset link is on its way.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // signed out everywhere
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['This reset link is invalid or has expired. Ask for a new one.']]);
        }

        return $this->success(null, 'Password changed. You can sign in now.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->userData($request->user()->load('shop')));
    }

    /** Lets any signed-in user change their own name, email and (with the current password) password. */
    public function updateProfile(ProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->only(['name', 'email']));

        if ($request->filled('password')) {
            $user->password = $request->input('password');
            $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete(); // other devices are signed out
        }

        $user->save();

        return $this->success($this->userData($user->load('shop')), 'Profile updated');
    }

    /** A hash made with the app's current hashing cost, to compare against when the email is unknown. */
    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(Str::random(32));
    }

    private function userData(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role', 'photo_url']) + [
            'role_label' => PermissionCatalog::ROLES[$user->role] ?? $user->role,
            'shop' => $user->shop?->only(['id', 'name']),
            'permissions' => $user->permissionKeys(),
        ];
    }
}
