<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns other petty-cash pieces hang off:
 *
 *  - fin_payments.petty_cash_fund_id marks a PAY as a drawer top-up (or an RCV
 *    as a drawer return), so submit()/post() know the expected allocation shape
 *    and the SPA draws the fund card instead of the open-bills table. Ordinary
 *    payments keep it null and their behaviour bit-identical.
 *  - fin_project_costs.wbs_task_id carries the optional WBS attribution a
 *    voucher or kasbon line names, so realisasi can later be read per WBS task,
 *    not only per category. Nullable and unconstrained (prj_wbs_tasks is
 *    another module's table — the fin_project_costs pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_payments', 'petty_cash_fund_id')) {
            Schema::table('fin_payments', function (Blueprint $table): void {
                $table->foreignId('petty_cash_fund_id')
                    ->nullable()
                    ->constrained('fin_petty_cash_funds');
            });
        }

        if (! Schema::hasColumn('fin_project_costs', 'wbs_task_id')) {
            Schema::table('fin_project_costs', function (Blueprint $table): void {
                $table->unsignedBigInteger('wbs_task_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fin_payments', 'petty_cash_fund_id')) {
            Schema::table('fin_payments', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('petty_cash_fund_id');
            });
        }

        if (Schema::hasColumn('fin_project_costs', 'wbs_task_id')) {
            Schema::table('fin_project_costs', function (Blueprint $table): void {
                $table->dropColumn('wbs_task_id');
            });
        }
    }
};
