<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svc_preventive_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_contract_id')->constrained('svc_contracts');
            $table->foreignId('site_id')->nullable()->constrained('svc_contract_sites');
            $table->string('name'); // e.g. "PM CCTV Bulanan"
            $table->string('frequency', 20)->default('monthly'); // monthly | quarterly | semiannual
            $table->date('next_due_date');
            $table->unsignedBigInteger('assigned_to')->nullable(); // hr_employees.id (cross-module)
            $table->json('checklist')->nullable(); // list of inspection steps
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('assigned_to');
            $table->index(['is_active', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_preventive_schedules');
    }
};
