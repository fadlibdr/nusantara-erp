<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak override prakualifikasi vendor di SPK (temuan #35) — cermin kolom
 * yang sama di prc_purchase_orders.
 *
 * Tanpa kolom ini sisi SPK tidak bisa memikul kontrak override-beralasan:
 * SubcontractService::create memanggil gate-nya tapi alasannya di-pull lalu
 * DIBUANG, dan submit tidak memeriksa apa pun — SPK bernilai miliaran
 * (komitmen jauh lebih besar dari PO rata-rata) justru satu-satunya dokumen
 * pengadaan yang override-nya tak meninggalkan jejak untuk auditor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            if (! Schema::hasColumn('scm_subcontracts', 'qualification_override_reason')) {
                $table->string('qualification_override_reason', 500)->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            if (Schema::hasColumn('scm_subcontracts', 'qualification_override_reason')) {
                $table->dropColumn('qualification_override_reason');
            }
        });
    }
};
