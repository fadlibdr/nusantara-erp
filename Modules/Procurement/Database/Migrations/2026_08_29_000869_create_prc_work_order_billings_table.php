<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 — tagihan per periode atas PPK (menutup Laporan Deviasi v2 §3.10 baris
 * "Rekap tagihan alat: tak terikat periode sewa").
 *
 * Satu billing = satu PPK x satu rentang tanggal [period_start, period_end].
 * Kuantitasnya DITURUNKAN, bukan diketik: per_jam dari delta pembacaan
 * hour-meter DI DALAM periode (ast_equipment_logs), per_bulan/per_hari_8jam
 * dari kalender. Uangnya baru keluar lewat tagihan AP yang dibuat dari
 * billing ini (fin_ap_bills.work_order_billing_id, migrasi Finance 001127).
 *
 * MENGAPA PERIODE YANG SAMA TIDAK BISA TERTAGIH DUA KALI — argumen keamanan
 * yang diminta pelajaran P3/P4, ditulis di sini karena tabel inilah kuncinya:
 *
 *   1. WorkOrderBillingService menolak billing baru yang periodenya
 *      TUMPANG-TINDIH dengan billing HIDUP mana pun milik PPK yang sama
 *      (existing.start <= new.end AND existing.end >= new.start), diputuskan
 *      di dalam transaksi ber-lockForUpdate pada baris PPK — dua penyusun
 *      yang balapan diserialisasi di titik yang sama. Maka periode-periode
 *      billing satu PPK saling lepas (disjoint).
 *   2. Jam per_jam dihitung HANYA dari pembacaan di dalam periode billing
 *      (aturan batas dalam-periode — lihat WorkOrderBillingService). Karena
 *      periodenya saling lepas, setiap pasangan pembacaan berurutan jatuh di
 *      dalam paling banyak SATU periode, sehingga satu jam meter tidak
 *      mungkin tertagih pada dua billing.
 *   3. Plafon qty_periods di-roll-forward per baris PPK, dikunci pada
 *      work_order_item_id — id baris, yang stabil dengan argumen migrasi
 *      000868 (baris PPK tidak pernah diregenerasi setelah approved).
 *   4. Satu billing → paling banyak SATU tagihan AP hidup:
 *      ApBillService::createFromWorkOrderBilling menolak bila tagihan
 *      non-cancelled atas billing yang sama sudah ada (cermin persis guard
 *      opname mandor P4). Billing yang tagihannya DIBATALKAN bisa ditagih
 *      ulang — pembatalan membalik jurnalnya, jadi rupiahnya tetap satu kali.
 *
 * Billing SENGAJA bukan Approvable: angkanya turunan register + kalender
 * (tidak ada yang diketik untuk disetujui), PPK di atasnya sudah
 * ber-maker-checker, dan tagihan AP di bawahnya ber-maker-checker penuh di
 * Finance — lapisan persetujuan ketiga hanya menyetujui rupiah yang sama
 * tiga kali. Hapus billing hanya boleh selama belum ada tagihan AP hidup
 * yang menunjuknya (WorkOrderBillingService::delete).
 *
 * meter_start/meter_end pada baris per_jam adalah SNAPSHOT pembacaan yang
 * dipakai — supaya rekap dan tagihan bisa menyebut "1.200,0 → 1.213,0 =
 * 13 jam" tanpa membaca ulang register yang mungkin sudah bertambah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_work_order_billings', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PPKB/{Y}/{RM}/{N4}
            $table->foreignId('work_order_id')->constrained('prc_work_orders');
            $table->unsignedInteger('billing_no'); // urutan per PPK, gaya claim_no opname
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_amount', 18, 2)->default(0); // = Σ amount baris (DPP)
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_order_id', 'period_start']);
        });

        Schema::create('prc_work_order_billing_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_billing_id')->constrained('prc_work_order_billings');
            $table->foreignId('work_order_item_id')->constrained('prc_work_order_items'); // kunci roll-forward (lihat docblock)
            $table->decimal('qty', 15, 3); // jam / hari / bulan pada periode ini
            $table->decimal('amount', 18, 2); // qty x rate baris PPK
            $table->decimal('meter_start', 12, 3)->nullable(); // snapshot pembacaan pertama dalam periode (per_jam)
            $table->decimal('meter_end', 12, 3)->nullable(); // snapshot pembacaan terakhir dalam periode (per_jam)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_work_order_billing_lines');
        Schema::dropIfExists('prc_work_order_billings');
    }
};
