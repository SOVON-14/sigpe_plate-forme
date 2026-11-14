<?php

use App\Http\Controllers\Api\V1\AcquisitionRequestController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'acquisition-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':acquisition.read')->group(function () {
            Route::get('/acquisition-requests', [AcquisitionRequestController::class, 'index']);
            Route::get('/acquisition-requests/{acquisition}', [AcquisitionRequestController::class, 'show']);
            Route::get('/acquisition-requests/{acquisition}/history', [AcquisitionRequestController::class, 'history']);
            Route::get('/deliveries/{delivery}/receptions', [DeliveryController::class, 'receptionsIndex']);
        });

        Route::middleware(EnsurePermission::class.':acquisition.requests.manage')->group(function () {
            Route::post('/acquisition-requests', [AcquisitionRequestController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/acquisition-requests/{acquisition}/submit', [AcquisitionRequestController::class, 'submit'])
                ->middleware(IdempotentRequest::class);
            Route::post('/acquisition-requests/{acquisition}/select', [AcquisitionRequestController::class, 'select'])
                ->middleware(IdempotentRequest::class);
            Route::post('/acquisition-requests/{acquisition}/order', [AcquisitionRequestController::class, 'order'])
                ->middleware(IdempotentRequest::class);
            Route::post('/acquisition-requests/{acquisition}/deliveries', [DeliveryController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/acquisition-requests/{acquisition}/close', [AcquisitionRequestController::class, 'close'])
                ->middleware(IdempotentRequest::class);
            Route::post('/acquisition-requests/{acquisition}/cancel', [AcquisitionRequestController::class, 'cancel'])
                ->middleware(IdempotentRequest::class);
            Route::post('/deliveries/{delivery}/receptions', [DeliveryController::class, 'recordReception'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
