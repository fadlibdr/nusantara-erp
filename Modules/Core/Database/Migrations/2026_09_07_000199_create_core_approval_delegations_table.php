<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DELEGASI "a.n." — Budi menyetujui atas nama Sari selama Sari cuti (F-1).
 *
 * Yang terjadi tanpa tabel ini sudah diketahui: dokumen menumpuk sampai
 * direkturnya kembali (diukur 4 Sep 2026: PAY/2026/VIII/0002 menunggu 33 hari),
 * atau — jauh lebih buruk — kata sandi dipinjamkan, dan seluruh jejak
 * persetujuan aplikasi ini menjadi fiksi. Delegasi yang tercatat adalah satu-
 * satunya jawaban yang tidak merusak jejaknya.
 *
 * JENDELA, BUKAN SAKLAR. starts_at/ends_at adalah TANGGAL dan inklusif di
 * kedua ujung (Asia/Jakarta): "10 sampai 20 September" berarti apa yang
 * dikatakannya. ends_at null = sampai dicabut, yang sah tetapi harus terlihat
 * di layar sebagai delegasi tanpa akhir.
 *
 * scope null = setiap hak approve yang DIPEGANG pemberinya; scope 'prc' =
 * hanya prc.approve / prc.approve-director. Awalan modul, bukan daftar
 * dokumen: hak yang didelegasikan adalah izin, dan izinlah yang diperiksa.
 *
 * YANG TABEL INI TIDAK PERNAH BERIKAN: apa pun selain persetujuan.
 * Core\Support\ApprovalDelegations menyaring ability terhadap pola
 * <awalan>.approve / <awalan>.approve-director sebelum melihat satu baris pun,
 * jadi delegasi tidak pernah menjadi jalan memutar untuk membuat, mengubah,
 * menghapus atau memposting apa pun. Delegasi juga tidak berantai: pemberinya
 * harus memegang izin itu SENDIRI (bukan lewat delegasi lain), kalau tidak
 * sebuah rantai tiga orang akan memberi hak direktur kepada orang yang tidak
 * pernah dipilih siapa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_approval_delegations', function (Blueprint $table): void {
            $table->id();

            // Pemberi dan penerima adalah LOGIN, bukan pegawai: yang
            // didelegasikan adalah izin, dan izin menempel pada akun.
            // cascadeOnDelete: sebuah delegasi tanpa pemberi bukan delegasi
            // yang berkurang artinya — ia adalah hak tanpa asal.
            $table->foreignId('giver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();

            // Awalan modul ('prc', 'scm', …) atau null = seluruh hak approve
            // yang dipegang pemberinya.
            $table->string('scope', 20)->nullable();

            $table->date('starts_at');
            $table->date('ends_at')->nullable();

            // Alasan yang terbaca manusia ("cuti tahunan 10–20 Sep"), tercetak
            // di banner penerima dan di layar pemberinya.
            $table->string('reason', 200)->nullable();

            // Dicabut, bukan dihapus: sebuah delegasi yang pernah hidup adalah
            // penjelasan bagi setiap baris "a.n." yang ditinggalkannya.
            $table->timestamp('revoked_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            // Pertanyaan panas: "delegasi hidup apa yang dipegang orang ini?"
            // — ditanyakan Gate::before pada setiap pemeriksaan izin approve.
            $table->index(['delegate_user_id', 'revoked_at']);
            $table->index(['giver_user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_approval_delegations');
    }
};
