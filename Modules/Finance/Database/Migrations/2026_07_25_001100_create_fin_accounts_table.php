<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts. Roots: 1 Aset, 2 Kewajiban, 3 Ekuitas, 4 Pendapatan,
        // 5 Beban Proyek (HPP), 6 Beban Operasional, 7 Pendapatan/Beban Lain.
        Schema::create('fin_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique(); // e.g. 1-1200
            $table->string('name', 150);
            $table->string('account_type', 20); // asset|liability|equity|revenue|cogs|expense|other
            $table->foreignId('parent_id')->nullable()->constrained('fin_accounts');
            $table->boolean('is_postable')->default(true); // groups (parents) are not postable
            $table->string('normal_balance', 10); // debit|credit
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_accounts');
    }
};
