<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuthUserResource;
use App\Http\Traits\ApiResponse;
use App\Models\User;

abstract class Controller
{
    use ApiResponse;

    /** Signs the user in: a new API token and who they are. Callers check the account and shop are active first. */
    protected function session(User $user): array
    {
        return ['token' => $user->createToken('api')->plainTextToken, 'user' => AuthUserResource::make($user)];
    }
}
