<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_progress_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // CLM/{Y}/{RM}/{N4}
            $table->foreignId('subcontract_id')->constrained('scm_subcontracts');
            $table->unsignedInteger('claim_no'); // opname sequence per SPK (1, 2, ...)
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_amount', 18, 2)->default(0); // this-period work (DPP of the opname)
            $table->decimal('retention_amount', 18, 2)->default(0); // withheld, released after masa pemeliharaan
            $table->decimal('net_before_tax', 18, 2)->default(0); // gross - retention
            $table->decimal('ppn_amount', 18, 2)->default(0); // on full gross (PKP vendors only)
            $table->decimal('pph_amount', 18, 2)->default(0); // PPh final konstruksi on full gross
            $table->decimal('net_payable', 18, 2)->default(0); // net_before_tax + ppn - pph
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['subcontract_id', 'claim_no']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_progress_claims');
    }
};
