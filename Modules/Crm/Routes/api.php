<?php

use Illuminate\Support\Facades\Route;
use Modules\Crm\Http\Controllers\ContractChangeOrderController;
use Modules\Crm\Http\Controllers\ContractController;
use Modules\Crm\Http\Controllers\ContractTerminController;
use Modules\Crm\Http\Controllers\CustomerController;
use Modules\Crm\Http\Controllers\GuaranteeController;
use Modules\Crm\Http\Controllers\LeadController;
use Modules\Crm\Http\Controllers\PipelineReportController;
use Modules\Crm\Http\Controllers\QuotationController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Customers
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:crm.create');
    Route::get('customers/{customer}', [CustomerController::class, 'show']);
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:crm.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:crm.delete');

    // Leads
    Route::get('leads', [LeadController::class, 'index']);
    Route::post('leads', [LeadController::class, 'store'])->middleware('permission:crm.create');
    Route::get('leads/{lead}', [LeadController::class, 'show']);
    Route::put('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:crm.update');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->middleware('permission:crm.delete');
    // Konversi lead→pelanggan (temuan #58). crm.create, karena yang dibuat
    // adalah master pelanggan — bukan sekadar perubahan pada lead-nya.
    Route::post('leads/{lead}/convert-to-customer', [LeadController::class, 'convertToCustomer'])->middleware('permission:crm.create');

    // Quotations (penawaran)
    Route::get('quotations', [QuotationController::class, 'index']);
    Route::post('quotations', [QuotationController::class, 'store'])->middleware('permission:crm.create');
    Route::get('quotations/{quotation}', [QuotationController::class, 'show']);
    Route::put('quotations/{quotation}', [QuotationController::class, 'update'])->middleware('permission:crm.update');
    Route::delete('quotations/{quotation}', [QuotationController::class, 'destroy'])->middleware('permission:crm.delete');
    Route::post('quotations/{quotation}/submit', [QuotationController::class, 'submit'])->middleware('permission:crm.update');
    Route::post('quotations/{quotation}/approve', [QuotationController::class, 'approve'])->middleware('permission:crm.approve');
    Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->middleware('permission:crm.approve');
    Route::post('quotations/{quotation}/mark-won', [QuotationController::class, 'markWon'])->middleware('permission:crm.update');
    Route::post('quotations/{quotation}/mark-lost', [QuotationController::class, 'markLost'])->middleware('permission:crm.update');
    Route::post('quotations/{quotation}/revise', [QuotationController::class, 'revise'])->middleware('permission:crm.update');

    // Analitik win-rate (temuan #78): agregasi won_at/lost_at/lost_reason yang
    // sudah dicatat Tandai Menang/Kalah — win-rate per kuartal keputusan dan
    // alasan kalah terbanyak. crm.view: angka manajemen penjualan, bukan dokumen.
    Route::get('reports/pipeline', [PipelineReportController::class, 'pipeline'])->middleware('permission:crm.view');

    // Contracts + termin schedules
    Route::get('contracts', [ContractController::class, 'index']);
    Route::post('contracts', [ContractController::class, 'store'])->middleware('permission:crm.create');
    Route::get('contracts/{contract}', [ContractController::class, 'show']);
    Route::put('contracts/{contract}', [ContractController::class, 'update'])->middleware('permission:crm.update');
    Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])->middleware('permission:crm.delete');
    Route::post('contracts/{contract}/activate', [ContractController::class, 'activate'])->middleware('permission:crm.approve');
    Route::get('contracts/{contract}/termins', [ContractController::class, 'termins']);

    // Antrean siap tagih (PM→Finance handoff). `billing-ready` is declared before
    // the {contractTermin} wildcard so the literal segment is not swallowed by it.
    // fin.view, not crm.view — this is a finance work queue, see the controller.
    Route::get('contract-termins/billing-ready', [ContractTerminController::class, 'billingReady'])->middleware('permission:fin.view');
    Route::put('contract-termins/{contractTermin}', [ContractTerminController::class, 'update'])->middleware('permission:crm.update');

    // Pekerjaan tambah-kurang. An approved contract is immutable by design; this
    // is how its value legitimately moves.
    Route::get('contract-change-orders', [ContractChangeOrderController::class, 'index'])->middleware('permission:crm.view');
    Route::post('contract-change-orders', [ContractChangeOrderController::class, 'store'])->middleware('permission:crm.create');
    Route::get('contract-change-orders/{contractChangeOrder}', [ContractChangeOrderController::class, 'show'])->middleware('permission:crm.view');
    Route::put('contract-change-orders/{contractChangeOrder}', [ContractChangeOrderController::class, 'update'])->middleware('permission:crm.update');
    Route::delete('contract-change-orders/{contractChangeOrder}', [ContractChangeOrderController::class, 'destroy'])->middleware('permission:crm.delete');
    Route::post('contract-change-orders/{contractChangeOrder}/submit', [ContractChangeOrderController::class, 'submit'])->middleware('permission:crm.update');
    Route::post('contract-change-orders/{contractChangeOrder}/approve', [ContractChangeOrderController::class, 'approve'])->middleware('permission:crm.approve');
    Route::post('contract-change-orders/{contractChangeOrder}/reject', [ContractChangeOrderController::class, 'reject'])->middleware('permission:crm.approve');
    // Wizard pasca-persetujuan (temuan #14): nilai tambah CCO menjadi satu
    // termin baru ber-due_date sehingga antrean siap tagih ikut mengejarnya.
    // crm.update — yang diubah adalah jadwal termin kontrak, sama seperti
    // PUT contract-termins/{id}; persetujuan nilainya sudah lewat crm.approve.
    Route::post('contract-change-orders/{contractChangeOrder}/schedule-termin', [ContractChangeOrderController::class, 'scheduleTermin'])->middleware('permission:crm.update');
    Route::get('contracts/{contract}/change-summary', [ContractChangeOrderController::class, 'summary'])->middleware('permission:crm.view');

    // Register jaminan & asuransi. A register, not a document — no numbering,
    // no approval; identity is the bank's own (issuer, number). end_date on
    // active rows is what erp:deadline-watch scans.
    Route::get('guarantees', [GuaranteeController::class, 'index'])->middleware('permission:crm.view');
    Route::post('guarantees', [GuaranteeController::class, 'store'])->middleware('permission:crm.create');
    Route::get('guarantees/{guarantee}', [GuaranteeController::class, 'show'])->middleware('permission:crm.view');
    Route::put('guarantees/{guarantee}', [GuaranteeController::class, 'update'])->middleware('permission:crm.update');
    Route::delete('guarantees/{guarantee}', [GuaranteeController::class, 'destroy'])->middleware('permission:crm.delete');
});
