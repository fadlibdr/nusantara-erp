<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aktivitas CRM (F-3) — pekerjaan penjualan yang punya tanggal dan pemilik.
 *
 * Sampai paket ini, satu-satunya tanggal di seluruh corong awal adalah
 * crm_leads.next_follow_up_at: SATU tanggal per prospek, diketik tangan, tanpa
 * jejak siapa yang harus menelepon dan tanpa cara mencatat bahwa teleponnya
 * sudah dilakukan. Menggesernya berarti menghapus rencana sebelumnya, dan
 * "sudah saya telepon minggu lalu" tidak punya tempat untuk ditulis sama
 * sekali. Tabel ini adalah tempat itu, dan sejak migrasi 000396 tanggal pada
 * prospek DITURUNKAN dari baris-baris di sini.
 *
 * TIGA KEPUTUSAN YANG DIJELASKAN DI SINI KARENA KEDUANYA TERLIHAT DI LAYAR.
 *
 *  - `due_at` bertipe DATE, bukan datetime. "Hubungi lagi Senin depan" adalah
 *    sebuah HARI; menyimpannya sebagai 00:00 memalsukan ketelitian yang tidak
 *    pernah diketik siapa pun (alasan yang sama tertulis di migrasi 000383
 *    untuk next_follow_up_at, dan turunannya kini harus setipe dengan
 *    sumbernya). `done_at` sebaliknya BERTIPE WAKTU: ia dicap oleh server
 *    pada saat seseorang menekan "Selesai" — itu momen yang sungguhan terjadi.
 *
 *  - `document_type` + `document_id` menyimpan STRING PENDEK ('lead',
 *    'quotation', 'customer'), bukan nama kelas — pola yang sama dengan
 *    fin_payment_allocations.payable_type dan dengan alasan yang sama
 *    (AttachableDocuments): sebuah endpoint yang menerima nama kelas
 *    mengizinkan penelepon menyebut kelas apa pun sebagai induk sebuah baris.
 *    Daftar sahnya hidup di Modules\Crm\Support\ActivityDocuments.
 *
 *  - Tanpa `code`. Ini register, bukan dokumen: tidak ada nomor, tidak ada
 *    persetujuan, tidak ada jurnal — sama seperti crm_guarantees. Yang
 *    dikenali orang adalah subjeknya.
 *
 * Rujukan lintas modul ke users.id (owner_user_id, done_by_id) tanpa
 * constrained(), aturan §2 CONVENTIONS. document_id juga tanpa FK: ia menunjuk
 * tiga tabel yang berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->id();

            // Dokumen tempat aktivitas ini menggantung.
            $table->string('document_type', 20);
            $table->unsignedBigInteger('document_id');

            $table->string('type', 20);
            $table->string('subject');
            $table->text('notes')->nullable();

            // Rencana (hari) dan kenyataan (saat).
            $table->date('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unsignedBigInteger('done_by_id')->nullable();

            // Siapa yang harus mengerjakannya. Boleh kosong — "Belum
            // ditugaskan" adalah keadaan yang sungguhan ada, dan menebak
            // pemiliknya dari pembuat barisnya adalah karangan.
            $table->unsignedBigInteger('owner_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Kartu di layar prospek/penawaran/pelanggan membaca lewat pasangan
            // ini; ia juga saringan turunan next_follow_up_at.
            $table->index(['document_type', 'document_id']);
            // Antrean "aktivitas saya" dan pengawas jatuh tempo.
            $table->index('owner_user_id');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
