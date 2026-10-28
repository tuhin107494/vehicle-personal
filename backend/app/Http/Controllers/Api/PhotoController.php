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
        $this->replace($model, Photos::store($request->file('photo'), $type));

        return $this->success(['photo_url' => $model->photo_url], 'Photo saved');
    }

    public function destroy(string $id, string $type): JsonResponse
    {
        $model = $this->find($type, $id);
        $this->replace($model, null);

        return $this->success(['photo_url' => null], 'Photo removed');
    }

    /** The signed-in user's own photo. */
    public function storeOwn(PhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->replace($user, Photos::store($request->file('photo'), 'users'));

        return $this->success(['photo_url' => $user->photo_url], 'Photo saved');
    }

    public function destroyOwn(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->replace($user, null);

        return $this->success(['photo_url' => null], 'Photo removed');
    }

    /** A shop owner sets the photo of an admin or moderator of their shop. */
    public function storeUser(PhotoRequest $request, User $user): JsonResponse
    {
        $this->authorizeUser($user);
        $this->replace($user, Photos::store($request->file('photo'), 'users'));

        return $this->success(['photo_url' => $user->photo_url], 'Photo saved');
    }

    public function destroyUser(User $user): JsonResponse
    {
        $this->authorizeUser($user);
        $this->replace($user, null);

        return $this->success(['photo_url' => null], 'Photo removed');
    }

    /** Points the row at the new photo (or none), and only then removes the old file; a failed save cleans up the new one instead. */
    private function replace(Customer|Supplier|Investor|User $model, ?string $path): void
    {
        $old = $model->photo;
        $model->photo = $path;

        try {
            $model->save();
        } catch (\Throwable $e) {
            Photos::delete($path);

            throw $e;
        }

        Photos::delete($old);
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
