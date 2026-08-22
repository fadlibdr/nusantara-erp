<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log BBM & jam alat — the site register of fuel and hour-meter readings,
 * resolving deviasi #13 (owner decision, 22 Aug 2026).
 *
 * A REGISTER, NOT A LEDGER. Nothing here moves money, posts a journal or
 * touches stock: the fuel COST already flows through petty cash under the
 * BbmTol category, and a second money path would count the same solar twice.
 * This table records the operational half nobody was keeping — hours run and
 * litres drunk — per deployment, by the people already standing at the site.
 *
 * DELIBERATELY NO unique (deployment_id, log_date). A real site refuels the
 * excavator in the morning and tops it up in the afternoon; those are two
 * facts, and a unique key would force the second to overwrite the first or be
 * thrown away. Multiple rows per day are ordered by (log_date, id) — insertion
 * order within the day — and EquipmentLogService's monotonic guard holds
 * across that ordering.
 *
 * No softDeletes and no code column: this is a line register in the sense of
 * docs/CONVENTIONS.md §4, not a document header. There is no delete route to
 * soft-delete for — a register of readings is corrected by the next reading,
 * never by editing history (see EquipmentLogController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ast_equipment_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deployment_id')->constrained('ast_deployments');
            $table->date('log_date');
            // A READING off the gauge, not a delta. Utilisation math derives
            // deltas later, which is exactly why a reading that runs backwards
            // is refused at the service rather than averaged away here.
            $table->decimal('hour_meter', 15, 3)->nullable();
            $table->decimal('fuel_liters', 15, 3)->nullable();
            $table->text('notes')->nullable();
            // Same shape as fin_petty_cash's created_by: users are app-level,
            // migrated before every module, and never hard-deleted (the IAM
            // screen only deactivates), so the constraint cannot dangle.
            $table->foreignId('logged_by')->constrained('users');
            $table->timestamps();

            // The two ways the register is read: one deployment's trail in
            // date order, and a date window across deployments (listing()'s
            // date_from/date_to lands on log_date).
            $table->index(['deployment_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ast_equipment_logs');
    }
};
