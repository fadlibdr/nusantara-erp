<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak alasan PO tanpa PR (T3.8, ANALISIS-PROSES E3).
 *
 * PO langsung — tanpa permintaan pembelian — memang diizinkan (kebutuhan
 * darurat di lapangan), tetapi sampai 4 Sep 2026 tidak meninggalkan alasan
 * apa pun: PO/2026/III/0002 Rp 128 jt di produksi ber-purchase_requisition_id
 * NULL, dan satu-satunya "mengapa" hanyalah komentar di seeder ("direct PO
 * (PR ICT masih submitted)") — tidak terbaca auditor, tidak terbaca layar.
 * Polanya sama dengan override prakualifikasi (kolom di sebelahnya): alasan
 * tinggal di dokumennya, tampil di detail dan di formulir cetak, dan hanya
 * terisi bila PO memang lahir tanpa PR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prc_purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('prc_purchase_orders', 'pr_bypass_reason')) {
                $table->string('pr_bypass_reason', 500)->nullable()->after('qualification_override_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prc_purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('prc_purchase_orders', 'pr_bypass_reason')) {
                $table->dropColumn('pr_bypass_reason');
            }
        });
    }
};
