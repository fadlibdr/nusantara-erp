<?php

use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\PurchaseOrderController;
use Modules\Procurement\Http\Controllers\PurchaseRequisitionController;
use Modules\Procurement\Http\Controllers\ReportController;
use Modules\Procurement\Http\Controllers\RfqController;
use Modules\Procurement\Http\Controllers\VendorController;
use Modules\Procurement\Http\Controllers\VendorDocumentController;
use Modules\Procurement\Http\Controllers\VendorEvaluationController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Vendors (also the canonical vendor master for the Subcontract module)
    Route::get('vendors', [VendorController::class, 'index']);
    Route::post('vendors', [VendorController::class, 'store'])->middleware('permission:prc.create');
    Route::get('vendors/{vendor}', [VendorController::class, 'show']);
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->middleware('permission:prc.update');
    Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])->middleware('permission:prc.delete');

    // Register dokumen prakualifikasi vendor — jenis, nomor, berlaku s/d.
    // Satu register untuk temuan #35 dan #69; gate-nya di
    // VendorQualificationService, pengingatnya di deadline-watch.
    Route::get('vendor-documents', [VendorDocumentController::class, 'index']);
    Route::post('vendor-documents', [VendorDocumentController::class, 'store'])->middleware('permission:prc.create');
    Route::get('vendor-documents/{vendorDocument}', [VendorDocumentController::class, 'show']);
    Route::put('vendor-documents/{vendorDocument}', [VendorDocumentController::class, 'update'])->middleware('permission:prc.update');
    Route::delete('vendor-documents/{vendorDocument}', [VendorDocumentController::class, 'destroy'])->middleware('permission:prc.delete');

    // Purchase requisitions
    Route::get('purchase-requisitions', [PurchaseRequisitionController::class, 'index']);
    Route::post('purchase-requisitions', [PurchaseRequisitionController::class, 'store'])->middleware('permission:prc.create');
    Route::get('purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'show']);
    Route::put('purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'update'])->middleware('permission:prc.update');
    Route::delete('purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'destroy'])->middleware('permission:prc.delete');
    Route::post('purchase-requisitions/{purchaseRequisition}/submit', [PurchaseRequisitionController::class, 'submit'])->middleware('permission:prc.update');
    Route::post('purchase-requisitions/{purchaseRequisition}/approve', [PurchaseRequisitionController::class, 'approve'])->middleware('permission:prc.approve');
    Route::post('purchase-requisitions/{purchaseRequisition}/reject', [PurchaseRequisitionController::class, 'reject'])->middleware('permission:prc.approve');
    Route::post('purchase-requisitions/{purchaseRequisition}/create-po', [PurchaseRequisitionController::class, 'createPo'])->middleware('permission:prc.create');

    // RFQ — lembar banding penawaran vendor (temuan #34 tahap 3). Aksi yang
    // MEMBUAT dokumen (store, create-po) memakai prc.create; mengisi tabulasi
    // (quotes, pemenang, tutup) adalah prc.update seperti edit dokumen biasa.
    Route::get('rfqs', [RfqController::class, 'index']);
    Route::post('rfqs', [RfqController::class, 'store'])->middleware('permission:prc.create');
    Route::get('rfqs/{rfq}', [RfqController::class, 'show']);
    Route::put('rfqs/{rfq}', [RfqController::class, 'update'])->middleware('permission:prc.update');
    Route::delete('rfqs/{rfq}', [RfqController::class, 'destroy'])->middleware('permission:prc.delete');
    Route::post('rfqs/{rfq}/quotes', [RfqController::class, 'quotes'])->middleware('permission:prc.update');
    Route::post('rfqs/{rfq}/choose-winner', [RfqController::class, 'chooseWinner'])->middleware('permission:prc.update');
    Route::post('rfqs/{rfq}/create-po', [RfqController::class, 'createPo'])->middleware('permission:prc.create');
    Route::post('rfqs/{rfq}/close', [RfqController::class, 'close'])->middleware('permission:prc.update');

    // Purchase orders
    Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:prc.create');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:prc.update');
    Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:prc.delete');
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:prc.update');
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:prc.approve');
    Route::post('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->middleware('permission:prc.approve');
    Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:prc.update');

    // Reports. Gated on prc.view explicitly — unlike a per-document GET this
    // aggregates open commitments and prices across every vendor at once.
    Route::get('reports/outstanding', [ReportController::class, 'outstanding'])->middleware('permission:prc.view');

    // Vendor evaluations
    Route::get('vendor-evaluations', [VendorEvaluationController::class, 'index']);
    Route::post('vendor-evaluations', [VendorEvaluationController::class, 'store'])->middleware('permission:prc.create');
    // Rute literal SEBELUM wildcard {vendorEvaluation}, atau "delivery-suggestion"
    // ditelan binding model dan menjawab 404.
    Route::get('vendor-evaluations/delivery-suggestion', [VendorEvaluationController::class, 'deliverySuggestion'])->middleware('permission:prc.view');
    Route::get('vendor-evaluations/{vendorEvaluation}', [VendorEvaluationController::class, 'show']);
    Route::put('vendor-evaluations/{vendorEvaluation}', [VendorEvaluationController::class, 'update'])->middleware('permission:prc.update');
    Route::delete('vendor-evaluations/{vendorEvaluation}', [VendorEvaluationController::class, 'destroy'])->middleware('permission:prc.delete');
});
