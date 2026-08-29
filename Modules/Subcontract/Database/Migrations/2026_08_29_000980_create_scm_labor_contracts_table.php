<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 — SP3 Induk: SPK mandor upah borongan (menutup Laporan Deviasi v2 §3.5
 * baris "SPK Mandor / SP3 Induk / opname mandor").
 *
 * Baris kontrak (scm_labor_contract_items) adalah boq_item x tarif upah x
 * qty — nilai SP3 = Σ amount barisnya, dan plafon klaim per baris adalah qty
 * barisnya (bukan persen seperti opname subkon: mandor dibayar per volume).
 *
 * KUNCI ROLL-FORWARD SISA QTY — pelajaran P3 diterapkan lalu dijawab untuk
 * tabel INI: MeasurementService tidak boleh mengunci riwayat pada id baris
 * BOQ karena BoqService::copyVersion meregenerasi baris-baris itu (id baru,
 * riwayat hilang, volume yang sama tertagih dua kali). Baris SP3 TIDAK punya
 * jalur regenerasi seperti itu: ia dimiliki kontraknya sendiri, tanpa
 * copyVersion, tanpa impor-ulang; satu-satunya yang menulis ulang barisnya
 * adalah LaborContractService::syncItems, yang hanya berjalan selagi SP3
 * masih draft/rejected (assertEditable) — sedangkan klaim hanya boleh lahir
 * atas SP3 APPROVED, sehingga tidak pernah ada klaim yang menunjuk baris
 * yang masih bisa diregenerasi. Karena itu roll-forward di
 * LaborClaimService AMAN dikunci pada scm_labor_claim_items
 * .labor_contract_item_id (id baris) — di sini, dan tidak aman di P3.
 * Siapa pun yang kelak menambahkan revisi/addendum yang meregenerasi baris
 * SP3 wajib membaca paragraf ini dulu dan pindah kunci seperti P3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_labor_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // SP3/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('vendor_id'); // prc_vendors (harus vendor_type = mandor)
            $table->unsignedBigInteger('project_id'); // prj_projects, lintas-modul tanpa FK
            $table->string('title', 200);
            $table->decimal('value', 18, 2)->default(0); // = Σ amount baris (DPP upah)
            $table->decimal('ppn_rate', 8, 4)->default(0); // 0 kecuali mandor PKP (praktisnya tak pernah)
            $table->string('pph_scheme', 30); // LaborPphScheme: final_umkm | pph21_ter
            $table->decimal('pph_rate', 8, 4)->default(0); // snapshot tarif saat dibuat (PP 55/2022 0,5%)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('qualification_override_reason', 500)->nullable(); // jejak override gate K3L/pakta
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('vendor_id');
            $table->index('project_id');
            $table->index('status');
        });

        Schema::create('scm_labor_contract_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('labor_contract_id')->constrained('scm_labor_contracts');
            $table->unsignedInteger('line_no');
            $table->unsignedBigInteger('boq_item_id')->nullable(); // est_boq_items, lintas-modul tanpa FK
            $table->string('wbs_code', 20)->nullable();
            $table->string('description', 500);
            $table->decimal('qty', 15, 3); // plafon volume klaim baris ini
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_rate', 18, 2); // tarif upah per satuan
            $table->decimal('amount', 18, 2); // qty x unit_rate
            $table->timestamps();

            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_labor_contract_items');
        Schema::dropIfExists('scm_labor_contracts');
    }
};
