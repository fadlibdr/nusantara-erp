<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2 / kriteria #4 — jejak RFQ pada SPK subkon.
     *
     * Kriteria #4 menuntut "PO/SPK dari RFQ tidak dapat disetujui tanpa award
     * decision disetujui". PO sudah membawa rfq_id (blok 000860); SPK belum, jadi
     * separuh SPK dari aturan itu tidak bisa ditegakkan. Kolom ini menutupnya:
     * SubcontractService::approve memeriksa award ketika rfq_id terisi.
     *
     * Referensi lintas modul (prc_rfqs) — unsignedBigInteger + index, TANPA
     * constraint (CONVENTIONS §3). Nullable: SPK lama dan SPK yang bukan dari
     * banding subkon tetap sah, aman di MySQL dengan data lama.
     */
    public function up(): void
    {
        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            $table->unsignedBigInteger('rfq_id')->nullable()->after('project_id');
            $table->index('rfq_id');
        });
    }

    public function down(): void
    {
        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            $table->dropIndex(['rfq_id']);
            $table->dropColumn('rfq_id');
        });
    }
};
