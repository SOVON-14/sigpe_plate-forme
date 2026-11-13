<?php

use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\FindingController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'inventory-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':inventory.campaigns.read')->group(function () {
            Route::get('/institutions/{institutionId}/inventory-campaigns', [CampaignController::class, 'index']);
            Route::get('/inventory-campaigns/{campaign}', [CampaignController::class, 'show']);
            Route::get('/inventory-campaigns/{campaign}/agents', [CampaignController::class, 'agents']);
            Route::get('/inventory-campaigns/{campaign}/findings', [CampaignController::class, 'findings']);
            Route::get('/inventory-campaigns/{campaign}/report', [CampaignController::class, 'report'])
                ->middleware(EnsurePermission::class.':inventory.reports.read');
            Route::get('/findings/{finding}', [FindingController::class, 'show']);
        });

        Route::middleware(EnsurePermission::class.':inventory.campaigns.manage')->group(function () {
            Route::post('/institutions/{institutionId}/inventory-campaigns', [CampaignController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/inventory-campaigns/{campaign}/status', [CampaignController::class, 'changeStatus'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':inventory.agents.manage')->group(function () {
            Route::post('/inventory-campaigns/{campaign}/agents', [CampaignController::class, 'assignAgent'])
                ->middleware(IdempotentRequest::class);
            Route::delete('/inventory-campaigns/{campaign}/agents/{agent}', [CampaignController::class, 'unassignAgent'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':inventory.findings.record')->group(function () {
            Route::post('/inventory-campaigns/{campaign}/findings', [CampaignController::class, 'recordFinding'])
                ->middleware(IdempotentRequest::class);
        });

        Route::post('/findings/{finding}/verify', [FindingController::class, 'verify'])
            ->middleware([EnsurePermission::class.':inventory.findings.verify', IdempotentRequest::class]);
    });
});
