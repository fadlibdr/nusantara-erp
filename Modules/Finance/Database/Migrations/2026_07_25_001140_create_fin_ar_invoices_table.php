<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AR termin invoices (penagihan termin kontrak).
        Schema::create('fin_ar_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // INV/{Y}/{RM}/{N4}
            // Cross-module references (Crm / Projects) — indexed, no DB constraint.
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('termin_id')->nullable(); // crm_contract_termins
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('description', 500);
            $table->decimal('dpp', 18, 2);
            $table->decimal('ppn_rate', 8, 4)->default(0);
            $table->decimal('ppn_amount', 18, 2)->default(0);
            // Retensi dipotong dari termin ini (skema retensi per termin).
            $table->decimal('retention_withheld', 18, 2)->default(0);
            $table->decimal('total', 18, 2); // dpp + ppn - retention_withheld
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->string('faktur_pajak_no', 40)->nullable();
            $table->text('terbilang'); // Terbilang::rupiah(total)
            $table->date('paid_at')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('contract_id');
            $table->index('termin_id');
            $table->index('project_id');
            $table->index('status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_ar_invoices');
    }
};
