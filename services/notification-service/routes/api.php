<?php

use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'notification-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':notification.read')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
            Route::get('/notifications/{notification}/deliveries', [NotificationController::class, 'deliveries']);
            Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
        });

        Route::middleware(EnsurePermission::class.':notification.manage')->group(function () {
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
                ->middleware(IdempotentRequest::class);
            Route::post('/notifications/{notification}/acknowledge', [NotificationController::class, 'acknowledge'])
                ->middleware(IdempotentRequest::class);
            Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences']);
            Route::get('/notification-templates', [NotificationController::class, 'templates']);
        });
    });
});
