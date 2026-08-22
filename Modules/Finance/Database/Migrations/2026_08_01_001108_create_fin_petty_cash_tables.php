<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kas kecil / kasbon (paket A3 "kas kecil / kasbon tidak ada").
 *
 * One imprest fund per site/office (fin_petty_cash_funds), each with its OWN
 * postable 1-11xx COA leaf — the fin_bank_accounts pattern — and ONE custodian.
 * The custodian keys expense vouchers (PCV) and employee advances (KSB) against
 * the drawer; the second pair of eyes sits at REPLENISHMENT, which rides the
 * existing PAY approval chain (see PaymentService).
 *
 * Numbering: 2026_08_01_001108 continues date-forward after 001107
 * (add_remark_to_fin_payment_allocations) — the 001100–001199 block per
 * docs/CONVENTIONS.md is exhausted, so Finance continues on dated slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_petty_cash_funds', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // user-entered, like fin_bank_accounts (KK-HO, KK-GRAHA)
            $table->string('name', 100);
            // The fund's OWN postable 1-11xx leaf. Unique: two drawers sharing
            // one account would make the per-fund imprest identity (GL balance
            // == float − outstanding bons) unauditable — the same reason bank
            // reconciliation refuses two bank accounts on one COA account.
            $table->foreignId('coa_account_id')->unique()->constrained('fin_accounts');
            $table->foreignId('custodian_id')->constrained('users');
            // Cross-module reference, unconstrained — the fin_project_costs pattern.
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->decimal('float_amount', 18, 2);
            $table->decimal('max_voucher_amount', 18, 2)->nullable(); // null = uncapped
            $table->decimal('max_kasbon_amount', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fin_petty_cash_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PCV/{Y}/{RM}/{N4}
            $table->foreignId('fund_id')->constrained('fin_petty_cash_funds');
            $table->date('voucher_date'); // period-dated: scanned by DanglingDocuments
            $table->string('category', 20); // PettyCashCategory
            $table->string('description', 500);
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('wbs_task_id')->nullable(); // prj_wbs_tasks, cross-module
            $table->string('status', 30)->default('draft'); // draft|posted|cancelled
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('posted_at')->nullable();
            // Stamped at replenishment SUBMIT: the frozen set of bons the
            // approver reviews before bank money moves. Unstamped on reject.
            $table->foreignId('replenishment_payment_id')->nullable()->constrained('fin_payments');
            // Mirrors fin_ar_invoices' cancellation columns (2026_07_28_001103).
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('voucher_date');
            $table->index('status');
        });

        Schema::create('fin_kasbons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // KSB/{Y}/{RM}/{N4}
            $table->foreignId('fund_id')->constrained('fin_petty_cash_funds');
            $table->unsignedBigInteger('employee_id')->index(); // hr_employees, cross-module
            $table->date('advance_date'); // period-dated: scanned by DanglingDocuments (draft only)
            $table->decimal('amount', 18, 2);
            $table->string('purpose', 500);
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('draft'); // draft|issued|settled
            $table->date('settlement_date')->nullable();
            // amount − Σ settlement lines; negative = extra cash paid out at settlement.
            $table->decimal('cash_returned', 18, 2)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // Settlement (pertanggungjawaban) lines: written once inside settle()'s
        // transaction, immutable after. Costs are recorded per LINE (reference
        // 'kasbon_line') so two same-category lines on different WBS tasks do
        // not collapse under ProjectCostService::record()'s idempotency key.
        Schema::create('fin_kasbon_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kasbon_id')->constrained('fin_kasbons')->cascadeOnDelete();
            $table->string('category', 20); // PettyCashCategory
            $table->string('description', 500);
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('wbs_task_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_kasbon_lines');
        Schema::dropIfExists('fin_kasbons');
        Schema::dropIfExists('fin_petty_cash_vouchers');
        Schema::dropIfExists('fin_petty_cash_funds');
    }
};
