<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vendor yang DIUNDANG menawar pada satu RFQ. Daftar ini adalah pagar
     * lembar banding: harga hanya boleh diketik untuk vendor di sini —
     * tanpa pagar itu sebuah harga "pemenang" bisa muncul dari vendor yang
     * tidak pernah diajak banding.
     */
    public function up(): void
    {
        Schema::create('prc_rfq_vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('prc_rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('prc_vendors');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_rfq_vendors');
    }
};
