<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sampai kapan sebuah lampiran masih berlaku (F-8).
 *
 * Ada dokumen yang isinya benar hanya sampai sebuah tanggal: polis asuransi
 * CAR yang menempel di SPK, STNK dan KIR yang difoto pada kartu aset, sertifikat
 * kalibrasi alat ukur pada lembar inspeksi, surat izin kerja yang discan pada
 * IKL. Sebelum kolom ini tanggal itu hanya hidup di dalam berkasnya — untuk
 * mengetahuinya seseorang harus mengunduh dan membukanya, yang berarti tidak
 * ada seorang pun yang mengetahuinya sampai ada yang bertanya.
 *
 * NULLABLE, DAN NULL ADALAH KEADAAN NORMAL — bukan "lupa diisi".
 *
 * Hampir semua baris tabel ini tidak akan pernah punya tanggal ini: foto
 * lapangan, nota warung, gambar kerja, selfie absen (F-4) — dan tabel inilah
 * yang tumbuh paling cepat di aplikasi, satu baris per foto. Karena itu kolom
 * ini TIDAK memakai pola `alarm_when_date_missing` yang dipakai PKWT
 * (hr_employees.pkwt_end_date) dan servis aset (ast_maintenances.next_due_date):
 * di sana tanggal kosong memang berarti sebuah kelalaian, di sini ia berarti
 * berkas biasa. Pengawas yang meneriaki setiap foto lapangan karena tak satu
 * pun punya masa berlaku adalah pengawas yang dimatikan orang pada hari
 * pertama. Lihat WatchedDeadlines entri attachment_valid_until_* dan
 * CONVENTIONS §37.
 *
 * INDEKS PASANGAN (attachable_type, valid_until) — DIUKUR, BUKAN DITEBAK.
 *
 * Pemindai tenggat menanyakan satu kueri per modul:
 * `attachable_type IN (…kelas modul itu…) AND valid_until <batas>`. Indeks satu
 * kolom `valid_until` tampak cukup — sampai diukur. Atas 40.000 baris tiruan
 * (38.577 di antaranya foto laporan harian, 59 baris bertanggal), 9 Sep 2026:
 *
 *   SQLite, TANPA ANALYZE (keadaan produksi — repo ini tidak pernah menjalankannya)
 *     indeks (valid_until)                : SEARCH … USING INDEX …attachable_type…  14,689 ms
 *     indeks (attachable_type, valid_until): SEARCH … USING COVERING INDEX …          0,024 ms
 *   MySQL 8 (erp_scratch, sesudah ANALYZE TABLE)
 *     tanpa indeks baru                   : type=ALL   rows=39.844                   44,530 ms
 *     indeks (valid_until)                : type=range rows=34, Using index condition  0,276 ms
 *     indeks (attachable_type, valid_until): type=range rows=35, Using index           0,382 ms
 *
 * Perencana SQLite tanpa statistik SELALU memilih indeks kesetaraan yang sudah
 * ada — (attachable_type, attachable_id) — lalu menyaring valid_until baris demi
 * baris atas 38.577 foto; indeks `valid_until` sendirian tidak pernah
 * tersentuh. Hanya pasangan berawalan attachable_type yang dipakai kedua driver
 * tanpa ANALYZE, dan di keduanya ia COVERING (tidak ada lookup tabel sama
 * sekali). 610x lebih cepat di driver produksi adalah alasan yang cukup untuk
 * menyalin string 120 karakter itu sekali lagi. Angka-angka ini ada juga di
 * CONVENTIONS §37 dan LAPORAN-PAKET-HM-F-8.
 *
 * MAJU-SAJA: kolom baru, nullable, tanpa backfill dan tanpa nilai bawaan.
 * Tidak ada satu baris pun yang berubah arti karena migrasi ini dijalankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_attachments', function (Blueprint $table): void {
            // date, bukan dateTime: masa berlaku sebuah surat berbutir HARI —
            // dan aturan "berlaku s/d" di repo ini (prc_vendor_documents,
            // crm_guarantees) menghitung hari terakhir sebagai MASIH SAH.
            $table->date('valid_until')->nullable()->after('caption');

            $table->index(['attachable_type', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::table('core_attachments', function (Blueprint $table): void {
            $table->dropIndex(['attachable_type', 'valid_until']);
            $table->dropColumn('valid_until');
        });
    }
};
