<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The details of a customer payment, supplier payment or supplier advance, and their edit history. */
class TransactionController extends Controller
{
    public function __construct(private TransactionService $transactions) {}

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeEdit($request);
        $tx = $this->transactions->find($type, $id);

        return $this->success(['values' => $this->transactions->values($tx), 'edits' => $this->transactions->history($type, $id)]);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeEdit($request);
        $tx = $this->transactions->find($type, $id);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(['cash', 'bank', 'mobile'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'memo_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['paid_at.before_or_equal' => 'Date cannot be in the future.']);

        $this->transactions->update($tx, $data, $request->user());

        return $this->success(['values' => $this->transactions->values($tx->fresh()), 'edits' => $this->transactions->history($type, $id)], 'Transaction updated');
    }

    private function authorizeEdit(Request $request): void
    {
        abort_unless($request->user()->hasPermission('transactions.edit'), 403, 'You do not have permission to do this.');
    }
}
