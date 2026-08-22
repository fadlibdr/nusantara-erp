<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svc_field_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PM/{Y}/{RM}/{N4} (preventive maintenance visit / field service report)
            $table->foreignId('ticket_id')->constrained('svc_tickets');
            $table->date('report_date');
            $table->unsignedBigInteger('technician_employee_id'); // hr_employees.id (cross-module)
            $table->text('findings');
            $table->text('actions_taken');
            $table->text('recommendations')->nullable();
            $table->string('customer_sign_name', 100)->nullable();
            $table->dateTime('customer_signed_at')->nullable();
            $table->string('status', 30)->default('draft'); // draft | submitted | acknowledged
            $table->timestamps();
            $table->softDeletes();

            $table->index('technician_employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_field_reports');
    }
};
