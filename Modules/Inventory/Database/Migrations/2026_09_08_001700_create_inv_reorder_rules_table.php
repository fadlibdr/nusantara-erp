<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titik pesan ulang per ITEM × GUDANG (F-6).
 *
 * BLOK MIGRASI LANJUTAN INVENTORY. Blok pertama Inventory 000400–000499 sudah
 * habis pada granularitas yang dipakai §2 ("increment by 10"): kesepuluh slot
 * puluhan 000400…000490 terpakai, dan luapannya sudah mengambil 000491 serta
 * 000495–000499. Berkas ini adalah slot PERTAMA blok lanjutan Inventory
 * 001700–001799, yang DIDAFTARKAN di tabel "Blok lanjutan" CONVENTIONS §2 pada
 * commit yang sama dengan berkas ini — aturannya sendiri: didaftarkan di tabel
 * itu pada commit yang pertama kali memakainya, tidak lebih dulu dan tidak
 * belakangan. Tabel §2 itulah sumber kebenaran rentang blok, bukan prosa mana
 * pun, termasuk prosa di kepala berkas ini.
 *
 * KENAPA ADA TABEL BARU DAN BUKAN KOLOM KEDUA DI inv_items.
 *
 * `inv_items.min_stock` adalah satu angka untuk SELURUH perusahaan. Gudang
 * pusat yang memasok delapan proyek dan gudang site yang hanya memasang CCTV
 * di satu gedung tidak punya titik pesan ulang yang sama, dan hari ini
 * keduanya memakai angka yang sama — sehingga satu-satunya cara membuat gudang
 * site berhenti berteriak adalah menurunkan ambang gudang pusat juga. Ambang
 * per pasangan tidak bisa hidup di baris item.
 *
 * PRIORITAS, DAN IA DITULIS DI LAYAR.
 *
 * Aturan aktif untuk sepasang (gudang, item) MENGGANTIKAN `min_stock` untuk
 * pasangan itu — bukan menambahnya, bukan mengambil yang lebih besar. Termasuk
 * bila titiknya LEBIH RENDAH: itulah gunanya, dan "yang paling ketat menang"
 * akan membuat aturan gudang site tidak pernah bisa lebih longgar daripada
 * angka perusahaan. `reorder_point` 0 pada aturan aktif berarti "pasangan ini
 * tidak pernah dipesan ulang", persis seperti `min_stock` 0 berarti itu untuk
 * item tanpa aturan. Definisi tunggalnya hidup di
 * StockService::lowStockAlerts() dan disalin — dengan sengaja, dijaga uji —
 * ke registri Core ModuleCounts.
 *
 * TANPA softDeletes, SEPERTI ast_depreciation_runs DAN core_saved_reports.
 *
 * Kueri "di bawah minimum" menggabungkan tabel ini dengan LEFT JOIN atas
 * (warehouse_id, item_id). Dua baris hidup untuk satu pasangan akan
 * MENGGANDAKAN baris kekurangan — dan karena registri ModuleCounts menghitung
 * baris yang sama, angka di ubin launcher ikut menggandakan diri tanpa satu
 * pun galat. UNIQUE di bawah ini adalah yang menahannya, dan UNIQUE tidak bisa
 * hidup berdampingan dengan softDeletes: satu baris yang dibuang lembut
 * menempati pasangannya selamanya, sehingga aturan yang dihapus tidak pernah
 * bisa dibuat ulang. Yang dibutuhkan pemakai bukan tong sampah melainkan
 * saklar, dan saklarnya adalah `is_active` — aturan nonaktif TETAP ada, tetap
 * terbaca angkanya, dan tidak ikut menentukan ambang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_reorder_rules', function (Blueprint $table): void {
            $table->id();
            // DI DALAM satu modul, jadi constrained() biasa (CONVENTIONS §3):
            // inv_warehouses dan inv_items dimiliki Inventory sendiri.
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            $table->foreignId('item_id')->constrained('inv_items');
            // Kuantitas decimal(15,3), sama dengan inv_items.min_stock dan
            // inv_stock_balances.qty — ambang yang dibandingkan dengan saldo
            // harus punya skala yang sama dengan saldonya, atau perbandingan
            // 12,5 < 12,500 mulai bergantung pada dialek basis data.
            $table->decimal('reorder_point', 15, 3)->default(0);
            // Jumlah yang diusulkan sekali pesan. 0 = "tidak dinyatakan", dan
            // usulan PR memakai kekurangannya sendiri. Bukan default yang
            // masuk akal, melainkan ketiadaan yang dinyatakan.
            $table->decimal('reorder_qty', 15, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id']);
            // Kueri kekurangan menjoin dari saldo ke aturan; item_id sendiri
            // melayani layar "aturan untuk item ini" pada detail item.
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_reorder_rules');
    }
};
