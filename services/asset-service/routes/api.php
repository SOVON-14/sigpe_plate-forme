<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'asset-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class . ':asset.read')->group(function () {
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::get('/categories/{category}/subcategories', [CategoryController::class, 'subcategories']);
            Route::get('/institutions/{institutionId}/assets', [AssetController::class, 'index']);
            Route::get('/assets/{asset}', [AssetController::class, 'show']);
            Route::get('/assets/{asset}/histories', [AssetController::class, 'histories']);
        });

        Route::middleware(EnsurePermission::class . ':asset.manage')->group(function () {
            Route::post('/institutions/{institutionId}/assets', [AssetController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::patch('/assets/{asset}', [AssetController::class, 'update'])
                ->middleware(IdempotentRequest::class);
            Route::post('/assets/{asset}/status', [AssetController::class, 'changeStatus'])
                ->middleware(IdempotentRequest::class);
            Route::post('/assets/{asset}/physical-state', [AssetController::class, 'changePhysicalState'])
                ->middleware(IdempotentRequest::class);
            Route::post('/categories/{category}/subcategories', [CategoryController::class, 'storeSubcategory'])
                ->middleware(IdempotentRequest::class);
        });

        // TEC-017 : endpoint machine-to-machine Movement → Asset.
        // Permission dédiée asset.rattachement.confirm.
        Route::post('/assets/{asset}/rattachement', [AssetController::class, 'confirmRattachement'])
            ->middleware([EnsurePermission::class . ':asset.rattachement.confirm', IdempotentRequest::class]);
    });
});
