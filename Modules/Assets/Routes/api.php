<?php

use Illuminate\Support\Facades\Route;
use Modules\Assets\Http\Controllers\AssetCategoryController;
use Modules\Assets\Http\Controllers\AssetController;
use Modules\Assets\Http\Controllers\DeploymentController;
use Modules\Assets\Http\Controllers\DepreciationRunController;
use Modules\Assets\Http\Controllers\MaintenanceController;
use Modules\Assets\Http\Controllers\ReportController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Asset categories (Alat Berat, Kendaraan, Alat Ukur & Uji, ...)
    Route::get('categories', [AssetCategoryController::class, 'index']);
    Route::post('categories', [AssetCategoryController::class, 'store'])->middleware('permission:ast.create');
    Route::get('categories/{category}', [AssetCategoryController::class, 'show']);
    Route::put('categories/{category}', [AssetCategoryController::class, 'update'])->middleware('permission:ast.update');
    Route::delete('categories/{category}', [AssetCategoryController::class, 'destroy'])->middleware('permission:ast.delete');

    // Asset register (daftar aset tetap)
    Route::get('assets', [AssetController::class, 'index']);
    Route::post('assets', [AssetController::class, 'store'])->middleware('permission:ast.create');
    Route::get('assets/{asset}', [AssetController::class, 'show']);
    Route::put('assets/{asset}', [AssetController::class, 'update'])->middleware('permission:ast.update');
    Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->middleware('permission:ast.delete');
    Route::post('assets/{asset}/deploy', [AssetController::class, 'deploy'])->middleware('permission:ast.create');
    // ast.post, not ast.update: disposing posts a derecognition journal to the
    // GL, the same weight as posting a depreciation run — an editor who may
    // rename an asset must not be able to take it off the balance sheet.
    Route::post('assets/{asset}/dispose', [AssetController::class, 'dispose'])->middleware('permission:ast.post');
    Route::get('assets/{asset}/history', [AssetController::class, 'history']);

    // Deployments (mobilisasi alat ke proyek)
    Route::get('deployments', [DeploymentController::class, 'index']);
    Route::post('deployments', [DeploymentController::class, 'store'])->middleware('permission:ast.create');
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show']);
    Route::put('deployments/{deployment}', [DeploymentController::class, 'update'])->middleware('permission:ast.update');
    Route::delete('deployments/{deployment}', [DeploymentController::class, 'destroy'])->middleware('permission:ast.delete');
    Route::post('deployments/{deployment}/return', [DeploymentController::class, 'return'])->middleware('permission:ast.post');

    // Maintenance records (service rutin / perbaikan / kalibrasi)
    Route::get('maintenances', [MaintenanceController::class, 'index']);
    Route::post('maintenances', [MaintenanceController::class, 'store'])->middleware('permission:ast.create');
    Route::get('maintenances/{maintenance}', [MaintenanceController::class, 'show']);
    Route::put('maintenances/{maintenance}', [MaintenanceController::class, 'update'])->middleware('permission:ast.update');
    Route::delete('maintenances/{maintenance}', [MaintenanceController::class, 'destroy'])->middleware('permission:ast.delete');

    // Monthly straight-line depreciation runs — Finance imports posted runs
    Route::get('depreciation-runs', [DepreciationRunController::class, 'index']);
    Route::post('depreciation-runs', [DepreciationRunController::class, 'store'])->middleware('permission:ast.create');
    Route::get('depreciation-runs/{depreciationRun}', [DepreciationRunController::class, 'show']);
    Route::delete('depreciation-runs/{depreciationRun}', [DepreciationRunController::class, 'destroy'])->middleware('permission:ast.delete');
    Route::post('depreciation-runs/{depreciationRun}/post', [DepreciationRunController::class, 'post'])->middleware('permission:ast.post');

    // Reports
    Route::get('reports/utilization', [ReportController::class, 'utilization']);
});
