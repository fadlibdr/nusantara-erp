<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CSAT tiket layanan (F-9) — kepuasan pelanggan atas SATU tiket yang sudah
 * selesai, dikumpulkan lewat tautan sekali pakai. Bentuknya sengaja meniru
 * `core_external_approvals` (migrasi Core 000180) baris per baris pada sisi
 * tokennya, karena masalahnya identik: memberi kapabilitas kepada seseorang
 * yang TIDAK punya akun di sistem ini.
 *
 * SATU BARIS ADALAH SATU UNDANGAN MENILAI, ditujukan kepada satu orang di
 * pihak pelanggan dengan namanya. Setelah dipakai, baris yang sama membawa
 * penilaiannya (`score`, `comment`, `rated_at`, `rated_via`): bukti undangan
 * dan bukti penilaian tidak bisa saling lepas, persis alasan yang ditulis
 * migrasi persetujuan eksternal.
 *
 * TOKEN TIDAK PERNAH DISIMPAN. Kolomnya sha256 dari token; teks polosnya
 * tampil tepat sekali di respons penerbitan lalu hilang dari server. Tidak ada
 * endpoint "lihat ulang tautan", dan tidak satu pun jalur log menyentuh teks
 * polosnya. Nullable + unique karena NULL ganda sah di MySQL maupun SQLite —
 * warisan bentuk yang sama; hari ini setiap baris memang punya token, dan
 * `CsatServiceTest` memaku bahwa tidak ada pintu kedua yang menulis baris
 * tanpa token (lihat `rated_via` di bawah).
 *
 * ticket_id memakai constrained(): svc_tickets adalah tabel modul INI
 * (CONVENTIONS §3 — FK di dalam modul sendiri, tanpa FK lintas modul). Tidak
 * ada customer_id di sini meskipun tiketnya punya: satu salinan kedua dari
 * kolom yang sama adalah satu tempat lagi yang bisa berselisih, dan setiap
 * kueri ringkasan sudah harus menyentuh svc_tickets untuk statusnya.
 *
 * score unsignedTinyInteger 1..5, NULLABLE — dan itulah seluruh perangkap
 * paket ini. Tiket yang belum dinilai TIDAK punya skor; ia bukan nol bintang.
 * Rata-rata dihitung HANYA atas baris ber-rated_at, dan tidak satu pun
 * permukaan boleh menuliskan 0 untuk yang kosong (CsatService::summary()).
 *
 * comment adalah TEKS BEBAS PELANGGAN TENTANG SEORANG TEKNISI YANG NAMANYA
 * ADA DI TIKET. Ia hidup di gerbang yang sama dengan tiketnya (`svc.view`) dan
 * tidak pernah di gerbang yang lebih longgar: tidak di daftar tiket, tidak di
 * lookup, tidak di pencarian global, tidak di lonceng. Lihat docblock
 * CsatService dan docs/LAPORAN-PAKET-HM-F-9.md §"Keputusan pemilik".
 *
 * rated_via: hari ini SATU nilai saja, 'link'. Kolomnya tetap ada — sama
 * seperti `decided_via` pada persetujuan eksternal — supaya ketiadaan pintu
 * kedua terbaca di lapisan penyimpanan, bukan hanya di dokumen: penilaian yang
 * DIKETIKKAN staf atas nama pelanggan (lewat telepon) sengaja TIDAK dibangun,
 * karena orang yang dinilai memegang keyboard yang sama. Bila kelak pintu itu
 * dibuka, ia menulis nilai lain di kolom ini dan setiap rata-rata bisa
 * memisahkannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svc_csat_ratings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ticket_id')->constrained('svc_tickets');

            // Kepada siapa undangan menilai diterbitkan — arsip, bukan akun.
            $table->string('recipient_name', 120);
            $table->string('recipient_email', 150)->nullable();

            $table->char('token_hash', 64)->nullable()->unique();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');

            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');

            // 1..5 — NULL selama belum dinilai. NULL ≠ 0.
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('comment', 1000)->nullable();
            $table->dateTime('rated_at')->nullable();
            $table->string('rated_via', 10)->nullable();

            $table->timestamps();

            // Ringkasan CSAT menyaring "yang sudah dinilai" per tiket.
            $table->index(['ticket_id', 'rated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_csat_ratings');
    }
};
