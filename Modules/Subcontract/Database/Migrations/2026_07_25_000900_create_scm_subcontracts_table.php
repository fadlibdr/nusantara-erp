<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_subcontracts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // SPK/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('vendor_id'); // prc_vendors (must be is_subcontractor)
            $table->unsignedBigInteger('project_id')->nullable(); // prj_projects
            $table->string('title', 200);
            $table->text('scope')->nullable();
            $table->decimal('value', 18, 2)->default(0); // DPP = sum of item amounts
            $table->decimal('ppn_rate', 8, 4)->default(0); // 0 when the vendor is non-PKP
            $table->decimal('retention_pct', 8, 4)->default(5);
            $table->string('pph_scheme', 40); // PP 9/2022 classification key (config erp.tax.pph_final_construction)
            $table->decimal('pph_rate', 8, 4)->default(0); // snapshot of the statutory rate at creation
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('needs_director_approval')->default(false); // computed on submit vs config threshold
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('vendor_id');
            $table->index('project_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_subcontracts');
    }
};
