<?php

use App\Exceptions\DomainException;
use App\Support\ProblemResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (DomainException $e, Request $request) {
            $status = match ($e->errorCode) {
                'UNAUTHENTICATED', 'TOKEN_INVALID', 'TOKEN_EXPIRED' => 401,
                'FORBIDDEN' => 403,
                'ASSET_NOT_FOUND' => 404,
                'VALUE_UNKNOWN_REQUIRES_JUSTIFICATION',
                'ASSET_NO_CHANGE', 'ASSET_STATE_UNCHANGED',
                'ASSET_STATUS_UNCHANGED', 'ASSET_EXIT_REQUIRES_REFORM',
                'ASSET_ALREADY_IN_INSTITUTION', 'ASSET_NOT_TRANSFERABLE',
                'ASSET_SUBCATEGORY_MISMATCH', 'ASSET_SUBCATEGORY_FOREIGN',
                'IDEMPOTENCY_CONFLICT' => 409,
                default => 422,
            };

            return ProblemResponse::fromStatus(
                $status,
                $e->errorCode,
                $e->getMessage(),
                $request->header('X-Correlation-ID'),
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ProblemResponse::make(
                422,
                'Erreur de validation',
                'Un ou plusieurs champs sont invalides.',
                'VALIDATION_FAILED',
                $request->header('X-Correlation-ID'),
                ['errors' => $e->errors()],
            );
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ProblemResponse::fromStatus(
                404,
                'NOT_FOUND',
                'Ressource introuvable.',
                $request->header('X-Correlation-ID'),
            );
        });
    })->create();
