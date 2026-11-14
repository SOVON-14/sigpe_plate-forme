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
                'FORBIDDEN', 'REQUEST_NOT_CANCELLABLE', 'DELIVERY_NOT_RECEIVABLE',
                'ITEM_NOT_IN_REQUEST', 'ASSET_NOT_IN_OPERATION' => 403,
                'REQUEST_NOT_FOUND', 'DELIVERY_NOT_FOUND' => 404,
                'REQUEST_NOT_SUBMITTABLE', 'REQUEST_NOT_VALIDATED',
                'REQUEST_NOT_SELECTED', 'REQUEST_NOT_RECEIVABLE',
                'REQUEST_NOT_RECEIVED', 'REQUEST_ASSETS_PENDING',
                'WORKFLOW_OUTCOME_UNKNOWN', 'IDEMPOTENCY_CONFLICT' => 409,
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
