<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\AccountController;
use Modules\Finance\Http\Controllers\ApBillController;
use Modules\Finance\Http\Controllers\ArInvoiceController;
use Modules\Finance\Http\Controllers\ArRetentionController;
use Modules\Finance\Http\Controllers\BankAccountController;
use Modules\Finance\Http\Controllers\BankReconciliationController;
use Modules\Finance\Http\Controllers\BankStatementController;
use Modules\Finance\Http\Controllers\FiscalPeriodController;
use Modules\Finance\Http\Controllers\JournalController;
use Modules\Finance\Http\Controllers\KasbonController;
use Modules\Finance\Http\Controllers\PaymentController;
use Modules\Finance\Http\Controllers\PettyCashFundController;
use Modules\Finance\Http\Controllers\PettyCashVoucherController;
use Modules\Finance\Http\Controllers\ProjectCostController;
use Modules\Finance\Http\Controllers\ReportController;
use Modules\Finance\Http\Controllers\RevenueRecognitionController;
use Modules\Finance\Http\Controllers\TaxController;
use Modules\Finance\Http\Controllers\TaxEqualizationController;
use Modules\Finance\Http\Controllers\TaxExportController;
use Modules\Finance\Http\Controllers\TaxObligationController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Chart of accounts
    Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:fin.view');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:fin.create');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->middleware('permission:fin.view');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->middleware('permission:fin.delete');

    // Taxes
    Route::get('taxes', [TaxController::class, 'index'])->middleware('permission:fin.view');
    Route::post('taxes', [TaxController::class, 'store'])->middleware('permission:fin.create');
    Route::get('taxes/{tax}', [TaxController::class, 'show'])->middleware('permission:fin.view');
    Route::put('taxes/{tax}', [TaxController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('taxes/{tax}', [TaxController::class, 'destroy'])->middleware('permission:fin.delete');

    // Kalender pajak — register kewajiban masa (PPh 21/23/final 4(2), PPN).
    // Generate hanya mencetak baris masa (idempoten) — fin.create; mencatat
    // setor/lapor adalah entri manual atas baris yang ada — fin.update. Tidak
    // ada yang menulis buku besar di sini, jadi fin.post tidak terlibat.
    Route::get('tax-obligations', [TaxObligationController::class, 'index'])->middleware('permission:fin.view');
    Route::post('tax-obligations/generate', [TaxObligationController::class, 'generate'])->middleware('permission:fin.create');
    Route::put('tax-obligations/{taxObligation}', [TaxObligationController::class, 'update'])->middleware('permission:fin.update');

    // Journal vouchers
    Route::get('journals', [JournalController::class, 'index'])->middleware('permission:fin.view');
    Route::post('journals', [JournalController::class, 'store'])->middleware('permission:fin.create');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->middleware('permission:fin.view');
    Route::put('journals/{journal}', [JournalController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('journals/{journal}', [JournalController::class, 'destroy'])->middleware('permission:fin.delete');
    // Posting JV manual adalah fin.approve, bukan fin.post: jurnal yang diketik
    // tangan bisa mengkredit rekening bank persis seperti pembayaran keluar,
    // jadi tahapnya sama — yang mengetik (fin.create) tidak boleh sekaligus
    // menjadi yang mengesahkannya ke buku besar.
    Route::post('journals/{journal}/post', [JournalController::class, 'post'])->middleware('permission:fin.approve');

    // AR termin invoices
    Route::get('ar-invoices', [ArInvoiceController::class, 'index'])->middleware('permission:fin.view');
    Route::post('ar-invoices', [ArInvoiceController::class, 'store'])->middleware('permission:fin.create');
    Route::get('ar-invoices/{arInvoice}', [ArInvoiceController::class, 'show'])->middleware('permission:fin.view');
    Route::put('ar-invoices/{arInvoice}', [ArInvoiceController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('ar-invoices/{arInvoice}', [ArInvoiceController::class, 'destroy'])->middleware('permission:fin.delete');
    Route::post('ar-invoices/{arInvoice}/submit', [ArInvoiceController::class, 'submit'])->middleware('permission:fin.update');
    Route::post('ar-invoices/{arInvoice}/approve', [ArInvoiceController::class, 'approve'])->middleware('permission:fin.approve');
    Route::post('ar-invoices/{arInvoice}/reject', [ArInvoiceController::class, 'reject'])->middleware('permission:fin.approve');
    Route::post('ar-invoices/{arInvoice}/faktur', [ArInvoiceController::class, 'faktur'])->middleware('permission:fin.update');
    // Membatalkan dokumen yang sudah berjurnal adalah fin.post, bukan
    // fin.approve: yang dilakukan adalah memposting jurnal pembalik.
    Route::post('ar-invoices/{arInvoice}/cancel', [ArInvoiceController::class, 'cancel'])->middleware('permission:fin.post');

    // AP bills
    Route::get('ap-bills', [ApBillController::class, 'index'])->middleware('permission:fin.view');
    Route::post('ap-bills', [ApBillController::class, 'store'])->middleware('permission:fin.create');
    Route::get('ap-bills/{apBill}', [ApBillController::class, 'show'])->middleware('permission:fin.view');
    Route::put('ap-bills/{apBill}', [ApBillController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('ap-bills/{apBill}', [ApBillController::class, 'destroy'])->middleware('permission:fin.delete');
    Route::post('ap-bills/{apBill}/submit', [ApBillController::class, 'submit'])->middleware('permission:fin.update');
    Route::post('ap-bills/{apBill}/approve', [ApBillController::class, 'approve'])->middleware('permission:fin.approve');
    Route::post('ap-bills/{apBill}/reject', [ApBillController::class, 'reject'])->middleware('permission:fin.approve');
    Route::post('ap-bills/{apBill}/cancel', [ApBillController::class, 'cancel'])->middleware('permission:fin.post');

    // Piutang retensi — withheld on termin invoices, collectible after the
    // warranty period. Releasing is fin.post: it books a receipt.
    Route::get('ar-retentions', [ArRetentionController::class, 'index'])->middleware('permission:fin.view');
    Route::post('ar-retentions/{arRetention}/release', [ArRetentionController::class, 'release'])->middleware('permission:fin.post');

    // Bank accounts
    Route::get('bank-accounts', [BankAccountController::class, 'index'])->middleware('permission:fin.view');
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->middleware('permission:fin.create');
    Route::get('bank-accounts/{bankAccount}', [BankAccountController::class, 'show'])->middleware('permission:fin.view');
    Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->middleware('permission:fin.delete');

    // Payments (RCV in / PAY out)
    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:fin.view');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:fin.create');
    // Registered ABOVE payments/{payment}: the wildcard binding would
    // otherwise swallow the literal path and 404 on "settleable-liabilities".
    Route::get('payments/settleable-liabilities', [PaymentController::class, 'settleableLiabilities'])->middleware('permission:fin.view');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:fin.view');
    Route::put('payments/{payment}', [PaymentController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->middleware('permission:fin.delete');
    // Pemisahan tugas pada uang keluar: PAY berjalan draft -> submitted ->
    // approved -> posted, sedangkan RCV tetap langsung diposting.
    // Submit is fin.update, not fin.create — the same reading as
    // ar-invoices/{id}/submit and ap-bills/{id}/submit above: preparing a
    // document for someone else to judge is not authorising it.
    Route::post('payments/{payment}/submit', [PaymentController::class, 'submit'])->middleware('permission:fin.update');
    Route::post('payments/{payment}/approve', [PaymentController::class, 'approve'])->middleware('permission:fin.approve');
    Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])->middleware('permission:fin.approve');
    Route::post('payments/{payment}/post', [PaymentController::class, 'post'])->middleware('permission:fin.post');
    // fin.post like ar-invoices/cancel and ap-bills/cancel: a reversal is a
    // posting act (it mints the mirror journal), not an approval one.
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('permission:fin.post');

    // Kas kecil (imprest) — dana per site/kantor dengan satu pemegang
    Route::get('petty-cash-funds', [PettyCashFundController::class, 'index'])->middleware('permission:fin.view');
    Route::post('petty-cash-funds', [PettyCashFundController::class, 'store'])->middleware('permission:fin.create');
    Route::get('petty-cash-funds/{pettyCashFund}', [PettyCashFundController::class, 'show'])->middleware('permission:fin.view');
    Route::put('petty-cash-funds/{pettyCashFund}', [PettyCashFundController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('petty-cash-funds/{pettyCashFund}', [PettyCashFundController::class, 'destroy'])->middleware('permission:fin.delete');
    // Replenish/return hanya MEMBUAT draf PAY/RCV — uangnya baru bergerak
    // setelah pembayarannya melewati rantai persetujuan yang sudah ada.
    Route::post('petty-cash-funds/{pettyCashFund}/replenish', [PettyCashFundController::class, 'replenish'])->middleware('permission:fin.create');
    Route::post('petty-cash-funds/{pettyCashFund}/return', [PettyCashFundController::class, 'returnToBank'])->middleware('permission:fin.create');

    // Voucher kas kecil (PCV)
    Route::get('petty-cash-vouchers', [PettyCashVoucherController::class, 'index'])->middleware('permission:fin.view');
    Route::post('petty-cash-vouchers', [PettyCashVoucherController::class, 'store'])->middleware('permission:fin.create');
    Route::get('petty-cash-vouchers/{pettyCashVoucher}', [PettyCashVoucherController::class, 'show'])->middleware('permission:fin.view');
    Route::put('petty-cash-vouchers/{pettyCashVoucher}', [PettyCashVoucherController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('petty-cash-vouchers/{pettyCashVoucher}', [PettyCashVoucherController::class, 'destroy'])->middleware('permission:fin.delete');
    // Posting voucher adalah fin.create + penjaga pemegang DI DALAM service —
    // penyimpangan yang disengaja dari doktrin "tulis buku besar = fin.post".
    // Memberi pemegang laci site fin.post berarti juga memberinya tutup buku
    // dan posting pembayaran bank; memaksa setiap bon parkir Rp 50.000 lewat
    // kantor pusat justru menciptakan kembali celah A3. Pagarnya: hanya
    // pemegang dana itu sendiri yang diterima service, plafon per bon, dan
    // saldo laci — mata kedua yang sesungguhnya membaca tumpukan bon saat
    // isi ulang disetujui (PaymentService).
    Route::post('petty-cash-vouchers/{pettyCashVoucher}/post', [PettyCashVoucherController::class, 'post'])->middleware('permission:fin.create');
    // Membatalkan dokumen yang sudah berjurnal adalah fin.post, bukan
    // fin.approve: yang dilakukan adalah memposting jurnal pembalik.
    Route::post('petty-cash-vouchers/{pettyCashVoucher}/cancel', [PettyCashVoucherController::class, 'cancel'])->middleware('permission:fin.post');

    // Kasbon (KSB) — uang muka kerja karyawan dari laci
    Route::get('kasbon', [KasbonController::class, 'index'])->middleware('permission:fin.view');
    Route::post('kasbon', [KasbonController::class, 'store'])->middleware('permission:fin.create');
    Route::get('kasbon/{kasbon}', [KasbonController::class, 'show'])->middleware('permission:fin.view');
    Route::put('kasbon/{kasbon}', [KasbonController::class, 'update'])->middleware('permission:fin.update');
    Route::delete('kasbon/{kasbon}', [KasbonController::class, 'destroy'])->middleware('permission:fin.delete');
    // fin.create + penjaga pemegang di service, alasan yang sama dengan
    // posting voucher di atas.
    Route::post('kasbon/{kasbon}/issue', [KasbonController::class, 'issue'])->middleware('permission:fin.create');
    Route::post('kasbon/{kasbon}/settle', [KasbonController::class, 'settle'])->middleware('permission:fin.create');

    // Periode fiskal / tutup buku
    Route::get('fiscal-periods', [FiscalPeriodController::class, 'index'])->middleware('permission:fin.view');
    Route::post('fiscal-periods/generate', [FiscalPeriodController::class, 'generate'])->middleware('permission:fin.create');
    Route::get('fiscal-periods/{fiscalPeriod}/checklist', [FiscalPeriodController::class, 'checklist'])->middleware('permission:fin.view');
    // Menutup periode adalah tindakan pembukuan: fin.post, sama seperti
    // memposting jurnal terakhir bulan itu.
    Route::post('fiscal-periods/{fiscalPeriod}/close', [FiscalPeriodController::class, 'close'])->middleware('permission:fin.post');
    // Membuka kembali mengubah angka yang sudah dilaporkan, jadi batasnya HARUS
    // lebih tinggi daripada yang membukanya: fin.approve. Siapa pun yang bisa
    // memposting tidak boleh bisa membuka sendiri periode yang ingin diisinya.
    Route::post('fiscal-periods/{fiscalPeriod}/reopen', [FiscalPeriodController::class, 'reopen'])->middleware('permission:fin.approve');

    // Project cost ledger
    Route::get('project-costs', [ProjectCostController::class, 'index'])->middleware('permission:fin.view');

    // Bank statements (rekening koran) — import and matching.
    // Matching is fin.update, not fin.post: it writes no ledger row. It records
    // that a bank movement and an existing posting are the same event.
    Route::get('bank-statements', [BankStatementController::class, 'index'])->middleware('permission:fin.view');
    Route::post('bank-statements/preview', [BankStatementController::class, 'preview'])->middleware('permission:fin.create');
    Route::post('bank-statements', [BankStatementController::class, 'store'])->middleware('permission:fin.create');
    Route::get('bank-statements/{bankStatement}', [BankStatementController::class, 'show'])->middleware('permission:fin.view');
    Route::delete('bank-statements/{bankStatement}', [BankStatementController::class, 'destroy'])->middleware('permission:fin.delete');
    Route::get('bank-statements/{bankStatement}/suggestions', [BankStatementController::class, 'suggestions'])->middleware('permission:fin.view');

    Route::get('bank-statement-lines/{bankStatementLine}/suggestions', [BankStatementController::class, 'lineSuggestions'])->middleware('permission:fin.view');
    Route::post('bank-statement-lines/{bankStatementLine}/match', [BankStatementController::class, 'match'])->middleware('permission:fin.update');
    Route::post('bank-statement-lines/{bankStatementLine}/unmatch', [BankStatementController::class, 'unmatch'])->middleware('permission:fin.update');
    Route::post('bank-statement-lines/{bankStatementLine}/no-match', [BankStatementController::class, 'noMatch'])->middleware('permission:fin.update');
    Route::post('bank-statement-lines/{bankStatementLine}/reopen', [BankStatementController::class, 'reopen'])->middleware('permission:fin.update');

    // Statutory tax exports (e-Faktur PPN keluaran, e-Bupot PPh dipotong)
    Route::get('tax-exports', [TaxExportController::class, 'index'])->middleware('permission:fin.view');
    Route::get('tax-exports/e-faktur', [TaxExportController::class, 'eFaktur'])->middleware('permission:fin.view');
    Route::get('tax-exports/e-bupot', [TaxExportController::class, 'eBupot'])->middleware('permission:fin.view');
    // Menerbitkan nomor bukti potong: POST, dan fin.approve — bukan fin.view.
    // Nomor ini adalah referensi hukum permanen yang dikutip vendor saat
    // mengkreditkan PPh-nya, dan ia lahir di ApBillService::approve(); jalur ini
    // hanya menyusulkan masa yang tagihannya disetujui sebelum kolomnya ada.
    // Sebelumnya penomoran terjadi di dalam ekspor e-Bupot, sehingga siapa pun
    // yang membuka laporan (atau sekadar melihat checklist tutup buku) ikut
    // menerbitkan nomor. Sebuah GET tidak boleh mengubah data.
    Route::post('tax-exports/e-bupot/numbers', [TaxExportController::class, 'issueBuktiPotongNumbers'])->middleware('permission:fin.approve');

    // Ekualisasi pajak — kertas kerja rekonsiliasi buku vs SPT per tahun
    // fiskal, untuk pemeriksa pajak / SP2DK. fin.view seperti laporan lain:
    // setiap angkanya turunan baca-saja dari jurnal terposting dan dokumen
    // sumber, dan selisih residunya dihitung — tidak pernah dipaksa nol.
    Route::get('tax-equalization', [TaxEqualizationController::class, 'index'])->middleware('permission:fin.view');

    // Reports
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('permission:fin.view');
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->middleware('permission:fin.view');
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('permission:fin.view');
    // Buku besar: rincian baris jurnal di balik satu baris neraca saldo.
    // fin.view seperti laporan lain — ia hanya membaca jurnal terposting, dan
    // menutupnya lebih rapat daripada neraca saldo justru tidak masuk akal:
    // orang yang boleh melihat saldo akhir sebuah akun harus boleh melihat
    // dari mana saldo itu berasal.
    Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->middleware('permission:fin.view');
    Route::get('reports/project-profitability/{projectId}', [ReportController::class, 'projectProfitability'])->middleware('permission:fin.view');
    Route::get('reports/ar-aging', [ReportController::class, 'arAging'])->middleware('permission:fin.view');
    Route::get('reports/ap-aging', [ReportController::class, 'apAging'])->middleware('permission:fin.view');
    // Arus kas (audit A3): laporan PSAK 2 metode langsung, proyeksi 90 hari,
    // dan saldo bank untuk dashboard — semuanya turunan baca-saja dari jurnal
    // terposting dan dokumen terbuka, jadi klasifikasinya fin.view seperti
    // neraca saldo dan aging di atas.
    Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->middleware('permission:fin.view');
    Route::get('reports/cash-projection', [ReportController::class, 'cashProjection'])->middleware('permission:fin.view');
    Route::get('reports/bank-balances', [ReportController::class, 'bankBalances'])->middleware('permission:fin.view');
    Route::get('reports/bank-reconciliation', [BankReconciliationController::class, 'show'])->middleware('permission:fin.view');
    // Pengakuan pendapatan PSAK 115 (persentase penyelesaian). Posting needs
    // fin.post — it writes to the general ledger.
    Route::get('revenue-recognition', [RevenueRecognitionController::class, 'index'])->middleware('permission:fin.view');
    Route::post('revenue-recognition', [RevenueRecognitionController::class, 'store'])->middleware('permission:fin.create');
    Route::get('revenue-recognition/{revenueRecognitionRun}', [RevenueRecognitionController::class, 'show'])->middleware('permission:fin.view');
    Route::post('revenue-recognition/{revenueRecognitionRun}/recalculate', [RevenueRecognitionController::class, 'recalculate'])->middleware('permission:fin.create');
    Route::post('revenue-recognition/{revenueRecognitionRun}/post', [RevenueRecognitionController::class, 'post'])->middleware('permission:fin.post');
    Route::delete('revenue-recognition/{revenueRecognitionRun}', [RevenueRecognitionController::class, 'destroy'])->middleware('permission:fin.delete');

    Route::get('reports/bank-reconciliation-overview', [BankReconciliationController::class, 'overview'])->middleware('permission:fin.view');
});
