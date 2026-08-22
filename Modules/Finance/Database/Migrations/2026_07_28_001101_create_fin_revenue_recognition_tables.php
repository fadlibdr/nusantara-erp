<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengakuan pendapatan PSAK 115 (persentase penyelesaian).
 *
 * Revenue was recognised when a termin invoice was approved — a BILLING basis.
 * PSAK 115 (the renumbered PSAK 72, IFRS 15) recognises construction and
 * system-integration revenue OVER TIME by progress; billing is merely a payment
 * schedule. On live data the gap is not academic: a 20% down payment put
 * Rp 9,7 miliar of revenue on the books for work that is 0,5% complete.
 *
 * A run is one accounting period. Its lines hold, per contract, the whole
 * calculation the auditor will ask for: price, EAC, cost to date, progress,
 * cumulative revenue, cumulative billing, and the contract asset/liability
 * position. Posting writes ONE adjusting journal moving the ledger from
 * billed-revenue to earned-revenue. See docs/KEBIJAKAN-PENDAPATAN.md.
 *
 * Numbering: Finance's 001100–001199 block was exhausted on 2026_07_25 and
 * continued date-forward per the note in 2026_07_26_001100. Next free: 001102.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        // Earned ahead of billing — becomes a receivable when invoiced.
        ['1-1360', 'Aset Kontrak (Pendapatan Belum Difakturkan)', 'asset', 'debit', '1-1000'],
        // Billed ahead of earning — the down payment lives here, not in revenue.
        ['2-1410', 'Liabilitas Kontrak (Penagihan Melebihi Pendapatan)', 'liability', 'credit', '2-1000'],
        // PSAK 237: the full expected loss on an onerous contract, at once.
        ['2-1700', 'Provisi Kerugian Kontrak', 'liability', 'credit', '2-1000'],
        ['5-1600', 'Beban Provisi Kerugian Kontrak', 'cogs', 'debit', '5-0000'],
    ];

    public function up(): void
    {
        Schema::create('fin_revenue_recognition_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('status', 20)->default('draft'); // PostingStatus
            $table->decimal('total_adjustment', 18, 2)->default(0);
            $table->text('notes')->nullable();
            // User semantics (users.id) — app-owned, no DB constraint.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // One recognition per period. Recalculating replaces the draft;
            // a second run for a posted period is a restatement, not a run.
            $table->unique(['period_year', 'period_month']);
        });

        Schema::create('fin_revenue_recognition_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('fin_revenue_recognition_runs')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('crm_contracts');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_type', 40);
            $table->string('revenue_account', 20);

            $table->decimal('transaction_price', 18, 2);      // DPP, CCO-inclusive
            $table->decimal('estimated_total_cost', 18, 2);   // EAC as used this run
            $table->string('eac_source', 40);                 // rap_approved | rap_unapproved | override | cost_floor | none
            $table->decimal('cost_to_date', 18, 2);
            $table->decimal('progress_pct', 8, 4);            // 0..100
            $table->decimal('revenue_cumulative', 18, 2);     // PSAK 115 earned to date
            $table->decimal('billed_cumulative', 18, 2);      // approved invoice DPP to date

            // earned − billed: >0 contract asset (1-1360), <0 liability (2-1410)
            $table->decimal('contract_balance', 18, 2);
            $table->decimal('provision_balance', 18, 2)->default(0);

            // What this run's journal moves, per account family. Kept on the
            // line so the journal can be reconstructed and audited per contract.
            $table->decimal('revenue_adjustment', 18, 2);
            $table->decimal('provision_adjustment', 18, 2)->default(0);

            $table->timestamps();

            $table->unique(['run_id', 'contract_id']);
        });

        // Installations that seeded the chart before these accounts existed.
        $this->insertAccounts();
    }

    private function insertAccounts(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type, $normal, $parentCode]) {
            $parent = DB::table('fin_accounts')->where('code', $parentCode)->value('id');

            if ($parent === null) {
                return;   // chart not seeded yet; the seeder will create them
            }

            if (DB::table('fin_accounts')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('fin_accounts')->insert([
                'code' => $code,
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $normal,
                'is_postable' => true,
                'is_active' => true,
                'parent_id' => $parent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_revenue_recognition_lines');
        Schema::dropIfExists('fin_revenue_recognition_runs');

        // Accounts are removed only while unused: one carrying postings is
        // somebody's balance sheet, and dropping it would orphan journal lines.
        foreach (self::ACCOUNTS as [$code]) {
            $account = DB::table('fin_accounts')->where('code', $code)->first();

            if ($account !== null && ! DB::table('fin_journal_lines')->where('account_id', $account->id)->exists()) {
                DB::table('fin_accounts')->where('id', $account->id)->delete();
            }
        }
    }
};
