<?php

use Illuminate\Support\Facades\Route;
use Modules\Quality\Http\Controllers\ConcreteSampleController;
use Modules\Quality\Http\Controllers\InspectionController;
use Modules\Quality\Http\Controllers\InspectionTemplateController;
use Modules\Quality\Http\Controllers\NcrController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Checklist library (imported from XLSX via document-import; these endpoints
    // are the manual CRUD over the same tables).
    Route::get('inspection-templates', [InspectionTemplateController::class, 'index']);
    Route::post('inspection-templates', [InspectionTemplateController::class, 'store'])->middleware('permission:qc.create');
    Route::get('inspection-templates/{template}', [InspectionTemplateController::class, 'show']);
    Route::put('inspection-templates/{template}', [InspectionTemplateController::class, 'update'])->middleware('permission:qc.update');
    Route::delete('inspection-templates/{template}', [InspectionTemplateController::class, 'destroy'])->middleware('permission:qc.delete');

    // Inspeksi mutu (QCI). submit runs the NCR block in InspectionService;
    // approve/reject are the house Approvable cycle (qc.approve, maker-checker).
    Route::get('inspections', [InspectionController::class, 'index']);
    Route::post('inspections', [InspectionController::class, 'store'])->middleware('permission:qc.create');
    Route::get('inspections/{inspection}', [InspectionController::class, 'show']);
    Route::put('inspections/{inspection}', [InspectionController::class, 'update'])->middleware('permission:qc.update');
    Route::delete('inspections/{inspection}', [InspectionController::class, 'destroy'])->middleware('permission:qc.delete');
    Route::post('inspections/{inspection}/submit', [InspectionController::class, 'submit'])->middleware('permission:qc.update');
    Route::post('inspections/{inspection}/approve', [InspectionController::class, 'approve'])->middleware('permission:qc.approve');
    Route::post('inspections/{inspection}/reject', [InspectionController::class, 'reject'])->middleware('permission:qc.approve');

    // NCR. Its lifecycle is NcrStatus transitions, not submit/approve: verify is
    // qc.approve (accepting a correction), the rest qc.update.
    Route::get('ncr', [NcrController::class, 'index']);
    Route::post('ncr', [NcrController::class, 'store'])->middleware('permission:qc.create');
    Route::get('ncr/{ncr}', [NcrController::class, 'show']);
    Route::put('ncr/{ncr}', [NcrController::class, 'update'])->middleware('permission:qc.update');
    Route::delete('ncr/{ncr}', [NcrController::class, 'destroy'])->middleware('permission:qc.delete');
    Route::post('ncr/{ncr}/start-correction', [NcrController::class, 'startCorrection'])->middleware('permission:qc.update');
    Route::post('ncr/{ncr}/verify', [NcrController::class, 'verify'])->middleware('permission:qc.approve');
    Route::post('ncr/{ncr}/close', [NcrController::class, 'close'])->middleware('permission:qc.update');

    // Benda uji beton & hasil (F/BU). pass is computed, never posted.
    Route::get('concrete-samples', [ConcreteSampleController::class, 'index']);
    Route::post('concrete-samples', [ConcreteSampleController::class, 'store'])->middleware('permission:qc.create');
    Route::get('concrete-samples/{sample}', [ConcreteSampleController::class, 'show']);
    Route::put('concrete-samples/{sample}', [ConcreteSampleController::class, 'update'])->middleware('permission:qc.update');
    Route::delete('concrete-samples/{sample}', [ConcreteSampleController::class, 'destroy'])->middleware('permission:qc.delete');
    Route::post('concrete-samples/{sample}/tests', [ConcreteSampleController::class, 'addTest'])->middleware('permission:qc.update');
});
