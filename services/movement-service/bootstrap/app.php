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
                'FORBIDDEN', 'CUSTODY_ALREADY_CLOSED', 'OPERATION_NOT_TRANSFER',
                'OPERATION_NOT_LOAN', 'OPERATION_NOT_INTERMINISTERIAL',
                'ASSET_NOT_IN_OPERATION' => 403,
                'OPERATION_NOT_FOUND', 'CUSTODY_NOT_FOUND', 'SHARED_RIGHT_NOT_FOUND' => 404,
                'OPERATION_NOT_SUBMITTABLE', 'OPERATION_NOT_AWAITING_DEPARTURE',
                'OPERATION_NOT_AWAITING_RECEPTION', 'OPERATION_NOT_AWAITING_ASSET',
                'OPERATION_NOT_ACTIVE', 'OPERATION_NOT_CANCELLABLE',
                'OPERATION_ASSET_CONFIRMATION_PENDING', 'OPERATION_INCIDENT_OPEN',
                'SHARED_RIGHT_ALREADY_ACTIVE', 'SHARED_RIGHT_INVALID_TRANSITION',
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
