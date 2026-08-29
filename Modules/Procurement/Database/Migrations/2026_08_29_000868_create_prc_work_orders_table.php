<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 — PPK: perintah kerja alat sewa & jasa berbasis periode (menutup Laporan
 * Deviasi v2 §3.5 baris "PPK/SPK alat & jasa per periode").
 *
 * Baris PPK (prc_work_order_items) = alat/uraian x tarif x basis tarif x
 * qty_periods. qty_periods adalah PLAFON kuantitas dalam satuan basisnya
 * (jam untuk per_jam, hari untuk per_hari_8jam, bulan untuk per_bulan) —
 * nilai PPK = Σ amount baris (rate x qty_periods), dan tagihan per periode
 * tidak boleh melampaui plafon itu per baris.
 *
 * KUNCI ROLL-FORWARD PLAFON — pelajaran P3/P4 diterapkan lalu dijawab untuk
 * tabel INI, dengan argumen yang sama dengan scm_labor_contracts: baris PPK
 * tidak punya jalur regenerasi apa pun setelah approved. Ia dimiliki PPK-nya
 * sendiri, tanpa copyVersion, tanpa impor-ulang; satu-satunya yang menulis
 * ulang barisnya adalah WorkOrderService::syncItems, yang hanya berjalan
 * selagi PPK masih draft/rejected (assertEditable) — sedangkan tagihan
 * periode hanya boleh lahir atas PPK APPROVED, sehingga tidak pernah ada
 * baris tagihan yang menunjuk baris PPK yang masih bisa diregenerasi.
 * Karena itu roll-forward di WorkOrderBillingService AMAN dikunci pada
 * prc_work_order_billing_lines.work_order_item_id (id baris). Siapa pun yang
 * kelak menambahkan addendum yang meregenerasi baris PPK wajib membaca
 * paragraf ini dulu dan pindah kunci seperti P3.
 *
 * Baris per_jam WAJIB menunjuk asset_id: jamnya diturunkan dari register
 * hour-meter alat itu (ast_equipment_logs), bukan diketik — baris tanpa alat
 * tidak punya meter untuk dibaca, jadi ditolak saat menyusun PPK, bukan
 * diam-diam ditagih nol saat penagihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PPK/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('vendor_id'); // prc_vendors (vendor_type rental atau supplier jasa)
            $table->unsignedBigInteger('project_id'); // prj_projects, lintas-modul tanpa FK
            $table->string('title', 200);
            $table->decimal('value', 18, 2)->default(0); // = Σ amount baris (DPP)
            $table->decimal('ppn_rate', 8, 4)->default(0); // snapshot saat dibuat; 0 kecuali vendor PKP
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('qualification_override_reason', 500)->nullable(); // jejak override gate dokumen wajib vendor
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('vendor_id');
            $table->index('project_id');
            $table->index('status');
        });

        Schema::create('prc_work_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('prc_work_orders');
            $table->unsignedInteger('line_no');
            $table->unsignedBigInteger('asset_id')->nullable(); // ast_assets, lintas-modul tanpa FK; WAJIB untuk per_jam
            $table->string('description', 500);
            $table->string('rate_basis', 20); // per_bulan | per_hari_8jam | per_jam
            $table->decimal('rate', 18, 2); // tarif sewa/jasa per satuan basis
            $table->decimal('qty_periods', 15, 3); // plafon kuantitas dalam satuan basis
            $table->decimal('amount', 18, 2); // rate x qty_periods
            $table->timestamps();

            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_work_order_items');
        Schema::dropIfExists('prc_work_orders');
    }
};
