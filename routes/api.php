<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CsvImportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DrillHoleAssignmentController;
use App\Http\Controllers\Api\DrillHoleController;
use App\Http\Controllers\Api\DrillHoleProgressController;
use App\Http\Controllers\Api\DrillingPlanController;
use App\Http\Controllers\Api\DrillingPlatformController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\ObservationController;
use App\Http\Controllers\Api\PlanFileController;
use App\Http\Controllers\Api\RiskController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

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
