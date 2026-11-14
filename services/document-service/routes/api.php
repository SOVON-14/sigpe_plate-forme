<?php

use App\Http\Controllers\Api\V1\AccessRequestController;
use App\Http\Controllers\Api\V1\DocumentCategoryController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'document-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':document.read')->group(function () {
            Route::get('/document-categories', [DocumentCategoryController::class, 'index']);
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::get('/documents/{document}', [DocumentController::class, 'show']);
            Route::get('/documents/{document}/versions', [DocumentController::class, 'versions']);
            Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
            Route::get('/documents/{document}/access-requests', [DocumentController::class, 'accessRequests']);
            Route::get('/documents/{document}/access-log', [DocumentController::class, 'accessLog']);
        });

        Route::middleware(EnsurePermission::class.':document.manage')->group(function () {
            Route::post('/documents', [DocumentController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/documents/{document}/versions', [DocumentController::class, 'addVersion'])
                ->middleware(IdempotentRequest::class);
            Route::post('/documents/{document}/validate', [DocumentController::class, 'validate'])
                ->middleware(IdempotentRequest::class);
            Route::post('/documents/{document}/lifecycle', [DocumentController::class, 'lifecycle'])
                ->middleware(IdempotentRequest::class);
            Route::post('/documents/{document}/access-requests', [DocumentController::class, 'createAccessRequest'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':document.decide')->group(function () {
            Route::post('/access-requests/{accessRequest}/decision', [AccessRequestController::class, 'decision'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
