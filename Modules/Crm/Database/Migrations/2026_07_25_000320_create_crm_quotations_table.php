<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('customer_id')->constrained('crm_customers');
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads');
            $table->string('title');
            $table->string('scope_type', 30); // construction | system_integration | maintenance
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('dpp', 18, 2)->default(0);
            $table->decimal('ppn_rate', 8, 4)->default(config('erp.tax.ppn_rate', 11.0));
            $table->decimal('ppn_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(0);
            $table->dateTime('won_at')->nullable();
            $table->dateTime('lost_at')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('scope_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quotations');
    }
};
