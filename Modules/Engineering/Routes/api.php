<?php

use Illuminate\Support\Facades\Route;
use Modules\Engineering\Http\Controllers\DrawingController;
use Modules\Engineering\Http\Controllers\DrawingSubmittalController;
use Modules\Engineering\Http\Controllers\IppController;
use Modules\Engineering\Http\Controllers\MaterialSubmittalController;
use Modules\Engineering\Http\Controllers\TransmittalController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Register shop drawing (FM-10-01/21)
    Route::get('drawings', [DrawingController::class, 'index']);
    Route::post('drawings', [DrawingController::class, 'store'])->middleware('permission:eng.create');
    Route::get('drawings/{drawing}', [DrawingController::class, 'show']);
    Route::put('drawings/{drawing}', [DrawingController::class, 'update'])->middleware('permission:eng.update');
    Route::delete('drawings/{drawing}', [DrawingController::class, 'destroy'])->middleware('permission:eng.delete');

    // Pengajuan persetujuan shop drawing (SDS, FM-10-03). The 'decision'
    // action records the EXTERNAL MK stamp — eng.approve, because whoever may
    // type the stamp wields the stamp; the service additionally refuses the
    // submittal's own creator (maker-checker on the recording).
    Route::get('drawing-submittals', [DrawingSubmittalController::class, 'index']);
    Route::post('drawing-submittals', [DrawingSubmittalController::class, 'store'])->middleware('permission:eng.create');
    Route::get('drawing-submittals/{drawingSubmittal}', [DrawingSubmittalController::class, 'show']);
    Route::put('drawing-submittals/{drawingSubmittal}', [DrawingSubmittalController::class, 'update'])->middleware('permission:eng.update');
    Route::delete('drawing-submittals/{drawingSubmittal}', [DrawingSubmittalController::class, 'destroy'])->middleware('permission:eng.delete');
    Route::post('drawing-submittals/{drawingSubmittal}/decision', [DrawingSubmittalController::class, 'decision'])->middleware('permission:eng.approve');

    // Pengajuan persetujuan material (SMS, FM-10-05/22)
    Route::get('material-submittals', [MaterialSubmittalController::class, 'index']);
    Route::post('material-submittals', [MaterialSubmittalController::class, 'store'])->middleware('permission:eng.create');
    Route::get('material-submittals/{materialSubmittal}', [MaterialSubmittalController::class, 'show']);
    Route::put('material-submittals/{materialSubmittal}', [MaterialSubmittalController::class, 'update'])->middleware('permission:eng.update');
    Route::delete('material-submittals/{materialSubmittal}', [MaterialSubmittalController::class, 'destroy'])->middleware('permission:eng.delete');
    Route::post('material-submittals/{materialSubmittal}/decision', [MaterialSubmittalController::class, 'decision'])->middleware('permission:eng.approve');

    // Transmittal (TRM) + tanda terima. 'terima' is eng.update, not approve:
    // recording who signed for a bundle is clerical custody, not a decision.
    Route::get('transmittals', [TransmittalController::class, 'index']);
    Route::post('transmittals', [TransmittalController::class, 'store'])->middleware('permission:eng.create');
    Route::get('transmittals/{transmittal}', [TransmittalController::class, 'show']);
    Route::put('transmittals/{transmittal}', [TransmittalController::class, 'update'])->middleware('permission:eng.update');
    Route::delete('transmittals/{transmittal}', [TransmittalController::class, 'destroy'])->middleware('permission:eng.delete');
    Route::post('transmittals/{transmittal}/terima', [TransmittalController::class, 'terima'])->middleware('permission:eng.update');

    // Ijin Pelaksanaan Pekerjaan (IPP, FM-10-11) — submit runs the gate in
    // IppService; approve/reject are the house Approvable cycle (eng.approve,
    // maker-checker in the trait).
    Route::get('ipp', [IppController::class, 'index']);
    Route::post('ipp', [IppController::class, 'store'])->middleware('permission:eng.create');
    Route::get('ipp/{ipp}', [IppController::class, 'show']);
    Route::put('ipp/{ipp}', [IppController::class, 'update'])->middleware('permission:eng.update');
    Route::delete('ipp/{ipp}', [IppController::class, 'destroy'])->middleware('permission:eng.delete');
    Route::post('ipp/{ipp}/submit', [IppController::class, 'submit'])->middleware('permission:eng.update');
    // Revisi membuat DOKUMEN BARU, maka izinnya izin membuat (P8, D9).
    Route::post('ipp/{ipp}/revise', [IppController::class, 'revise'])->middleware('permission:eng.create');
    Route::post('ipp/{ipp}/approve', [IppController::class, 'approve'])->middleware('permission:eng.approve');
    Route::post('ipp/{ipp}/reject', [IppController::class, 'reject'])->middleware('permission:eng.approve');
});
