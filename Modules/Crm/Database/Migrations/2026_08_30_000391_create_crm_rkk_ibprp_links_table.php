<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: baris IBPRP sebuah RKK — TAUTAN ke prj_risk_register (P6), bukan salinan.
 *
 * MENGAPA TAUTAN. Sebuah salinan teks bahaya dan skornya akan membeku pada hari
 * ia disalin, dan lembar F/RKK kemudian mencetak penilaian risiko yang sudah
 * tidak dipakai siapa pun sementara registernya sudah bergerak — dua penilaian
 * risiko untuk satu pekerjaan, keduanya bertanda tangan. Satu register, satu
 * kebenaran; RKK menunjuk baris mana yang termasuk.
 *
 * risk_entry_id lintas modul: unsignedBigInteger + index, TANPA constraint
 * (CONVENTIONS §3). Yang menjaga tautannya tidak menggantung adalah
 * RkkService::syncIbprpLinks, yang membaca prj_risk_register MENTAH di balik
 * Schema::hasTable dan menolak id yang tidak ada dengan 422 yang menyebutnya —
 * karena sebuah FK tidak boleh menyeberang batas modul, dan sebuah tautan yang
 * tidak diperiksa akan mencetak baris kosong pada lembar keselamatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_rkk_ibprp_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rkk_id')->constrained('crm_rkk_documents')->cascadeOnDelete();
            $table->unsignedBigInteger('risk_entry_id'); // prj_risk_register.id
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['rkk_id', 'risk_entry_id']);
            $table->index('risk_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_rkk_ibprp_links');
    }
};
