<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Project cost ledger (realisasi biaya proyek), compared against the RAP.
        // Fed by AP bill approvals here; payroll & material-issue postings from
        // the HrPayroll / Inventory modules land here through ProjectCostService.
        Schema::create('fin_project_costs', function (Blueprint $table): void {
            $table->id();
            // Cross-module reference (Projects) — indexed, no DB constraint.
            $table->unsignedBigInteger('project_id');
            $table->date('cost_date');
            $table->string('cost_category', 20); // material|labor|subcon|equipment|overhead
            // Source document morph strings, e.g. ('ap_bill', 3).
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description', 500);
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index('project_id');
            $table->index('cost_category');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_project_costs');
    }
};
