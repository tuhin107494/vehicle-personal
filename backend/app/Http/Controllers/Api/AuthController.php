<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Models\User;
use App\Support\PermissionCatalog;
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

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
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

    private function userData(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role']) + [
            'role_label' => PermissionCatalog::ROLES[$user->role] ?? $user->role,
            'shop' => $user->shop?->only(['id', 'name']),
            'permissions' => $user->permissionKeys(),
        ];
    }
}
