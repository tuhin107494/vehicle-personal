<?php

namespace App\Http\Controllers\Api\Gateway\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gateway\ResolveMismatchRequest;
use App\Http\Resources\Gateway\ReconciliationMismatchResource;
use App\Models\Gateway\AdminAuditLog;
use App\Models\Gateway\ReconciliationMismatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the daily settlement check flagged, super admin only. Resolving one records who looked and what they found. */
class ReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = ReconciliationMismatch::with(['shop', 'resolver'])
            ->when($request->gateway, fn ($q, $g) => $q->where('gateway_code', $g))
            ->when($request->kind, fn ($q, $k) => $q->where('kind', $k))
            ->when($request->resolved === 'yes', fn ($q) => $q->whereNotNull('resolved_at'), fn ($q) => $q->whereNull('resolved_at'))
            ->latest('id')->paginate($this->perPage($request));

        return $this->paginated($rows, ReconciliationMismatchResource::class, 'OK', ['open_count' => ReconciliationMismatch::whereNull('resolved_at')->count()]);
    }

    public function resolve(ResolveMismatchRequest $request, ReconciliationMismatch $mismatch): JsonResponse
    {
        if ($mismatch->resolved_at) {
            throw new BusinessRuleException('This mismatch was already resolved.');
        }

        $mismatch->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id, 'note' => $request->validated('note')]);
        AdminAuditLog::record($request->user(), 'mismatch.resolved', $mismatch, [['field' => 'note', 'to' => $request->validated('note')]], $request->ip());

        return $this->success(ReconciliationMismatchResource::make($mismatch->load(['shop', 'resolver'])), 'Mismatch resolved');
    }
}
