<?php

use App\Http\Controllers\Api\V1\CostController;
use App\Http\Controllers\Api\V1\DepreciationController;
use App\Http\Controllers\Api\V1\DisposalController;
use App\Http\Controllers\Api\V1\ValuationController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'finance-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':finance.read')->group(function () {
            Route::get('/asset-valuations', [ValuationController::class, 'index']);
            Route::get('/asset-valuations/{valuation}', [ValuationController::class, 'show']);
            Route::get('/assets/{assetId}/valuation', [ValuationController::class, 'assetView']);
            Route::get('/assets/{assetId}/depreciation-entries', [ValuationController::class, 'depreciationIndex']);
            Route::get('/asset-costs', [CostController::class, 'index']);
            Route::get('/asset-costs/{cost}', [CostController::class, 'show']);
            Route::get('/assets/{assetId}/costs', [CostController::class, 'assetSummary']);
            Route::get('/disposals', [DisposalController::class, 'index']);
            Route::get('/disposals/{disposal}', [DisposalController::class, 'show']);
            Route::get('/disposals/{disposal}/history', [DisposalController::class, 'history']);
        });

        Route::middleware(EnsurePermission::class.':finance.manage')->group(function () {
            Route::post('/asset-valuations', [ValuationController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/assets/{assetId}/depreciation-entries', [DepreciationController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/asset-costs', [CostController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/disposals', [DisposalController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/disposals/{disposal}/submit', [DisposalController::class, 'submit'])
                ->middleware(IdempotentRequest::class);
            Route::post('/disposals/{disposal}/execute', [DisposalController::class, 'execute'])
                ->middleware(IdempotentRequest::class);
            Route::post('/disposals/{disposal}/close', [DisposalController::class, 'close'])
                ->middleware(IdempotentRequest::class);
            Route::post('/disposals/{disposal}/resolve-investigation', [DisposalController::class, 'resolveInvestigation'])
                ->middleware(IdempotentRequest::class);
            Route::post('/disposals/{disposal}/cancel', [DisposalController::class, 'cancel'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
