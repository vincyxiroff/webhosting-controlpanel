<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConsistencyController;
use App\Http\Controllers\BillingWebhookController;
use App\Http\Controllers\EnterpriseController;
use App\Http\Controllers\NodeController;
use App\Http\Controllers\OperationJournalController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StabilityController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/billing/fossbilling/webhook', [BillingWebhookController::class, 'receive']);

    Route::middleware(['auth:api', 'tenant.context', 'audit'])->group(function (): void {
        Route::apiResource('tenants', TenantController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('plans', PlanController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('nodes', NodeController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('/nodes/{node}/drain', [NodeController::class, 'drain']);
        Route::post('/nodes/{node}/migrate-sites', [NodeController::class, 'migrateSites']);
        Route::apiResource('sites', SiteController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::post('/sites/{site}/suspend', [SiteController::class, 'suspend']);
        Route::post('/sites/{site}/restore', [SiteController::class, 'restore']);
        Route::post('/sites/{site}/deployments', [SiteController::class, 'deploy']);
        Route::post('/sites/{site}/vhost/preview', [SiteController::class, 'previewVhost']);
        Route::post('/sites/{site}/ssl/orders', [SiteController::class, 'orderCertificate']);

        Route::get('/scheduler/placement-decisions', [EnterpriseController::class, 'placementDecisions']);
        Route::post('/metering/samples', [EnterpriseController::class, 'recordUsage']);
        Route::post('/metering/aggregate', [EnterpriseController::class, 'aggregateUsage']);
        Route::post('/sites/{site}/enforcement/evaluate', [EnterpriseController::class, 'enforceSite']);
        Route::post('/ha/failover/detect', [EnterpriseController::class, 'detectFailover']);
        Route::post('/ha/sites/{site}/recover', [EnterpriseController::class, 'recoverSite']);
        Route::post('/storage/policies', [EnterpriseController::class, 'createStoragePolicy']);
        Route::post('/storage/snapshots', [EnterpriseController::class, 'queueStorageSnapshot']);
        Route::post('/edge/routes', [EnterpriseController::class, 'publishEdgeRoute']);
        Route::post('/edge/reroute', [EnterpriseController::class, 'rerouteEdge']);
        Route::post('/security/score', [EnterpriseController::class, 'scoreSecurity']);
        Route::post('/consistency/run', [ConsistencyController::class, 'run']);
        Route::get('/consistency/drifts', [ConsistencyController::class, 'drifts']);
        Route::get('/consistency/jobs', [ConsistencyController::class, 'jobs']);
        Route::post('/billing/usage/aggregate', [BillingController::class, 'aggregate']);
        Route::post('/billing/enforce', [BillingController::class, 'enforce']);
        Route::post('/billing/events/process', [BillingController::class, 'events']);
        Route::get('/billing/enforcement-decisions', [BillingController::class, 'decisions']);
        Route::get('/stability/sites/{site}', [StabilityController::class, 'site']);
        Route::get('/stability/transitions', [StabilityController::class, 'transitions']);
        Route::get('/stability/conflicts', [StabilityController::class, 'conflicts']);
        Route::get('/stability/locks', [StabilityController::class, 'locks']);
        Route::get('/operation-journal', [OperationJournalController::class, 'index']);
        Route::get('/operation-journal/sites/{site}', [OperationJournalController::class, 'site']);
        Route::post('/operation-journal/sites/{site}/snapshot', [OperationJournalController::class, 'snapshot']);
    });
});

Route::prefix('agent/v1')->group(function (): void {
    Route::post('/register', [AgentController::class, 'register']);
    Route::post('/heartbeat', [AgentController::class, 'heartbeat']);
    Route::post('/command/pull', [AgentController::class, 'pull']);
    Route::post('/command/{command}/result', [AgentController::class, 'result']);
    Route::post('/site/create', [AgentController::class, 'siteCreate']);
    Route::post('/site/delete', [AgentController::class, 'siteDelete']);
    Route::post('/site/suspend', [AgentController::class, 'siteSuspend']);
    Route::post('/site/restore', [AgentController::class, 'siteRestore']);
    Route::post('/runtime/provision', [AgentController::class, 'runtimeProvision']);
    Route::post('/runtime/destroy', [AgentController::class, 'runtimeDestroy']);
    Route::post('/reconcile', [AgentController::class, 'reconcile']);
});
