<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Temuan #34 tahap 1 — baris PO menunjuk baris BOQ yang dibelinya.
     *
     * Baris PR sudah membawa boq_item_id, baris PO belum: tautan anggaran mati
     * tepat pada dokumen yang justru memuat harga sesungguhnya. Tanpa kolom
     * ini kendali harga (#34 tahap 2) tidak punya harga BOQ pembanding, dan
     * jejak "PO ini membeli item RAB yang mana" harus ditebak dari deskripsi.
     *
     * Referensi lintas modul (Estimation) — indeks tanpa constraint DB, pola
     * yang sama dengan prc_purchase_requisition_items.boq_item_id: modul
     * Estimation boleh absen pada instalasi minimal.
     */
    public function up(): void
    {
        Schema::table('prc_purchase_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('boq_item_id')->nullable()->after('item_id'); // est_boq_items.id
            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('prc_purchase_order_items', function (Blueprint $table): void {
            $table->dropIndex(['boq_item_id']);
            $table->dropColumn('boq_item_id');
        });
    }
};
