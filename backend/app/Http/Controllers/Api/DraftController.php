<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DraftRequest;
use App\Models\Draft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Half-finished purchases and sales. Anyone who may create that kind of record may keep and resume its drafts. */
class DraftController extends Controller
{
    private const PERMISSION = ['purchase' => 'purchases.create', 'sale' => 'sales.create'];

    public function index(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', 'in:purchase,sale']])['type'];
        $this->authorizeType($type);

        $drafts = Draft::with('creator:id,name')->where('type', $type)->latest('updated_at')->get();

        return $this->success($drafts->map(fn (Draft $d) => $this->present($d)));
    }

    public function store(DraftRequest $request): JsonResponse
    {
        $this->authorizeType($request->type);

        $draft = Draft::create($request->safe()->only(['type', 'label', 'data']) + ['created_by' => $request->user()->id]);

        return $this->success($this->present($draft->load('creator:id,name')), 'Draft saved', 201);
    }

    public function update(DraftRequest $request, Draft $draft): JsonResponse
    {
        $this->authorizeType($draft->type);

        $draft->update($request->safe()->only(['label', 'data']));

        return $this->success($this->present($draft->load('creator:id,name')), 'Draft saved');
    }

    public function destroy(Draft $draft): JsonResponse
    {
        $this->authorizeType($draft->type);

        $draft->delete();

        return $this->success(null, 'Draft deleted');
    }

    private function authorizeType(string $type): void
    {
        abort_unless(auth()->user()->hasPermission(self::PERMISSION[$type]), 403, 'You do not have permission to do this.');
    }

    private function present(Draft $d): array
    {
        return [
            'id' => $d->id, 'type' => $d->type, 'label' => $d->label, 'data' => $d->data,
            'saved_by' => $d->creator?->name, 'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
