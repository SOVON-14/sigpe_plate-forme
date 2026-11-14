<?php

use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountRoleController;
use App\Http\Controllers\Api\V1\DelegationController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Middleware\AuthenticateFunctionalAccount;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'identity-service',
        'version' => '1.0.0-draft',
    ]))->withoutMiddleware(CorrelationIdMiddleware::class);

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateFunctionalAccount::class])->group(function () {
        Route::get('/me/permissions', [AccessController::class, 'permissions']);

        Route::middleware(EnsurePermission::class . ':identity.accounts.read')->group(function () {
            Route::get('/accounts', [AccountController::class, 'index']);
            Route::get('/accounts/{account}', [AccountController::class, 'show']);
        });

        Route::middleware(EnsurePermission::class . ':identity.accounts.manage')->group(function () {
            Route::post('/accounts', [AccountController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/accounts/{account}/status', [AccountController::class, 'changeStatus'])
                ->middleware(IdempotentRequest::class);
            Route::get('/accounts/{account}/roles', [AccountRoleController::class, 'index']);
            Route::post('/accounts/{account}/roles', [AccountRoleController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::delete('/accounts/{account}/roles/{assignment}', [AccountRoleController::class, 'destroy']);
        });

        Route::middleware(EnsurePermission::class . ':identity.roles.read')->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);
            Route::get('/roles/{role}', [RoleController::class, 'show']);
            Route::get('/permissions', [PermissionController::class, 'index']);
        });

        Route::middleware(EnsurePermission::class . ':identity.delegations.read')->group(function () {
            Route::get('/delegations', [DelegationController::class, 'index']);
        });

        Route::middleware(EnsurePermission::class . ':identity.delegations.manage')->group(function () {
            Route::post('/delegations', [DelegationController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/delegations/{delegation}/revoke', [DelegationController::class, 'revoke'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
