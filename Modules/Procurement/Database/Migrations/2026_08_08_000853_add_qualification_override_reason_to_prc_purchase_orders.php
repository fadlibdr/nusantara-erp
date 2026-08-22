<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak override prakualifikasi vendor (temuan #35).
 *
 * Gate prakualifikasi menolak pengajuan PO untuk vendor nonaktif atau vendor
 * yang dokumen wajibnya kedaluwarsa, dengan satu jalan darurat: override
 * BERALASAN. Alasan itu harus tinggal di dokumennya — bukan di log yang tidak
 * pernah dibuka — supaya "PO ini terbit ke vendor bermasalah karena X" terbaca
 * di layar detail dan bisa ditanyakan auditor dengan satu query, bukan
 * direka-reka dari ingatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prc_purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('prc_purchase_orders', 'qualification_override_reason')) {
                $table->string('qualification_override_reason', 500)->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prc_purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('prc_purchase_orders', 'qualification_override_reason')) {
                $table->dropColumn('qualification_override_reason');
            }
        });
    }
};
