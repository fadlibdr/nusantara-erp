<?php

namespace Modules\Finance\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\NumberSequence;
use Modules\Core\Support\Terbilang;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\ProjectCost;

class FinanceDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Master data lives in dedicated seeders so ProductionSeeder can run
        // them without the demo documents below. Same order as before the
        // extraction: COA -> fiscal periods -> taxes (taxes look up COA rows).
        $this->call([
            ChartOfAccountsSeeder::class,
            FiscalPeriodSeeder::class,
            TaxSeeder::class,
        ]);

        $this->seedBankAccounts();
        $this->seedArInvoice();
        $this->seedApBill();
        $this->seedPayments();
        $this->syncNumberSequences();
    }

    private function seedBankAccounts(): void
    {
        $banks = [
            [
                'code' => 'BANK-BCA-OPS',
                'name' => 'BCA Operasional',
                'bank_name' => 'BCA',
                'account_no' => '5230456789',
                'account_name' => 'PT Nusantara Karya Integrasi',
                'coa_code' => '1-1210',
            ],
            [
                'code' => 'BANK-MDR-PRJ',
                'name' => 'Mandiri Proyek',
                'bank_name' => 'Mandiri',
                'account_no' => '1270009945671',
                'account_name' => 'PT Nusantara Karya Integrasi',
                'coa_code' => '1-1220',
            ],
        ];

        foreach ($banks as $bank) {
            $coaId = Account::query()->where('code', $bank['coa_code'])->value('id');

            if ($coaId === null) {
                continue;
            }

            BankAccount::withTrashed()->updateOrCreate(
                ['code' => $bank['code']],
                [
                    'name' => $bank['name'],
                    'bank_name' => $bank['bank_name'],
                    'account_no' => $bank['account_no'],
                    'account_name' => $bank['account_name'],
                    'coa_account_id' => $coaId,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * INV/2026/II/0001 — DP 20% of CTR/2026/I/0001 (Rp 48.5 M kontrak):
     * dpp 9.7 miliar + PPN 11% 1.067 miliar = 10.767 miliar, approved,
     * journaled, faktur pajak registered. No retention on a down payment.
     */
    private function seedArInvoice(): void
    {
        $contract = Schema::hasTable('crm_contracts')
            ? DB::table('crm_contracts')->where('code', 'CTR/2026/I/0001')->first()
            : null;

        if ($contract === null) {
            return; // CRM not seeded yet — skip gracefully
        }

        $contractId = (int) $contract->id;
        $customerId = (int) $contract->customer_id; // CUST-0001
        $projectId = $this->lookupId('prj_projects', 'PRJ-2026-001');

        $terminId = Schema::hasTable('crm_contract_termins')
            ? DB::table('crm_contract_termins')
                ->where('contract_id', $contractId)
                ->where('termin_no', 1)
                ->value('id')
            : null;

        $dpp = 9700000000.00;          // 20% x 48.5 miliar
        $ppnRate = (float) config('erp.tax.ppn_rate', 11.0);
        $ppn = round($dpp * $ppnRate / 100, 2); // 1,067,000,000
        $total = round($dpp + $ppn, 2);         // 10,767,000,000

        $invoice = ArInvoice::withTrashed()->updateOrCreate(
            ['code' => 'INV/2026/II/0001'],
            [
                'customer_id' => $customerId,
                'contract_id' => $contractId,
                'termin_id' => $terminId,
                'project_id' => $projectId,
                'invoice_date' => '2026-02-05',
                'due_date' => '2026-03-07', // 30 hari sesuai term CUST-0001
                'description' => 'Penagihan DP 20% — Pembangunan Gedung Kantor Graha Sentosa (CTR/2026/I/0001)',
                'dpp' => $dpp,
                'ppn_rate' => $ppnRate,
                'ppn_amount' => $ppn,
                'retention_withheld' => 0,
                'total' => $total,
                'amount_paid' => 0, // settled by seedPayments()
                'faktur_pajak_no' => '010.000-26.00000001',
                'terbilang' => Terbilang::rupiah($total),
                'paid_at' => null,
                'status' => 'approved',
            ],
        );

        $this->writeApprovalTrail($invoice, [
            ['action' => 'submitted', 'note' => null],
            ['action' => 'approved', 'note' => 'DP sesuai kontrak; jaminan uang muka sudah diterima.'],
        ]);

        // Revenue journal: Dr Piutang Usaha / Cr Pendapatan Konstruksi + PPN Keluaran.
        $this->seedJournal('JV/2026/02/0001', '2026-02-05',
            'Invoice INV/2026/II/0001 — DP 20% Gedung Graha Sentosa',
            'ar_invoice', (int) $invoice->id, [
                ['1-1300', $total, 0, 'Piutang INV/2026/II/0001', $projectId],
                ['4-1100', 0, $dpp, 'Pendapatan DP 20% CTR/2026/I/0001', $projectId],
                ['2-1300', 0, $ppn, 'PPN Keluaran INV/2026/II/0001', $projectId],
            ]);

        // Mark the termin billed (CRM seeds the same date; keep them aligned).
        if ($terminId !== null) {
            DB::table('crm_contract_termins')
                ->where('id', $terminId)
                ->update(['billed_at' => '2026-02-05']);
        }
    }

    /**
     * BIL/2026/III/0001 — vendor bill for the semen PO (PO/2026/II/0001,
     * VND-0001): dpp 209.5 jt + PPN 23.045 jt = 232.545 jt payable, approved,
     * journaled, and fed into the project cost ledger as material cost.
     */
    private function seedApBill(): void
    {
        $vendorId = $this->lookupId('prc_vendors', 'VND-0001');

        if ($vendorId === null) {
            return; // Procurement not seeded yet — skip gracefully
        }

        $poId = $this->lookupId('prc_purchase_orders', 'PO/2026/II/0001');
        $projectId = $this->lookupId('prj_projects', 'PRJ-2026-001');

        // Mirrors the PO seed math: 2.000 zak x 62.000 + 300 m3 x 285.000.
        $dpp = 209500000.00;
        $ppn = round($dpp * (float) config('erp.tax.ppn_rate', 11.0) / 100, 2); // 23,045,000
        $total = round($dpp + $ppn, 2); // 232,545,000 (no PPh on goods)

        $bill = ApBill::withTrashed()->updateOrCreate(
            ['code' => 'BIL/2026/III/0001'],
            [
                'vendor_id' => $vendorId,
                'project_id' => $projectId,
                'purchase_order_id' => $poId,
                'subcontract_claim_id' => null,
                'bill_date' => '2026-03-05',
                'due_date' => '2026-04-04', // term 30 hari VND-0001
                'description' => 'Tagihan semen & pasir beton PO/2026/II/0001 — PT Semen Distribusi Utama',
                'dpp' => $dpp,
                'ppn_amount' => $ppn,
                'pph_tax_id' => null,
                'pph_amount' => 0,
                'total_payable' => $total,
                'amount_paid' => 0, // settled by seedPayments()
                'vendor_invoice_no' => 'SDU/INV/2026/0345',
                'faktur_pajak_no' => '010.002-26.00012345',
                'paid_at' => null,
                'status' => 'approved',
            ],
        );

        $this->writeApprovalTrail($bill, [
            ['action' => 'submitted', 'note' => null],
            ['action' => 'approved', 'note' => 'GRN lengkap, harga sesuai PO; setuju bayar.'],
        ]);

        // Expense journal: Dr Beban Material + PPN Masukan / Cr Hutang Usaha.
        $this->seedJournal('JV/2026/03/0003', '2026-03-05',
            'Bill BIL/2026/III/0001 — material struktur PO/2026/II/0001',
            'ap_bill', (int) $bill->id, [
                ['5-1100', $dpp, 0, 'Beban material PO/2026/II/0001', $projectId],
                ['1-1600', $ppn, 0, 'PPN Masukan BIL/2026/III/0001', $projectId],
                ['2-1100', 0, $total, 'Hutang usaha BIL/2026/III/0001', $projectId],
            ]);

        // Realisasi biaya proyek (DPP only — PPN Masukan is recoverable).
        if ($projectId !== null) {
            ProjectCost::query()->updateOrCreate(
                [
                    'reference_type' => 'ap_bill',
                    'reference_id' => $bill->id,
                    'cost_category' => 'material',
                ],
                [
                    'project_id' => $projectId,
                    'cost_date' => '2026-03-05',
                    'description' => 'Semen & pasir beton PO/2026/II/0001 (BIL/2026/III/0001)',
                    'amount' => $dpp,
                ],
            );
        }
    }

    /**
     * RCV/2026/II/0001 — customer pays the DP invoice in full (in, Mandiri
     * Proyek). PAY/2026/IV/0001 — the semen bill is paid (out, BCA
     * Operasional). Both posted with their bank journals.
     */
    private function seedPayments(): void
    {
        $invoice = ArInvoice::query()->where('code', 'INV/2026/II/0001')->first();
        $bankIn = BankAccount::query()->where('code', 'BANK-MDR-PRJ')->first();

        if ($invoice !== null && $bankIn !== null) {
            $payment = Payment::withTrashed()->updateOrCreate(
                ['code' => 'RCV/2026/II/0001'],
                [
                    'direction' => 'in',
                    'payment_date' => '2026-02-27',
                    'bank_account_id' => $bankIn->id,
                    'amount' => (float) $invoice->total,
                    'reference' => 'CN 260227/4415 Bank Mandiri',
                    'notes' => 'Pelunasan DP 20% Gedung Graha Sentosa.',
                    'status' => 'posted',
                ],
            );

            $payment->allocations()->delete();
            $payment->allocations()->create([
                'payable_type' => PaymentAllocation::TYPE_AR_INVOICE,
                'payable_id' => $invoice->id,
                'amount' => (float) $invoice->total,
            ]);

            $invoice->forceFill([
                'amount_paid' => (float) $invoice->total,
                'paid_at' => '2026-02-27',
            ])->save();

            $this->seedJournal('JV/2026/02/0002', '2026-02-27',
                'Penerimaan RCV/2026/II/0001 — pelunasan INV/2026/II/0001',
                'payment', (int) $payment->id, [
                    ['1-1220', (float) $invoice->total, 0, 'Setoran masuk Bank Mandiri Proyek', $invoice->project_id],
                    ['1-1300', 0, (float) $invoice->total, 'Pelunasan INV/2026/II/0001', $invoice->project_id],
                ]);
        }

        $bill = ApBill::query()->where('code', 'BIL/2026/III/0001')->first();
        $bankOut = BankAccount::query()->where('code', 'BANK-BCA-OPS')->first();

        if ($bill !== null && $bankOut !== null) {
            $payment = Payment::withTrashed()->updateOrCreate(
                ['code' => 'PAY/2026/IV/0001'],
                [
                    'direction' => 'out',
                    'payment_date' => '2026-04-02',
                    'bank_account_id' => $bankOut->id,
                    'amount' => (float) $bill->total_payable,
                    'reference' => 'TRF BCA 0204/8891',
                    'notes' => 'Pembayaran tagihan semen & pasir beton PT Semen Distribusi Utama.',
                    'status' => 'posted',
                ],
            );

            $payment->allocations()->delete();
            $payment->allocations()->create([
                'payable_type' => PaymentAllocation::TYPE_AP_BILL,
                'payable_id' => $bill->id,
                'amount' => (float) $bill->total_payable,
            ]);

            // A freshly seeded demo shows a COMPLETED maker-checker on the one
            // disbursement it contains: prepared by Dewi Lestari (finance),
            // agreed by Ratna Kusumawardani (finance-manager). Without this the
            // first thing a reviewer sees on the payment screen is a posted
            // Rp 232.545.000 with an empty approval timeline.
            $this->writeApprovalTrail($payment, [
                ['action' => 'submitted', 'email' => 'finance@nusantara.test', 'note' => null],
                [
                    'action' => 'approved',
                    'email' => 'finance-manager@nusantara.test',
                    'note' => 'Tagihan cocok dengan PO/2026/II/0001 dan GRN; setuju bayar.',
                ],
            ]);

            $bill->forceFill([
                'amount_paid' => (float) $bill->total_payable,
                'paid_at' => '2026-04-02',
            ])->save();

            $this->seedJournal('JV/2026/04/0004', '2026-04-02',
                'Pembayaran PAY/2026/IV/0001 — pelunasan BIL/2026/III/0001',
                'payment', (int) $payment->id, [
                    ['2-1100', (float) $bill->total_payable, 0, 'Pembayaran BIL/2026/III/0001', $bill->project_id],
                    ['1-1210', 0, (float) $bill->total_payable, 'Transfer keluar BCA Operasional', $bill->project_id],
                ]);
        }
    }

    /**
     * Idempotent posted journal with balanced lines.
     * Lines: [account_code, debit, credit, description, project_id].
     */
    private function seedJournal(
        string $code,
        string $date,
        string $description,
        string $referenceType,
        int $referenceId,
        array $lines,
    ): void {
        $userId = User::query()->orderBy('id')->value('id');

        $journal = Journal::withTrashed()->updateOrCreate(
            ['code' => $code],
            [
                'journal_date' => $date,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'status' => 'posted',
                'posted_by' => $userId,
                'posted_at' => $date.' 09:00:00',
            ],
        );

        $journal->lines()->delete();

        foreach ($lines as [$accountCode, $debit, $credit, $lineDescription, $projectId]) {
            $accountId = Account::query()->where('code', $accountCode)->value('id');

            if ($accountId === null) {
                continue; // COA row missing — should not happen, seeded above
            }

            $journal->lines()->create([
                'account_id' => $accountId,
                'description' => $lineDescription,
                'debit' => round((float) $debit, 2),
                'credit' => round((float) $credit, 2),
                'project_id' => $projectId,
            ]);
        }
    }

    /**
     * Rebuild the approval trail idempotently so re-running the seeder does
     * not duplicate rows.
     */
    private function writeApprovalTrail(ArInvoice|ApBill|Payment $document, array $trail): void
    {
        // Per-row actor, because a trail whose two rows carry the same user id
        // is the fraud path this system now refuses — a seeded demo must not
        // show it as though it were normal. `email` is optional; a row without
        // one falls back to the lowest user id, which is how the older trails
        // in this seeder were written and what keeps them unchanged.
        $fallback = User::query()->orderBy('id')->value('id');

        $document->approvals()->delete();

        foreach ($trail as $entry) {
            $document->approvals()->create([
                'action' => $entry['action'],
                'user_id' => $this->seedUserId($entry['email'] ?? null) ?? $fallback,
                'note' => $entry['note'],
            ]);
        }
    }

    private function seedUserId(?string $email): ?int
    {
        if ($email === null) {
            return null;
        }

        $id = User::query()->where('email', $email)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Cross-module lookup by canonical seed code; null when the owning module
     * has not been migrated/seeded yet.
     */
    private function lookupId(string $table, string $code): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $id = DB::table($table)->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Seeded codes use explicit sequence numbers; move the 2026 counters past
     * them so runtime-generated numbers never collide with the canon.
     */
    private function syncNumberSequences(): void
    {
        $minimums = ['INV' => 1, 'BIL' => 1, 'RCV' => 1, 'PAY' => 1, 'JV' => 4];

        foreach ($minimums as $type => $minimum) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => 2026],
                ['last_number' => 0],
            );

            if ((int) $sequence->last_number < $minimum) {
                $sequence->update(['last_number' => $minimum]);
            }
        }
    }
}
