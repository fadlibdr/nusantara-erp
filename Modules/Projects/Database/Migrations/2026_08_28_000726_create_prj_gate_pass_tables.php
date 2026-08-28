<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-C: Izin Masuk/Keluar Material & Peralatan (IMK, Form F/IM) menjadi
 * transaksi — header + baris barang.
 *
 * Dua tahap yang urutannya DITEGAKKAN service: manajemen menyetujui dulu
 * (Approvable, prj.approve), baru gerbang MEMERIKSA muatan yang lewat —
 * checked_by/checked_at diisi oleh aksi 'periksa' dan hanya sah pada izin
 * berstatus approved. Satpam memeriksa izin yang sudah disetujui, bukan
 * menyetujui sambil memeriksa.
 *
 * pass_date adalah baris TANGGAL pada lembarnya — tanpa kolom ini lembar izin
 * gerbang tidak bisa mencetak kapan muatan itu lewat.
 *
 * Rujukan silang, kontrak shared-ID §3 (indeks tanpa FK): vendor_id →
 * prc_vendors (counterparty teks untuk pihak yang bukan vendor terdaftar),
 * goods_receipt_id → inv_goods_receipts, transfer_id → inv_transfers,
 * item_id → inv_items, checked_by → users (petugas yang menekan 'periksa').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_gate_passes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // IMK/{Y}/{RM}/{N4}
            $table->foreignId('project_id')->constrained('prj_projects');
            $table->string('direction', 10); // GatePassDirection: in/out
            $table->date('pass_date');
            $table->string('vehicle_no', 20)->nullable();
            $table->string('driver_name', 150)->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable(); // prc_vendors — indexed, no FK
            $table->string('counterparty', 200)->nullable(); // asal/tujuan bila bukan vendor terdaftar
            $table->unsignedBigInteger('goods_receipt_id')->nullable(); // inv_goods_receipts — indexed, no FK
            $table->unsignedBigInteger('transfer_id')->nullable(); // inv_transfers — indexed, no FK
            $table->unsignedBigInteger('checked_by')->nullable(); // users — diisi aksi 'periksa'
            $table->dateTime('checked_at')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('vendor_id');
            $table->index('goods_receipt_id');
            $table->index('transfer_id');
            $table->index(['project_id', 'pass_date']);
        });

        Schema::create('prj_gate_pass_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gate_pass_id')->constrained('prj_gate_passes')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id')->nullable(); // inv_items — indexed, no FK
            $table->string('description', 200);
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20);
            $table->string('notes', 200)->nullable(); // kolom KETERANGAN lembar F/IM
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_gate_pass_items');
        Schema::dropIfExists('prj_gate_passes');
    }
};
