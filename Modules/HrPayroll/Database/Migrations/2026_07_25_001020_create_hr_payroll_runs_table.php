<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PYR/{Y}/{M2}/{N3}
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1..12
            $table->string('run_type', 20)->default('regular'); // regular | thr
            $table->date('payment_date')->nullable();
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('total_net', 18, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['period_year', 'period_month', 'run_type'],
                'hr_payroll_runs_period_unique'
            );
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_runs');
    }
};
