<?php

use App\Http\Controllers\Api\V1\InterventionController;
use App\Http\Controllers\Api\V1\InterventionPlanController;
use App\Http\Controllers\Api\V1\MaintenanceRequestController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'maintenance-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':maintenance.read')->group(function () {
            Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'index']);
            Route::get('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show']);
            Route::get('/maintenance-requests/{maintenanceRequest}/history', [MaintenanceRequestController::class, 'history']);
            Route::get('/intervention-plans', [InterventionPlanController::class, 'index']);
            Route::get('/intervention-plans/{plan}', [InterventionPlanController::class, 'show']);
            Route::get('/interventions', [InterventionController::class, 'index']);
            Route::get('/interventions/{intervention}', [InterventionController::class, 'show']);
            Route::get('/interventions/{intervention}/history', [InterventionController::class, 'history']);
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('/spare-parts', [StockController::class, 'partsIndex']);
            Route::get('/spare-parts/{part}', [StockController::class, 'partShow']);
            Route::get('/warehouses', [StockController::class, 'warehousesIndex']);
            Route::get('/stock-movements', [StockController::class, 'movementsIndex']);
        });

        Route::middleware(EnsurePermission::class.':maintenance.requests.manage')->group(function () {
            Route::post('/maintenance-requests', [MaintenanceRequestController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/maintenance-requests/{maintenanceRequest}/submit', [MaintenanceRequestController::class, 'submit'])
                ->middleware(IdempotentRequest::class);
            Route::post('/maintenance-requests/{maintenanceRequest}/cancel', [MaintenanceRequestController::class, 'cancel'])
                ->middleware(IdempotentRequest::class);
            Route::post('/intervention-plans', [InterventionPlanController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/intervention-plans/{plan}/execute', [InterventionPlanController::class, 'execute'])
                ->middleware(IdempotentRequest::class);
            Route::post('/interventions', [InterventionController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/interventions/{intervention}/start', [InterventionController::class, 'start'])
                ->middleware(IdempotentRequest::class);
            Route::post('/interventions/{intervention}/complete', [InterventionController::class, 'complete'])
                ->middleware(IdempotentRequest::class);
            Route::post('/interventions/{intervention}/interrupt', [InterventionController::class, 'interrupt'])
                ->middleware(IdempotentRequest::class);
            Route::post('/interventions/{intervention}/cancel', [InterventionController::class, 'cancel'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':maintenance.suppliers.manage')->group(function () {
            Route::post('/suppliers', [SupplierController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/suppliers/{supplier}/evaluate', [SupplierController::class, 'evaluate'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':maintenance.stock.manage')->group(function () {
            Route::post('/warehouses', [StockController::class, 'warehouseStore'])
                ->middleware(IdempotentRequest::class);
            Route::post('/spare-parts', [StockController::class, 'partStore'])
                ->middleware(IdempotentRequest::class);
            Route::post('/stock-movements', [StockController::class, 'movementStore'])
                ->middleware(IdempotentRequest::class);
        });
    });
});
