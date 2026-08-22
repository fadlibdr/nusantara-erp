<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained('crm_quotations')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('description', 500);
            $table->decimal('qty', 15, 3)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['quotation_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quotation_items');
    }
};
