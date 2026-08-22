<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_taxes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PPN, PPH21, PPH23, PPH4A2-*
            $table->string('name', 150);
            $table->decimal('rate', 8, 4);
            $table->string('tax_type', 20); // ppn|pph_withholding
            // Liability/receivable COA account the tax posts against (intra-module FK).
            $table->foreignId('coa_account_id')->nullable()->constrained('fin_accounts');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_taxes');
    }
};
