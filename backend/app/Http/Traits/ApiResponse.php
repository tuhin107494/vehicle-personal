<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    /** Rows per page for a list: what the caller asks for (default 15). It must be a whole number from 1 to 100, so one request cannot pull the whole table. */
    protected function perPage(Request $request, int $default = 15): int
    {
        $request->validate(
            ['per_page' => ['nullable', 'integer', 'min:1', 'max:100']],
            ['per_page.integer' => 'Rows per page must be a whole number.', 'per_page.min' => 'Rows per page must be at least 1.', 'per_page.max' => 'Rows per page cannot be more than 100.'],
        );

        return $request->integer('per_page', $default);
    }

    protected function success(mixed $data = null, string $message = 'OK', int $status = 200, ?array $meta = null): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    protected function paginated(LengthAwarePaginator $paginator, string $resource, string $message = 'OK', array $extra = []): JsonResponse
    {
        return $this->success($resource::collection($paginator->getCollection()), $message, 200, [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            ...$extra,
        ]);
    }

    protected function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
