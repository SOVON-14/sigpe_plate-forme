<?php

use App\Http\Controllers\Api\V1\CustodyController;
use App\Http\Controllers\Api\V1\OperationController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'movement-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':movement.read')->group(function () {
            Route::get('/custody', [CustodyController::class, 'index']);
            Route::get('/operations', [OperationController::class, 'index']);
            Route::get('/operations/{operation}', [OperationController::class, 'show']);
            Route::get('/operations/{operation}/history', [OperationController::class, 'history']);
            Route::get('/assets/{assetId}/movements', [OperationController::class, 'assetMovements']);
            Route::get('/shared-rights', [OperationController::class, 'sharedRightsIndex']);
        });

        Route::middleware(EnsurePermission::class.':movement.custody.manage')->group(function () {
            Route::post('/custody', [CustodyController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/custody/{custody}/close', [CustodyController::class, 'close'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':movement.operations.manage')->group(function () {
            Route::post('/operations', [OperationController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/operations/{operation}/submit', [OperationController::class, 'submit'])
                ->middleware(IdempotentRequest::class);
            Route::post('/operations/{operation}/confirm-departure', [OperationController::class, 'confirmDeparture'])
                ->middleware(IdempotentRequest::class);
            Route::post('/operations/{operation}/confirm-reception', [OperationController::class, 'confirmReception'])
                ->middleware(IdempotentRequest::class);
            Route::post('/operations/{operation}/return', [OperationController::class, 'returnLoan'])
                ->middleware(IdempotentRequest::class);
            Route::post('/operations/{operation}/incident', [OperationController::class, 'reportIncident'])
                ->middleware(IdempotentRequest::class);
            Route::post('/operations/{operation}/cancel', [OperationController::class, 'cancel'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':movement.shared_rights.manage')->group(function () {
            Route::post('/shared-rights', [OperationController::class, 'sharedRightsStore'])
                ->middleware(IdempotentRequest::class);
            Route::post('/shared-rights/{right}/suspend', [OperationController::class, 'sharedRightsSuspend'])
                ->middleware(IdempotentRequest::class);
            Route::post('/shared-rights/{right}/terminate', [OperationController::class, 'sharedRightsTerminate'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
