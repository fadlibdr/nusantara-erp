<?php

use Illuminate\Support\Facades\Route;
use Modules\Assets\Http\Controllers\AssetCategoryController;
use Modules\Assets\Http\Controllers\AssetController;
use Modules\Assets\Http\Controllers\DeploymentController;
use Modules\Assets\Http\Controllers\DepreciationRunController;
use Modules\Assets\Http\Controllers\EquipmentLogController;
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

    /*
     * Log BBM & jam alat (deviasi #13) — a REGISTER: it moves no money and
     * posts nothing. The fuel cost already flows through petty cash under the
     * BbmTol category; whoever comes looking for the journal, that is where
     * it lives. This register keeps the operational half only (hours run,
     * litres in), per deployment, written by the people at the site.
     *
     * READING: ast.view OR prj.view — the register belongs to the machine but
     * is read at the site, and the site roles hold prj.view without ast.view.
     * The pipe is spatie's own OR (PermissionMiddleware explodes '|' into
     * canAny) — first use in this codebase, and the least novel mechanism on
     * offer: it is documented behaviour of the middleware bootstrap/app.php
     * already registers, where registering the URI twice would just let the
     * second registration replace the first, and a bespoke OR middleware
     * would be new code for what the registered one already does.
     *
     * WRITING: prj.update, deliberately a Projects permission on an Assets
     * route. The people who write site logs are the project roles — site
     * manager and project manager both hold prj.update, teknisi does not —
     * and granting them ast.create instead would let a site manager mint
     * ASSETS, a much wider power than appending a fuel line. The scm.post +
     * fin.approve dual gate on Subcontract's retention release is the
     * standing precedent that the prefix=module convention yields where
     * honesty demands it.
     *
     * PUT/DELETE exist only to refuse with the append-only sentence (see the
     * controller); they sit behind the same write gate so a stranger gets a
     * plain 403 while the people who could have written the row get the rule.
     */
    Route::get('equipment-logs', [EquipmentLogController::class, 'index'])
        ->middleware('permission:ast.view|prj.view');
    Route::post('equipment-logs', [EquipmentLogController::class, 'store'])
        ->middleware('permission:prj.update');
    Route::put('equipment-logs/{equipmentLog}', [EquipmentLogController::class, 'update'])
        ->middleware('permission:prj.update');
    Route::delete('equipment-logs/{equipmentLog}', [EquipmentLogController::class, 'destroy'])
        ->middleware('permission:prj.update');

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
