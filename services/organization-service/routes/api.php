<?php

use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UnitTypeController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'organization-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class . ':organization.institutions.read')->group(function () {
            Route::get('/institutions', [InstitutionController::class, 'index']);
            Route::get('/institutions/{institution}', [InstitutionController::class, 'show']);
            Route::get('/institutions/{institution}/unit-types', [UnitTypeController::class, 'index']);
            Route::get('/institutions/{institution}/histories', [HistoryController::class, 'index']);
        });

        Route::middleware(EnsurePermission::class . ':organization.institutions.manage')->group(function () {
            Route::post('/institutions', [InstitutionController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/institutions/{institution}/status', [InstitutionController::class, 'changeStatus'])
                ->middleware(IdempotentRequest::class);
            Route::post('/institutions/{institution}/unit-types', [UnitTypeController::class, 'store'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class . ':organization.units.read')->group(function () {
            Route::get('/institutions/{institution}/units', [UnitController::class, 'index']);
            Route::get('/units/{unit}', [UnitController::class, 'show']);
            Route::get('/units/{unit}/positions', [PositionController::class, 'index']);
        });

        Route::middleware(EnsurePermission::class . ':organization.units.manage')->group(function () {
            Route::post('/institutions/{institution}/units', [UnitController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::patch('/units/{unit}', [UnitController::class, 'rename'])
                ->middleware(IdempotentRequest::class);
            Route::post('/units/{unit}/move', [UnitController::class, 'move'])
                ->middleware(IdempotentRequest::class);
            Route::post('/units/{unit}/status', [UnitController::class, 'changeStatus'])
                ->middleware(IdempotentRequest::class);
            Route::post('/units/{unit}/head', [PositionController::class, 'setUnitHead'])
                ->middleware(IdempotentRequest::class);
            Route::post('/units/{unit}/positions', [PositionController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/positions/{position}/responsible', [PositionController::class, 'assignResponsible'])
                ->middleware(IdempotentRequest::class);
            Route::delete('/positions/{position}/responsible', [PositionController::class, 'revokeResponsible'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
