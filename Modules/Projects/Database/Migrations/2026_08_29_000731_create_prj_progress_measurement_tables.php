<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 — OPNAME KE PEMILIK (berita acara pengukuran volume), the revenue-side
 * mirror of scm_progress_claims.
 *
 * The subcontractor opname has existed since the first release and measures
 * PERCENT per SPK line; this one measures VOLUME per BOQ item, because that is
 * what the owner's MK signs and what the backsheet of an owner claim has to
 * show. Percent cannot be substituted: a claim of "65 %" on a Rp 12 miliar
 * concrete package is not a measurement, it is an opinion, and the ceiling the
 * item table enforces (qty_cum ≤ contract qty + approved CCO qty) has no
 * meaning at all in percent.
 *
 * PER CONTRACT, not per project. The contract is what carries the BOQ, the
 * termin schedule and the addenda; a project is one delivery of it. project_id
 * rides along because every screen and every printed kop is organised by
 * project, and because the S-curve reads it — but the plafon, the CCO and the
 * AR invoice all hang off the contract.
 *
 * THE SNAPSHOT COLUMNS (description, unit, unit_price) are deliberate. A BOQ
 * can be revised — BoqService::revise clones it at version + 1 — and an opname
 * already approved must keep saying what was measured at the price it was
 * measured at, exactly like every other document in this codebase snapshots
 * the rate it charged. Without them the printed backsheet of an approved
 * opname would silently change the day somebody re-prices the RAB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_progress_measurements', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // OPN/{Y}/{RM}/{N4}
            // Inside the module: a real constraint. Across modules (Crm): a
            // bare indexed column, CONVENTIONS §3.
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id'); // crm_contracts
            $table->unsignedInteger('measurement_no'); // opname sequence per contract (1, 2, ...)
            $table->date('period_start');
            $table->date('period_end');
            // Sum of the period value of every line (qty_this x unit_price) —
            // what the owner claim built from this opname bills as its DPP.
            $table->decimal('period_amount', 18, 2)->default(0);
            // Cumulative value measured to date (qty_cum x unit_price), which is
            // what the value-weighted actual_pct divides by the BOQ total.
            $table->decimal('cumulative_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contract_id', 'measurement_no']);
            $table->index('contract_id');
            $table->index('status');
            $table->index('period_end');
        });

        Schema::create('prj_progress_measurement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('progress_measurement_id')
                ->constrained('prj_progress_measurements')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('boq_item_id');            // est_boq_items
            $table->unsignedBigInteger('location_id')->nullable(); // core_locations — optional, per the spec
            // Snapshots — see the class docblock for why they are not joins.
            $table->string('description', 500);
            $table->string('unit', 20);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('qty_prev', 15, 3)->default(0);  // cumulative before this opname
            $table->decimal('qty_this', 15, 3)->default(0);  // measured this period
            $table->decimal('qty_cum', 15, 3)->default(0);   // qty_prev + qty_this
            $table->decimal('amount', 18, 2)->default(0);    // qty_this x unit_price
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            // Nama eksplisit: nama otomatis Laravel untuk pasangan ini 73
            // karakter, MySQL membatasi pengenal 64 (ditemukan migrate:fresh
            // di MySQL 5 Sep 2026, Fase 0 T0.2). SQLite tidak peduli namanya.
            $table->unique(['progress_measurement_id', 'boq_item_id'], 'prj_progress_measurement_items_pm_boq_unique');
            $table->index('boq_item_id');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_progress_measurement_items');
        Schema::dropIfExists('prj_progress_measurements');
    }
};
