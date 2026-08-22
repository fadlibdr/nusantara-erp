<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak "PO ini lahir dari lembar banding yang mana" (temuan #34 tahap 3).
     * Tanpa kolom ini klaim "harga PO adalah harga pemenang RFQ" tidak bisa
     * dibuktikan dari dokumennya sendiri — dan RFQ yang sudah melahirkan PO
     * tidak bisa dilindungi dari penghapusan.
     */
    public function up(): void
    {
        Schema::table('prc_purchase_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('rfq_id')->nullable()->after('purchase_requisition_id'); // prc_rfqs.id
            $table->index('rfq_id');
        });
    }

    public function down(): void
    {
        Schema::table('prc_purchase_orders', function (Blueprint $table): void {
            $table->dropIndex(['rfq_id']);
            $table->dropColumn('rfq_id');
        });
    }
};
