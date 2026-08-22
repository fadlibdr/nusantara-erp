<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan cuti/izin (finding #22, half 1).
 *
 * Until now leave lived nowhere: HR typed leave_days straight into the monthly
 * attendance recap from memory and paper forms, so "how much cuti tahunan does
 * Joko have left" had no answer the system could defend. This register gives
 * every absence a document — draft → submitted → approved/rejected with the
 * module's maker-checker rule — and the approved ones feed the recap that the
 * payroll run reads (LeaveService::syncRecaps).
 *
 * The saldo itself is NOT a column. 12 hari cuti tahunan per entitlement year
 * (UU 13/2003 Pasal 79, after 12 months masa kerja) is arithmetic over
 * join_date and the approved rows, computed by LeaveService::balance — a stored
 * balance would be one more number that can silently disagree with the rows
 * that justify it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            // restrict, not cascade: deleting an employee must not shred the
            // approved absences a payroll recap was built from.
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('leave_type', 30); // tahunan | sakit | izin | khusus
            $table->date('start_date');
            $table->date('end_date');
            // Working days inside the range, computed server-side — the number
            // the saldo is debited by and the recap is fed with. Never typed by
            // the requester: a typed 1 over a two-week range would hand back
            // nine days of saldo nobody took.
            $table->unsignedTinyInteger('day_count');
            $table->text('reason');
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            // The balance query: approved rows of one employee and one type,
            // windowed by start_date.
            $table->index(['employee_id', 'leave_type', 'start_date'], 'hr_leave_requests_balance_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_requests');
    }
};
