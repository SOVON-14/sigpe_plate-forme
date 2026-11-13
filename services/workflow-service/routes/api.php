<?php

use App\Http\Controllers\Api\V1\InstanceController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'workflow-service',
        'version' => '1.0.0-draft',
    ]));

    Route::middleware([CorrelationIdMiddleware::class, AuthenticateUser::class])->group(function () {
        Route::middleware(EnsurePermission::class.':workflow.templates.read')->group(function () {
            Route::get('/workflows', [WorkflowController::class, 'index']);
            Route::get('/workflows/{workflow}', [WorkflowController::class, 'show']);
        });

        Route::middleware(EnsurePermission::class.':workflow.templates.manage')->group(function () {
            Route::post('/workflows', [WorkflowController::class, 'store'])
                ->middleware(IdempotentRequest::class);
        });

        Route::middleware(EnsurePermission::class.':workflow.instances.read')->group(function () {
            Route::get('/workflow-instances', [InstanceController::class, 'index']);
            Route::get('/workflow-instances/{instance}', [InstanceController::class, 'show']);
            Route::get('/workflow-instances/{instance}/tasks', [InstanceController::class, 'tasks']);
            Route::get('/tasks/{task}', [TaskController::class, 'show']);
        });

        Route::middleware(EnsurePermission::class.':workflow.instances.manage')->group(function () {
            Route::post('/workflow-instances', [InstanceController::class, 'store'])
                ->middleware(IdempotentRequest::class);
            Route::post('/workflow-instances/{instance}/cancel', [InstanceController::class, 'cancel'])
                ->middleware(IdempotentRequest::class);
        });

        Route::post('/tasks/{task}/decision', [TaskController::class, 'decide'])
            ->middleware([EnsurePermission::class.':workflow.tasks.decide', IdempotentRequest::class]);
    });
});
