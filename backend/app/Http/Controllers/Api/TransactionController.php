<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The details of a customer payment, supplier payment or supplier advance, and their edit history. Permissions are set on the routes. */
class TransactionController extends Controller
{
    public function __construct(private TransactionService $transactions) {}

    public function show(string $type, int $id): JsonResponse
    {
        $tx = $this->transactions->find($type, $id);

        return $this->success(['values' => $this->transactions->values($tx), 'edits' => $this->transactions->history($type, $id)]);
    }

    public function update(UpdateTransactionRequest $request, string $type, int $id): JsonResponse
    {
        $tx = $this->transactions->find($type, $id);

        $this->transactions->update($tx, $request->validated(), $request->user());

        return $this->success(['values' => $this->transactions->values($tx->fresh()), 'edits' => $this->transactions->history($type, $id)], 'Transaction updated');
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $this->transactions->delete($this->transactions->find($type, $id), $request->user());

        return $this->success(null, 'Transaction deleted');
    }

    public function cancel(CancelTransactionRequest $request, string $type, int $id): JsonResponse
    {
        $this->transactions->cancel($this->transactions->find($type, $id), $request->validated('reason'), $request->user());

        return $this->success(null, 'Transaction cancelled');
    }
}
