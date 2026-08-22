<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('customer_id')->constrained('crm_customers');
            $table->foreignId('quotation_id')->nullable()->constrained('crm_quotations');
            // The customer's own reference (their PO / SPK / PKS number).
            $table->string('contract_number_customer', 100)->nullable();
            $table->string('title');
            $table->string('scope_type', 30);
            $table->decimal('value', 18, 2)->default(0); // DPP, excludes PPN
            $table->decimal('ppn_rate', 8, 4)->default(config('erp.tax.ppn_rate', 11.0));
            $table->decimal('ppn_amount', 18, 2)->default(0);
            $table->decimal('total_with_ppn', 18, 2)->default(0);
            $table->date('sign_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('retention_pct', 8, 4)->default(5); // retensi, held until masa pemeliharaan ends
            $table->unsignedSmallInteger('warranty_months')->default(0);
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('scope_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contracts');
    }
};
