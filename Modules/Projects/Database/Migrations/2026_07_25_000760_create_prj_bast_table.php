<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_bast', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->string('bast_type', 10); // bast1 (serah terima pertama) | bast2 (kedua)
            $table->date('handover_date');
            $table->string('customer_representative', 150)->nullable();
            $table->text('notes')->nullable();
            // BAST I: retention (retensi) release due at the end of the warranty period.
            $table->date('retention_release_due')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['project_id', 'bast_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_bast');
    }
};
