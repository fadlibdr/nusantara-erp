<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan OVB (verifikasi F-2) — jalan keluar yang dijanjikan kalimatnya.
 *
 * OVB dikirim dengan aturan "satu anggaran disetujui per tahun buku" dan sebuah
 * kalimat penolakan yang berbunyi "batalkan %s lebih dulu bila anggaran ini yang
 * menggantikannya". Diukur pada verifikasi: OVB yang sudah disetujui TIDAK BISA
 * dibatalkan (rute tidak ada), dihapus (422, isEditable hanya draft/rejected),
 * ditolak (422, reject hanya menerima submitted), maupun diedit (422). Satu
 * tahun buku yang OVB-nya salah ketik terkunci selamanya, dan aplikasinya
 * menyuruh pemakainya melakukan tindakan yang tidak ia sediakan.
 *
 * Tiga kolom yang sama dengan pembatalan dokumen keuangan lain
 * (fin_ar_invoices, fin_ap_bills): siapa, kapan, dan KENAPA. Alasannya wajib —
 * sebuah anggaran tahunan yang hilang tanpa sebab tertulis adalah anggaran yang
 * tidak bisa dipertanggungjawabkan pada audit tahun itu.
 *
 * Slot kedua blok lanjutan Finance 001500– (CONVENTIONS §2, tabel blok adalah
 * sumber kebenarannya).
 *
 * INDEKS UNIK "satu approved per tahun" TIDAK PERLU DISENTUH: ia hanya
 * menghitung baris ber-status 'approved', jadi OVB yang dibatalkan melepaskan
 * slot tahunnya sendiri — di SQLite lewat klausa WHERE indeks parsialnya, di
 * MySQL lewat kolom generated yang menjadi NULL begitu statusnya berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_overhead_budgets', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->nullable()->after('notes');
            // User semantics (users.id) — app-owned, no DB constraint.
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('fin_overhead_budgets', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
