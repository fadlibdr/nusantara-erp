<?php

use Illuminate\Support\Facades\Route;
use Modules\Estimation\Http\Controllers\AhspController;
use Modules\Estimation\Http\Controllers\BoqController;
use Modules\Estimation\Http\Controllers\CostBudgetController;
use Modules\Estimation\Http\Controllers\PriceHistoryController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Riwayat harga beli per item, dari prc_purchase_order_items + valuasi GRN
    // (Temuan 17). est.view: harga beli per vendor adalah bahan negosiasi,
    // sama sensitifnya dengan analisa AHSP di bawahnya.
    Route::get('price-history', [PriceHistoryController::class, 'show'])->middleware('permission:est.view');

    // AHSP — unit-price analyses
    Route::get('ahsp', [AhspController::class, 'index'])->middleware('permission:est.view');
    Route::post('ahsp', [AhspController::class, 'store'])->middleware('permission:est.create');
    Route::get('ahsp/{ahsp}', [AhspController::class, 'show'])->middleware('permission:est.view');
    Route::put('ahsp/{ahsp}', [AhspController::class, 'update'])->middleware('permission:est.update');
    Route::delete('ahsp/{ahsp}', [AhspController::class, 'destroy'])->middleware('permission:est.delete');

    // BOQ / RAB
    Route::get('boqs', [BoqController::class, 'index'])->middleware('permission:est.view');
    Route::post('boqs', [BoqController::class, 'store'])->middleware('permission:est.create');
    Route::get('boqs/{boq}', [BoqController::class, 'show'])->middleware('permission:est.view');
    Route::put('boqs/{boq}', [BoqController::class, 'update'])->middleware('permission:est.update');
    Route::delete('boqs/{boq}', [BoqController::class, 'destroy'])->middleware('permission:est.delete');
    Route::get('boqs/{boq}/items', [BoqController::class, 'items'])->middleware('permission:est.view');
    Route::post('boqs/{boq}/submit', [BoqController::class, 'submit'])->middleware('permission:est.update');
    Route::post('boqs/{boq}/approve', [BoqController::class, 'approve'])->middleware('permission:est.approve');
    Route::post('boqs/{boq}/reject', [BoqController::class, 'reject'])->middleware('permission:est.approve');
    Route::post('boqs/{boq}/new-version', [BoqController::class, 'newVersion'])->middleware('permission:est.create');

    // RAP — internal cost budgets
    Route::get('cost-budgets', [CostBudgetController::class, 'index'])->middleware('permission:est.view');
    Route::post('cost-budgets', [CostBudgetController::class, 'store'])->middleware('permission:est.create');
    Route::get('cost-budgets/{costBudget}', [CostBudgetController::class, 'show'])->middleware('permission:est.view');
    Route::put('cost-budgets/{costBudget}', [CostBudgetController::class, 'update'])->middleware('permission:est.update');
    Route::delete('cost-budgets/{costBudget}', [CostBudgetController::class, 'destroy'])->middleware('permission:est.delete');
    Route::post('cost-budgets/{costBudget}/generate-from-boq', [CostBudgetController::class, 'generateFromBoq'])->middleware('permission:est.update');
    Route::post('cost-budgets/{costBudget}/submit', [CostBudgetController::class, 'submit'])->middleware('permission:est.update');
    Route::post('cost-budgets/{costBudget}/approve', [CostBudgetController::class, 'approve'])->middleware('permission:est.approve');
    Route::post('cost-budgets/{costBudget}/reject', [CostBudgetController::class, 'reject'])->middleware('permission:est.approve');
});
