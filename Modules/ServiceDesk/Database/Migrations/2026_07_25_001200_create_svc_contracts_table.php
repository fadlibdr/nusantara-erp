<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svc_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // SVC/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('customer_id'); // crm_customers.id (cross-module)
            // Commercial (CRM) contract this maintenance agreement was signed under.
            $table->unsignedBigInteger('contract_id')->nullable(); // crm_contracts.id (cross-module)
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('contract_value', 18, 2)->default(0); // annual value (DPP, excl. PPN)
            $table->string('billing_cycle', 20)->default('quarterly'); // monthly | quarterly | yearly
            $table->unsignedSmallInteger('sla_response_hours')->default(4);
            $table->unsignedSmallInteger('sla_resolution_hours')->default(24);
            $table->text('coverage')->nullable(); // scope of covered systems / exclusions
            $table->string('status', 30)->default('active'); // active | expired | terminated
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('contract_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_contracts');
    }
};
