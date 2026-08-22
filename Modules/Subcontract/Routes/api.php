<?php

use Illuminate\Support\Facades\Route;
use Modules\Subcontract\Http\Controllers\ProgressClaimController;
use Modules\Subcontract\Http\Controllers\SubcontractAddendumController;
use Modules\Subcontract\Http\Controllers\SubcontractController;

Route::middleware('auth:sanctum')->group(function (): void {
    // SPK subkontraktor
    Route::get('subcontracts', [SubcontractController::class, 'index']);
    Route::post('subcontracts', [SubcontractController::class, 'store'])->middleware('permission:scm.create');
    Route::get('subcontracts/{subcontract}', [SubcontractController::class, 'show']);
    Route::put('subcontracts/{subcontract}', [SubcontractController::class, 'update'])->middleware('permission:scm.update');
    Route::delete('subcontracts/{subcontract}', [SubcontractController::class, 'destroy'])->middleware('permission:scm.delete');
    Route::post('subcontracts/{subcontract}/submit', [SubcontractController::class, 'submit'])->middleware('permission:scm.update');
    Route::post('subcontracts/{subcontract}/approve', [SubcontractController::class, 'approve'])->middleware('permission:scm.approve');
    Route::post('subcontracts/{subcontract}/reject', [SubcontractController::class, 'reject'])->middleware('permission:scm.approve');

    // Addendum SPK (pekerjaan tambah-kurang subkon) — approval moves the SPK
    // value and the klaim plafon with it, so approve carries scm.approve like
    // the SPK's own approval.
    Route::get('addenda', [SubcontractAddendumController::class, 'index']);
    Route::post('addenda', [SubcontractAddendumController::class, 'store'])->middleware('permission:scm.create');
    Route::get('addenda/{addendum}', [SubcontractAddendumController::class, 'show']);
    Route::put('addenda/{addendum}', [SubcontractAddendumController::class, 'update'])->middleware('permission:scm.update');
    Route::delete('addenda/{addendum}', [SubcontractAddendumController::class, 'destroy'])->middleware('permission:scm.delete');
    Route::post('addenda/{addendum}/submit', [SubcontractAddendumController::class, 'submit'])->middleware('permission:scm.update');
    Route::post('addenda/{addendum}/approve', [SubcontractAddendumController::class, 'approve'])->middleware('permission:scm.approve');
    Route::post('addenda/{addendum}/reject', [SubcontractAddendumController::class, 'reject'])->middleware('permission:scm.approve');
    Route::get('subcontracts/{subcontract}/addendum-summary', [SubcontractController::class, 'addendumSummary']);

    // Uang muka subkon. The CLAIM walks the ordinary progress-claims lifecycle
    // (submit/approve below); only its creation has a dedicated door. The
    // PAYOUT mints an ALREADY-APPROVED AP bill exactly like the retention
    // release — same reasoning, same double gate: the id on that bill's
    // `approved` row must genuinely hold the AP approval right.
    Route::get('subcontracts/{subcontract}/advance', [SubcontractController::class, 'advance']);
    Route::post('subcontracts/{subcontract}/advance-claim', [SubcontractController::class, 'advanceClaim'])
        ->middleware('permission:scm.create');
    Route::post('subcontracts/{subcontract}/advance-payout', [SubcontractController::class, 'advancePayout'])
        ->middleware(['permission:scm.post', 'permission:fin.approve']);

    // Temuan #75 (susulan): gate waktu retensi membaca defect_liability_until,
    // tetapi kolom itu hanya bisa diisi selagi SPK draf — portofolio SPK yang
    // sudah disetujui sebelum gate lahir tak pernah bisa melengkapinya, dan
    // setiap pelepasannya terpaksa override selamanya. Pintu sempit ini
    // mengubah SATU tanggal itu saja pada SPK submitted/approved, dan ditolak
    // begitu retensi pernah dilepas (tanggal yang diganti sesudahnya menulis
    // ulang cerita yang jejak override-nya sudah rekam).
    Route::put('subcontracts/{subcontract}/defect-liability', [SubcontractController::class, 'updateDefectLiability'])
        ->middleware('permission:scm.update');

    // Retensi
    Route::get('subcontracts/{subcontract}/retention', [SubcontractController::class, 'retention']);
    // BOTH permissions, not either. One click here mints an ALREADY-APPROVED
    // AP bill (up to Rp 104.000.000 of releasable retention on SPK/2026/II/0001
    // once its opname bills are raised) that PaymentService will pay with no
    // further approval — so the person whose id lands on that bill's `approved`
    // row must actually hold the AP approval right, not just a Subcontract one.
    Route::post('subcontracts/{subcontract}/retention-release', [SubcontractController::class, 'retentionRelease'])
        ->middleware(['permission:scm.post', 'permission:fin.approve']);

    // Progress claims (opname)
    Route::get('progress-claims', [ProgressClaimController::class, 'index']);
    Route::post('progress-claims', [ProgressClaimController::class, 'store'])->middleware('permission:scm.create');
    Route::get('progress-claims/{progressClaim}', [ProgressClaimController::class, 'show']);
    Route::put('progress-claims/{progressClaim}', [ProgressClaimController::class, 'update'])->middleware('permission:scm.update');
    Route::delete('progress-claims/{progressClaim}', [ProgressClaimController::class, 'destroy'])->middleware('permission:scm.delete');
    Route::post('progress-claims/{progressClaim}/submit', [ProgressClaimController::class, 'submit'])->middleware('permission:scm.update');
    Route::post('progress-claims/{progressClaim}/approve', [ProgressClaimController::class, 'approve'])->middleware('permission:scm.approve');
    Route::post('progress-claims/{progressClaim}/reject', [ProgressClaimController::class, 'reject'])->middleware('permission:scm.approve');
});
