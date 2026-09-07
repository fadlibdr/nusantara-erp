<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua fakta yang selama ini hilang dari jejak persetujuan (F-1).
 *
 * `policy` — ATURAN YANG BERLAKU SAAT DOKUMEN DIAJUKAN, dicap pada baris
 * `submitted`. Sampai paket ini, jenjang persetujuan dibaca LANGSUNG dari
 * config setiap kali seseorang menekan Setujui: mengubah ambang siang hari
 * mengubah tuntutan setiap dokumen yang sedang menunggu, ke atas maupun ke
 * bawah, tanpa satu baris pun yang mencatat bahwa itu terjadi. Sebuah award
 * Rp 1,5 miliar yang diajukan ketika ≥ Rp 1 miliar menuntut tiga penyetuju
 * bisa selesai dengan dua, karena ambang tingkat ketiga dinaikkan sesudahnya.
 * Sesudah kolom ini, yang dibaca saat menyetujui adalah stempelnya.
 *
 * Isinya bukan hanya kebijakan melainkan HASILNYA (levels, director) beserta
 * nilai dokumennya, jadi tidak ada yang perlu dihitung ulang — dan tidak ada
 * jalan bagi hitungan ulang itu untuk memakai angka yang berbeda.
 *
 * `on_behalf_of_user_id` — SIAPA YANG SEBENARNYA DIWAKILI. Delegasi "a.n."
 * (core_approval_delegations) membuat Budi menyetujui memakai hak Sari. Baris
 * persetujuan yang hanya menyebut Budi menghilangkan separuh fakta itu; jejak
 * dan setiap tampilan membacanya sebagai "Budi a.n. Sari".
 *
 * FORWARD-ONLY. Kedua kolom nullable dan TIDAK diisi surut: dokumen yang sudah
 * diajukan sebelum migrasi ini tidak punya stempel dan tetap memakai resolusi
 * langsung (perilaku hari ini, tidak lebih buruk), dan persetujuan yang sudah
 * terjadi tidak pernah "ternyata a.n." seseorang. Menebak keduanya berarti
 * mengarang riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_approvals', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_approvals', 'policy')) {
                // json, bukan kolom per field: bentuknya berversi
                // (ApprovalPolicy::STAMP_VERSION) dan yang dibaca adalah
                // stempel apa adanya — memecahnya menjadi kolom berarti
                // migrasi baru setiap kali kebijakan tumbuh satu dimensi.
                $table->json('policy')->nullable()->after('note');
            }

            if (! Schema::hasColumn('core_approvals', 'on_behalf_of_user_id')) {
                // Tanpa FK, seperti user_id di tabel yang sama: baris
                // persetujuan adalah catatan sejarah dan harus selamat dari
                // penghapusan akun yang dicatatnya.
                $table->unsignedBigInteger('on_behalf_of_user_id')->nullable()->after('user_id');
                $table->index('on_behalf_of_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('core_approvals', function (Blueprint $table): void {
            if (Schema::hasColumn('core_approvals', 'on_behalf_of_user_id')) {
                $table->dropIndex(['on_behalf_of_user_id']);
                $table->dropColumn('on_behalf_of_user_id');
            }

            if (Schema::hasColumn('core_approvals', 'policy')) {
                $table->dropColumn('policy');
            }
        });
    }
};
