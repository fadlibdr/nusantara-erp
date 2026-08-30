<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: register dokumen lelang — SATU BARIS PER DOKUMEN yang diterima.
 *
 * Sebuah register adalah DAFTAR, jadi ia berbentuk kepala + baris, bukan empat
 * kolom pada kepala paketnya. Judul, bab, tanggal terbit, dan addendum ke-n
 * adalah atribut sebuah dokumen — dan satu lelang menerbitkan dokumen asli
 * plus n addendum, masing-masing dengan tanggalnya sendiri.
 *
 * addendum_no NULL = terbitan asli. 1..n = addendum ke berapa dokumen itu
 * datang. TenderPackageService memaksa nomor addendum BERURUTAN dari 1 tanpa
 * lompatan: register yang memuat "Addendum 3" tanpa Addendum 2 berarti ada satu
 * dokumen yang tidak pernah kita terima, dan penawaran yang disusun di atasnya
 * disusun di atas informasi yang hilang. 422-nya menyebut nomor yang bolong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tender_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tender_package_id')->constrained('crm_tender_packages')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('title', 250);                        // judul dokumen
            $table->string('chapter', 120)->nullable();          // bab / bagian
            $table->date('issued_date');                         // tanggal terbit
            $table->unsignedSmallInteger('addendum_no')->nullable(); // null = terbitan asli
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tender_package_id', 'addendum_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tender_documents');
    }
};
