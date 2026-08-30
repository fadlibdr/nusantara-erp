<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: penawaran menunjuk metode pelaksanaannya.
 *
 * SATU KOLOM, bukan restrukturisasi penawaran. Yang diminta paket ini adalah
 * rujukan — "Metode Pelaksanaan" pada dokumen penawaran — dan sebuah tabel
 * pivot penawaran×metode akan menjawab pertanyaan yang belum ada yang
 * menanyakannya sambil menyentuh setiap layar penawaran.
 *
 * Lintas modul (Crm → core_method_library): unsignedBigInteger + index, TANPA
 * constraint, sesuai CONVENTIONS §3. Aturannya — rujukan harus ke versi yang
 * BERLAKU, bukan yang sudah digantikan — hidup di QuotationService, karena
 * sebuah FK tidak bisa menyatakannya.
 *
 * Nullable dan aman di MySQL berisi data: penawaran lama tetap tanpa rujukan,
 * dan tidak ada yang mengarangkan satu untuk mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotations', function (Blueprint $table): void {
            $table->unsignedBigInteger('method_library_id')->nullable()->after('scope_type');
            $table->index('method_library_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_quotations', function (Blueprint $table): void {
            $table->dropIndex(['method_library_id']);
            $table->dropColumn('method_library_id');
        });
    }
};
