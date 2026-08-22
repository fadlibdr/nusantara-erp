<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_company', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('legal_name')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('nib', 30)->nullable();
            $table->boolean('is_pkp')->default(true);
            $table->string('sppkp_number', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('website', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_company');
    }
};
