<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svc_contract_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_contract_id')->constrained('svc_contracts');
            $table->string('site_name');
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('pic_name', 100)->nullable();
            $table->string('pic_phone', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_contract_sites');
    }
};
