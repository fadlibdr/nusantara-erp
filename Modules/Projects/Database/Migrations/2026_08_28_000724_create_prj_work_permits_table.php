<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-C: Izin Kerja Lapangan (IKL, Form F/IK) menjadi transaksi.
 *
 * Sampai paket ini lembar F/IK dicetak KOSONG dan diisi tangan; mulai sekarang
 * satu baris di sini adalah satu izin satu shift, dan lembarnya dicetak DARI
 * baris ini. FORWARD-ONLY: tidak ada backfill — proyek lama sah punya nol izin,
 * lembar kertas yang sudah diarsip tetap satu-satunya catatan masa itu.
 *
 * Rujukan silang mengikuti kontrak shared-ID CONVENTIONS §3 (indeks tanpa FK):
 *  - requested_by / safety_officer_id → hr_employees (pemohon adalah
 *    pelaksana/mandor; petugas K3 nullable karena tidak semua shift punya).
 *  - wbs_task_id tanpa FK meski satu modul — generate-WBS menghapus dan
 *    membangun ulang pohon tugas (alasan yang sama dengan 000723).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_work_permits', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // IKL/{Y}/{RM}/{N4}
            $table->foreignId('project_id')->constrained('prj_projects');
            $table->unsignedBigInteger('wbs_task_id')->nullable(); // prj_wbs_tasks — indexed, no FK (regenerate)
            $table->date('permit_date');
            $table->string('shift', 10); // WorkShift: pagi/siang/malam
            $table->text('work_description');
            $table->text('hazard_notes')->nullable();
            $table->json('ppe_required')->nullable(); // daftar APD wajib, list of strings
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->unsignedBigInteger('requested_by'); // hr_employees — indexed, no FK
            $table->unsignedBigInteger('safety_officer_id')->nullable(); // hr_employees — indexed, no FK
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('wbs_task_id');
            $table->index('requested_by');
            $table->index('safety_officer_id');
            $table->index(['project_id', 'permit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_work_permits');
    }
};
