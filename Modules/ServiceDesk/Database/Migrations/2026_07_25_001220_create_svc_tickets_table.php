<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svc_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // TKT-{Y}{M2}-{N4}
            $table->foreignId('service_contract_id')->nullable()->constrained('svc_contracts');
            $table->unsignedBigInteger('customer_id'); // crm_customers.id (cross-module)
            $table->foreignId('site_id')->nullable()->constrained('svc_contract_sites');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 20)->default('incident'); // incident | request | preventive
            $table->string('priority', 20)->default('medium'); // low | medium | high | critical
            $table->string('status', 30)->default('open');
            $table->string('channel', 20)->default('phone'); // phone | email | wa | portal | system
            $table->string('reported_by_name', 100)->nullable();
            $table->dateTime('reported_at');
            $table->unsignedBigInteger('assigned_to')->nullable(); // hr_employees.id (cross-module)
            $table->dateTime('response_due_at')->nullable();
            $table->dateTime('resolution_due_at')->nullable();
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('priority');
            $table->index('category');
            $table->index('response_due_at');
            $table->index('resolution_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_tickets');
    }
};
