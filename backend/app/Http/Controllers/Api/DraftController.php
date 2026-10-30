<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DraftRequest;
use App\Models\Draft;
use App\Services\DraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Half-finished purchases and sales. Anyone who may create that kind of record may keep and resume its drafts. */
class DraftController extends Controller
{
    private const PERMISSION = ['purchase' => 'purchases.create', 'sale' => 'sales.create'];

    public function __construct(private DraftService $drafts) {}

    public function index(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', 'in:purchase,sale']])['type'];
        $this->authorizeType($type);

        $drafts = Draft::with('creator:id,name')->where('type', $type)->latest('updated_at')->get();

        return $this->success($drafts->map(fn (Draft $d) => $d->present()));
    }

    public function store(DraftRequest $request): JsonResponse
    {
        $this->authorizeType($request->type);

        $draft = $this->drafts->save($request->safe()->only(['type', 'label', 'data']), $request->user());

        return $this->success($draft->load('creator:id,name')->present(), 'Draft saved', 201);
    }

    public function update(DraftRequest $request, Draft $draft): JsonResponse
    {
        $this->authorizeType($draft->type);

        $draft = $this->drafts->save($request->safe()->only(['label', 'data']), $request->user(), $draft);

        return $this->success($draft->load('creator:id,name')->present(), 'Draft saved');
    }

    public function destroy(Draft $draft): JsonResponse
    {
        $this->authorizeType($draft->type);

        $this->drafts->delete($draft);

        return $this->success(null, 'Draft deleted');
    }

    private function authorizeType(string $type): void
    {
        abort_unless(auth()->user()->hasPermission(self::PERMISSION[$type]), 403, 'You do not have permission to do this.');
    }
}
