<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: biaya penerapan SMKK sebuah RKK — TAUTAN ke baris BoQ, dan TIDAK ADA
 * KOLOM RUPIAH DI SINI.
 *
 * P6 sengaja meninggalkan biaya SMKK untuk paket ini, dan pertanyaannya bukan
 * "berapa" melainkan "baris mana". Biaya penerapan SMKK bukan anggaran kedua di
 * samping RAB: ia adalah baris-baris RAB itu sendiri (pada praktiknya satu
 * bagian "Pekerjaan Penerapan SMKK"). Sebuah kolom `amount` di tabel ini akan
 * menjadi angka kedua untuk uang yang sama, dan dua angka untuk satu uang
 * SELALU akhirnya berbeda — biasanya diketahui setelah lembar keselamatan
 * ditandatangani. Maka nilainya DITURUNKAN dari est_boq_items.amount saat
 * dibaca, dan menyunting RAB langsung terbaca pada RKK-nya.
 *
 * boq_item_id lintas modul tanpa constraint (CONVENTIONS §3), meski Crm →
 * Estimation adalah panah yang sah dan RkkService memang memakai model
 * BoqItem-nya. Aturan yang menjaganya: baris harus ADA, dan bila RKK menyebut
 * boq_id-nya, baris itu harus milik BoQ ITU — biaya SMKK yang menunjuk baris
 * RAB proyek lain bukan tautan sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_rkk_smkk_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rkk_id')->constrained('crm_rkk_documents')->cascadeOnDelete();
            $table->unsignedBigInteger('boq_item_id'); // est_boq_items.id
            $table->unsignedInteger('sort_order')->default(0);
            // Kategori komponen biaya penerapan SMKK menurut RKK-nya (mis.
            // "APD", "personel K3", "rambu & perlengkapan"). Label, bukan uang.
            $table->string('category', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['rkk_id', 'boq_item_id']);
            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_rkk_smkk_costs');
    }
};
