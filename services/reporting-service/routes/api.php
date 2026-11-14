<?php

use App\Http\Controllers\Api\V1\ReportingController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'reporting-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':reporting.read')->group(function () {
            Route::get('/dashboard', [ReportingController::class, 'dashboard']);
            Route::get('/assets', [ReportingController::class, 'searchAssets']);
            Route::get('/assets/{assetId}', [ReportingController::class, 'assetDetail']);
            Route::get('/indicators', [ReportingController::class, 'indicators']);
            Route::get('/views', [ReportingController::class, 'views']);
            Route::get('/reports/templates', [ReportingController::class, 'templates']);
            Route::get('/report-runs', [ReportingController::class, 'reportRuns']);
            Route::get('/report-runs/{reportRun}', [ReportingController::class, 'reportRun']);
            Route::get('/report-runs/{reportRun}/export', [ReportingController::class, 'exportReportRun']);
        });

        Route::middleware(EnsurePermission::class.':reporting.manage')->group(function () {
            Route::post('/dashboard/refresh', [ReportingController::class, 'refresh'])
                ->middleware(IdempotentRequest::class);
            Route::post('/views', [ReportingController::class, 'createView'])
                ->middleware(IdempotentRequest::class);
            Route::delete('/views/{view}', [ReportingController::class, 'deleteView']);
            Route::post('/reports/{templateCode}/run', [ReportingController::class, 'runReport'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
