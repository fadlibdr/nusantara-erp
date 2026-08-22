<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retensi receivable dari customer: recorded when an AR invoice withholds
        // retention, released after masa pemeliharaan (BAST II).
        Schema::create('fin_ar_retentions', function (Blueprint $table): void {
            $table->id();
            // Cross-module references (Crm / Projects) — indexed, no DB constraint.
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('source_invoice_id')->constrained('fin_ar_invoices');
            $table->decimal('amount', 18, 2);
            $table->boolean('released')->default(false);
            $table->date('released_at')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_ar_retentions');
    }
};
