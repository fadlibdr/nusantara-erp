<?php

/**
 * Fixture S38 (P-3b, putaran verifikasi kedua): satu pelanggan ber-NPWP 22 digit
 * (NITKU) dengan invoice DISETUJUI ber-nomor faktur pada masa S38 — lewat
 * PIPELINE SUNGGUHAN (ArInvoiceService create → submit → approve →
 * registerFakturPajak), bukan sisipan sqlite. Dijalankan harness-playwright.py
 * atas ERP_DB (SALINAN) dengan env DB_DATABASE. Idempoten: pelanggan/kontrak/
 * invoice yang sudah ada dipakai ulang.
 *
 *   php docs/bukti-uji/fixtures/s38-nitku.php <tahun> <bulan>
 *
 * Keluaran: satu baris JSON {customer, contract, invoice, status, faktur}.
 */
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\ArInvoiceService;

$year = (int) ($argv[1] ?? 2026);
$month = (int) ($argv[2] ?? 7);

$customer = Customer::query()->firstOrCreate(['code' => 'CUST-S38'], [
    'name' => 'PT Cabang NITKU (fixture S38)',
    'npwp' => '0012345678011000000002',
    'is_pkp' => true,
    'payment_term_days' => 30,
    'status' => 'active',
    'billing_address' => 'Jl. Fixture S38 No. 22',
    'city' => 'Bekasi',
    'province' => 'Jawa Barat',
]);
$contract = Contract::query()->firstOrCreate(['customer_id' => $customer->id, 'title' => 'Kontrak fixture S38'], [
    'scope_type' => 'construction',
    'value' => 1_000_000_000,
    'ppn_rate' => 11.0,
    'retention_pct' => 5.0,
    'warranty_months' => 6,
    'status' => 'approved',
]);

$invoice = ArInvoice::query()
    ->where('customer_id', $customer->id)
    ->where('description', 'Termin NITKU (fixture S38)')
    ->first();

if ($invoice === null) {
    $submitter = User::query()->where('email', 'finance@nusantara.test')->firstOrFail();
    $approver = User::query()->where('email', 'direktur@nusantara.test')->firstOrFail();
    $service = app(ArInvoiceService::class);

    $invoice = $service->create([
        'customer_id' => $customer->id,
        'contract_id' => $contract->id,
        'description' => 'Termin NITKU (fixture S38)',
        'dpp' => 100_000_000,
        'ppn_rate' => 11.0,
        'invoice_date' => sprintf('%04d-%02d-15', $year, $month),
    ]);
    $invoice->submit($submitter);
    $invoice = $service->approve($invoice, $approver);
    $invoice = $service->registerFakturPajak($invoice, sprintf('010.000-%02d.%08d', $year % 100, $invoice->id));
}

echo json_encode([
    'customer' => $customer->code,
    'contract' => $contract->id,
    'invoice' => $invoice->code,
    'status' => $invoice->status->value,
    'faktur' => $invoice->faktur_pajak_no,
]), "\n";
