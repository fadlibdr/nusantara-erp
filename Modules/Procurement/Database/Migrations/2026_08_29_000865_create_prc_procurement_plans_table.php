<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2 — Rencana Pengadaan / Pola Belanja (PBL).
     *
     * Menutup deviasi 3.5 "Pola Belanja / Schedule / Monitoring Perolehan":
     * "Baris PO Terbuka" memonitor KOMITMEN yang sudah terjadi; ini adalah
     * RENCANA-nya, disusun dari RAP (cost budget) sebelum PR terbit — paket apa,
     * metode apa, kapan kontraknya ditargetkan, siapa PIC-nya.
     */
    public function up(): void
    {
        Schema::create('prc_procurement_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PBL/{Y}/{N4}
            // Referensi lintas modul (Projects/Estimation) — indeks tanpa constraint.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('cost_budget_id')->nullable(); // est_cost_budgets.id (RAP)
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft'); // draft|active|closed
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('cost_budget_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_procurement_plans');
    }
};
