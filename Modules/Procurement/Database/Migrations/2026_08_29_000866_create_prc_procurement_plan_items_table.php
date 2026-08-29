<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baris rencana pengadaan: satu paket belanja. Metode (pembelian langsung,
     * penunjukan langsung, seleksi/RFQ, tender) menentukan jalur pengadaannya;
     * target_contract_date adalah kapan kontrak/PO diharapkan terbit, dibaca
     * mundur dari jadwal proyek.
     */
    public function up(): void
    {
        Schema::create('prc_procurement_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('procurement_plan_id')->constrained('prc_procurement_plans')->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(1);
            $table->unsignedBigInteger('boq_item_id')->nullable(); // est_boq_items.id
            $table->string('package', 200); // paket belanja
            $table->string('method', 30)->default('rfq'); // ProcurementMethod
            $table->decimal('estimated_amount', 18, 2)->nullable();
            $table->date('target_contract_date')->nullable();
            $table->string('pic', 120)->nullable();
            $table->string('status', 30)->default('planned'); // planned|in_progress|contracted|cancelled
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_procurement_plan_items');
    }
};
