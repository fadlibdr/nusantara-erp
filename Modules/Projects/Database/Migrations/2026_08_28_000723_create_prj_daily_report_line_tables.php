<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-A: empat tabel baris FM-10-12 — sel yang selama ini garis kosong kini
 * punya sumber data. Satu migrasi, empat tabel: pola 000790 (baseline tables).
 *
 * - manpower:   JUMLAH ORANG per jabatan (role_key = enum DailyReportRole,
 *               satu sumber dengan pad cetak). manpower_count laporan
 *               DITURUNKAN dari jumlah headcount begitu baris ada.
 * - equipment:  ALAT-ALAT yang bekerja hari itu.
 * - receipts:   MATERIAL MASUK hari itu (diterima/ditolak) — BUKAN
 *               prj_daily_report_materials, yang tetap PEMAKAIAN (qty_used).
 * - activities: URAIAN / PROGRESS / TARGET / HAMBATAN per baris pekerjaan.
 *
 * Rujukan lintas modul (asset_id → ast_assets, goods_receipt_id →
 * inv_goods_receipts, item_id → inv_items) diindeks tanpa FK, kontrak
 * shared-ID CONVENTIONS §3. wbs_task_id juga tanpa FK meski satu modul:
 * generate-WBS menghapus-dan-membangun-ulang seluruh pohon tugas, dan sebuah
 * constraint akan menggagalkan regenerasi atau mengkaskade baris laporan —
 * dua-duanya salah; baris uraian yang tugasnya hilang tetap terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_daily_report_manpower', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('prj_daily_reports')->cascadeOnDelete();
            $table->string('role_key', 40); // DailyReportRole
            $table->unsignedSmallInteger('headcount');
            $table->string('notes', 200)->nullable();
            $table->timestamps();

            $table->unique(['daily_report_id', 'role_key']);
        });

        Schema::create('prj_daily_report_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('prj_daily_reports')->cascadeOnDelete();
            $table->unsignedBigInteger('asset_id')->nullable(); // ast_assets — indexed, no FK
            $table->string('description', 150);
            $table->unsignedSmallInteger('qty');
            $table->decimal('hours', 8, 2)->nullable();
            $table->timestamps();

            $table->index('asset_id');
        });

        Schema::create('prj_daily_report_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('prj_daily_reports')->cascadeOnDelete();
            $table->unsignedBigInteger('goods_receipt_id')->nullable(); // inv_goods_receipts — indexed, no FK
            $table->unsignedBigInteger('item_id')->nullable(); // inv_items — indexed, no FK
            $table->string('description', 200);
            $table->decimal('qty_received', 15, 3);
            $table->decimal('qty_rejected', 15, 3)->default(0);
            $table->string('unit', 20);
            $table->string('rejection_reason', 200)->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id');
            $table->index('item_id');
        });

        Schema::create('prj_daily_report_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('prj_daily_reports')->cascadeOnDelete();
            $table->unsignedBigInteger('wbs_task_id')->nullable(); // prj_wbs_tasks — indexed, no FK (regenerate)
            $table->string('description', 300);
            $table->string('progress_note', 150)->nullable();
            $table->string('target_note', 150)->nullable();
            $table->string('obstacle', 300)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('wbs_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_daily_report_activities');
        Schema::dropIfExists('prj_daily_report_receipts');
        Schema::dropIfExists('prj_daily_report_equipment');
        Schema::dropIfExists('prj_daily_report_manpower');
    }
};
