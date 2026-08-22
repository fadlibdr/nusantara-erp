<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ast_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100); // Alat Berat, Kendaraan, Alat Ukur & Uji, ...
            $table->unsignedInteger('useful_life_months_default')->default(48);
            $table->string('depreciation_account_hint', 20)->nullable(); // e.g. 6-3100 Beban Penyusutan
            $table->string('accum_account_hint', 20)->nullable(); // e.g. 1-2910 Akumulasi Penyusutan
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ast_categories');
    }
};
