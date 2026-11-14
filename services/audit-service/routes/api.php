<?php

use App\Http\Controllers\Api\V1\AuditEntryController;
use App\Http\Controllers\Api\V1\IntegrityController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'audit-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':audit.read')->group(function () {
            Route::get('/audit-entries', [AuditEntryController::class, 'index']);
            Route::get('/audit-entries/chain/verify', [IntegrityController::class, 'verify'])
                ->middleware(EnsurePermission::class.':audit.verify');
            Route::get('/audit-entries/stats', [AuditEntryController::class, 'stats']);
            Route::get('/audit-entries/{auditEntry}', [AuditEntryController::class, 'show']);
        });
    });
});
