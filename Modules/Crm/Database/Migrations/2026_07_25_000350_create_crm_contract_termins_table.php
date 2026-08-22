<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contract_termins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('crm_contracts')->cascadeOnDelete();
            $table->unsignedInteger('termin_no');
            $table->string('name', 100); // e.g. "DP 20%", "Progress 50%", "BAST", "Retensi 5%"
            $table->decimal('percent', 8, 4);
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('billing_condition')->nullable();
            $table->date('billed_at')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'termin_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contract_termins');
    }
};
