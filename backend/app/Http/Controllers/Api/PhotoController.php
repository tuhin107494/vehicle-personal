<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PhotoRequest;
use App\Models\Customer;
use App\Models\Investor;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\Photos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Add, replace or remove the photo of a customer, supplier, investor or user. */
class PhotoController extends Controller
{
    private const MODELS = ['customers' => Customer::class, 'suppliers' => Supplier::class, 'investors' => Investor::class];

    public function store(PhotoRequest $request, string $id, string $type): JsonResponse
    {
        $model = $this->find($type, $id);
        $model->photo = Photos::store($request->file('photo'), $type, $model->photo);
        $model->save();

        return $this->success(['photo_url' => $model->photo_url], 'Photo saved');
    }

    public function destroy(string $id, string $type): JsonResponse
    {
        $model = $this->find($type, $id);
        Photos::delete($model->photo);
        $model->photo = null;
        $model->save();

        return $this->success(['photo_url' => null], 'Photo removed');
    }

    /** The signed-in user's own photo. */
    public function storeOwn(PhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->photo = Photos::store($request->file('photo'), 'users', $user->photo);
        $user->save();

        return $this->success(['photo_url' => $user->photo_url], 'Photo saved');
    }

    public function destroyOwn(Request $request): JsonResponse
    {
        $user = $request->user();
        Photos::delete($user->photo);
        $user->photo = null;
        $user->save();

        return $this->success(['photo_url' => null], 'Photo removed');
    }

    /** A shop owner sets the photo of an admin or moderator of their shop. */
    public function storeUser(PhotoRequest $request, User $user): JsonResponse
    {
        $this->authorizeUser($user);
        $user->photo = Photos::store($request->file('photo'), 'users', $user->photo);
        $user->save();

        return $this->success(['photo_url' => $user->photo_url], 'Photo saved');
    }

    public function destroyUser(User $user): JsonResponse
    {
        $this->authorizeUser($user);
        Photos::delete($user->photo);
        $user->photo = null;
        $user->save();

        return $this->success(['photo_url' => null], 'Photo removed');
    }

    private function authorizeUser(User $user): void
    {
        abort_unless($user->shop_id === auth()->user()->shop_id, 404, 'Resource not found');

        if (! in_array($user->role, PermissionCatalog::ASSIGNABLE_ROLES, true)) {
            throw new BusinessRuleException('Only admins and moderators can be edited here.');
        }
    }

    private function find(string $type, string $id)
    {
        abort_unless(isset(self::MODELS[$type]), 404);

        return self::MODELS[$type]::findOrFail($id); // the shop scope keeps this to the user's own shop
    }
}
