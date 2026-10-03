<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureShopUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api'); // the 120-a-minute limit above, on every API route
        $middleware->alias([
            'role' => EnsureRole::class,
            'permission' => CheckPermission::class,
            'shop' => EnsureShopUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $fail = fn (string $message, int $status, ?array $errors = null) => response()->json(
            array_filter(['success' => false, 'message' => $message, 'errors' => $errors], fn ($v) => $v !== null),
            $status,
        );
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(fn (ValidationException $e, Request $r) => $isApi($r)
            ? $fail('Validation failed', 422, $e->errors()) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $isApi($r)
            ? $fail('Unauthenticated', 401) : null);
        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $r) => $isApi($r)
            ? $fail('Resource not found', 404) : null);
        $exceptions->render(fn (BusinessRuleException $e, Request $r) => $isApi($r)
            ? $fail($e->getMessage(), 409) : null);
        $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => $isApi($r)
            ? $fail($e->getMessage() ?: 'Request failed', $e->getStatusCode()) : null);
        $exceptions->render(fn (Throwable $e, Request $r) => $isApi($r)
            ? $fail(config('app.debug') ? $e->getMessage() : 'Server error', 500) : null);
    })->create();
