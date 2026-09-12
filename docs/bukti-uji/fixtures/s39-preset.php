<?php

/**
 * Fixture S39 (P-3c): preset impor per rekening untuk BANK-BCA-OPS, disimpan
 * lewat PIPELINE SUNGGUHAN (BankStatementImportService::savePreset — parse +
 * tie-out atas berkas contoh demo docs/samples/rekening-koran-bca-2026-04.csv,
 * pemetaan dari docs/samples/README.md), bukan sisipan sqlite. Dijalankan
 * harness-playwright.py atas ERP_DB (SALINAN) dengan env DB_DATABASE.
 * Idempoten: menyimpan lagi menimpa preset yang sama.
 *
 *   php docs/bukti-uji/fixtures/s39-preset.php
 *
 * Keluaran: satu baris JSON {account, preset, expected_header, balance_column}.
 * Berkas contoh demo dipakai untuk membuktikan JALUR folder terpantau, bukan
 * tata letak bank mana pun (docs/samples/bank/README.md §5).
 */
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Services\BankStatementImportService;

// `use` SEBELUM bootstrap: Kernel::class di baris bootstrap dibaca saat kompilasi, dan pint
// (ordered_imports) memindahkan import ke atas — import yang berada sesudah pemakaiannya
// menjatuhkan fixture dengan "Class \"Kernel\" does not exist" (terukur 12 Sep 2026).
$root = dirname(__DIR__, 3);   // docs/bukti-uji/fixtures → akar repo
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$account = BankAccount::query()->where('code', 'BANK-BCA-OPS')->firstOrFail();
$admin = User::query()->where('email', 'admin@nusantara.test')->firstOrFail();
$csv = (string) file_get_contents($root.'/docs/samples/rekening-koran-bca-2026-04.csv');

$preset = app(BankStatementImportService::class)->savePreset($account, 'BCA contoh demo (S39)', 'csv', $csv, [
    'delimiter' => ';', 'skip_rows' => 1, 'date_column' => 0, 'date_format' => 'dd/mm/yyyy', 'description_column' => 1,
    'amount_mode' => 'debit_credit', 'debit_column' => 3, 'credit_column' => 4, 'balance_column' => 5, 'number_format' => 'id',
    'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'opening_balance' => 0, 'closing_balance' => -232_795_000,
], $admin->id);

echo json_encode([
    'account' => $account->code,
    'preset' => $preset['name'],
    'expected_header' => $preset['expected_header'],
    'balance_column' => $preset['mapping']['balance_column'] ?? null,
]), "\n";
