<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Connector\EnrollController;
use App\Http\Controllers\Api\Connector\HeartbeatController;
use App\Http\Controllers\Api\Connector\PollController;
use App\Http\Controllers\Api\Connector\ResultController;
use App\Http\Controllers\Api\Connector\SessionController;
use App\Http\Controllers\Api\CsvImportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DrillHoleAssignmentController;
use App\Http\Controllers\Api\DrillHoleController;
use App\Http\Controllers\Api\DrillHoleProgressController;
use App\Http\Controllers\Api\DrillingPlanController;
use App\Http\Controllers\Api\DrillingPlatformController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Internal\DemoListHolesController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\ObservationController;
use App\Http\Controllers\Api\PlanFileController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\Tenant\TenantHoleController;
use App\Http\Middleware\AuthenticateConnector;
use App\Http\Middleware\AuthenticateDemoInternal;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('connector/v1')->group(function () {
    Route::post('/enroll', EnrollController::class);
    Route::post('/sessions', SessionController::class);

    Route::middleware(AuthenticateConnector::class)->group(function () {
        Route::post('/heartbeat', HeartbeatController::class);
        Route::post('/poll', PollController::class);
        Route::post('/results', ResultController::class);
    });
});

Route::middleware(AuthenticateDemoInternal::class)->group(function () {
    Route::post('/internal/demo/list-holes', DemoListHolesController::class);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('/drilling-plans', [DrillingPlanController::class, 'index']);
    Route::get('/drilling-plans/{drillingPlan}', [DrillingPlanController::class, 'show']);
    Route::get('/drilling-plans/{drillingPlan}/platforms', [DrillingPlatformController::class, 'byPlan']);
    Route::get('/drilling-plans/{drillingPlan}/available-machines', [MachineController::class, 'availableByPlan']);
    Route::get('/drilling-plans/{drillingPlan}/files', [PlanFileController::class, 'byPlan']);

    Route::get('/drilling-platforms/{drillingPlatform}', [DrillingPlatformController::class, 'show']);

    Route::get('/drill-holes', [DrillHoleController::class, 'index']);
    Route::post('/drill-holes', [DrillHoleController::class, 'store']);
    Route::get('/drill-holes/{drillHole}', [DrillHoleController::class, 'show']);
    Route::patch('/drill-holes/{drillHole}/technical-data', [DrillHoleController::class, 'updateTechnicalData']);
    Route::post('/drill-holes/{drillHole}/assignments', [DrillHoleAssignmentController::class, 'store']);
    Route::post('/drill-holes/{drillHole}/progress', [DrillHoleProgressController::class, 'store']);
    Route::get('/drill-holes/{drillHole}/observations', [ObservationController::class, 'index']);
    Route::post('/drill-holes/{drillHole}/observations', [ObservationController::class, 'store']);
    Route::post('/drill-holes/{drillHole}/risks', [ObservationController::class, 'storeRisk']);

    Route::patch('/observations/{observation}/close-risk', [ObservationController::class, 'closeRisk']);

    Route::get('/risks', [RiskController::class, 'index']);

    Route::get('/machines', [MachineController::class, 'index']);

    Route::post('/imports/preview', [CsvImportController::class, 'preview']);
    Route::post('/imports/confirm', [CsvImportController::class, 'confirm']);
    Route::get('/imports', [CsvImportController::class, 'index']);
});

// Pozos/avance that live in the CLIENT's own database, reached through the
// Data Gateway. tenant_id is never read from the request — ResolveTenantContext
// resolves it server-side from the authenticated user's active membership.
// See docs/sprints/distributed-data-vertical-slice.md.
Route::middleware(['auth:sanctum', ResolveTenantContext::class])->prefix('tenant')->group(function () {
    Route::get('/holes', [TenantHoleController::class, 'index']);
    Route::get('/holes/{hole}', [TenantHoleController::class, 'show']);
});
