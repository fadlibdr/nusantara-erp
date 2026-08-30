<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6: kolom `jenis` pada pustaka template — 'quality' (checklist mutu, seluruh
 * pustaka Q1..Q31 yang sudah ada) atau '5r' (patroli housekeeping 5R).
 *
 * FORWARD-ONLY: bawaan kolom 'quality' plus backfill eksplisit, sehingga tidak
 * satu pun baris lama berubah perilaku — template tanpa jenis ADALAH template
 * mutu, persis seperti sebelum kolom ini lahir. Checklist 5R kemudian adalah
 * INSPEKSI BIASA atas template ber-jenis '5r': satu kolom ini adalah seluruh
 * mesin 5R; butir, hasil, verdict, lampiran foto, dan guard template-terpakai
 * (InspectionTemplateService) dipakai apa adanya, tanpa mesin paralel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qc_inspection_templates', function (Blueprint $table): void {
            $table->string('jenis', 20)->default('quality'); // TemplateKind: quality/5r
        });

        // Bawaan kolom sudah mengisi baris lama; backfill eksplisit agar
        // maksudnya terbaca dan tidak bergantung pada perilaku ALTER driver.
        DB::table('qc_inspection_templates')->whereNull('jenis')->update(['jenis' => 'quality']);
    }

    public function down(): void
    {
        Schema::table('qc_inspection_templates', function (Blueprint $table): void {
            $table->dropColumn('jenis');
        });
    }
};
