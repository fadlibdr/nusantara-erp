<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-C: Izin Kerja Lembur (ILB, Form F/IL) menjadi transaksi — header + baris
 * pekerja, karena lembar lembur ditandatangani PER ORANG dan jam per orang
 * itulah yang kelak diumpankan ke rekap absensi (hr_attendance_recaps).
 *
 * Baris pekerja: employee_id XOR worker_name. Kru mandor non-karyawan itu
 * nyata — namanya tercetak di lembar dan ikut ditandatangani — tetapi tidak
 * punya baris rekap payroll, jadi worker_name berdiri sendiri tanpa
 * employee_id. Umpan rekap (OvertimeRecapService) hanya membaca baris
 * ber-employee_id.
 *
 * start_time/end_time, bukan start/end: mengikuti preseden
 * prj_daily_reports.work_start/work_end, dan END adalah kata kunci tercadang di
 * MySQL maupun SQLite — nama polos berhenti aman begitu ada query mentah.
 * end_time < start_time berarti lembur melewati tengah malam (lihat
 * OvertimePermitService untuk keputusan lengkapnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_overtime_permits', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // ILB/{Y}/{RM}/{N4}
            $table->foreignId('project_id')->constrained('prj_projects');
            $table->date('overtime_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('reason');
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'overtime_date']);
        });

        Schema::create('prj_overtime_permit_workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('overtime_permit_id')->constrained('prj_overtime_permits')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable(); // hr_employees — indexed, no FK
            $table->string('worker_name', 150)->nullable(); // non-karyawan (kru mandor)
            $table->decimal('hours', 5, 2);
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_overtime_permit_workers');
        Schema::dropIfExists('prj_overtime_permits');
    }
};
