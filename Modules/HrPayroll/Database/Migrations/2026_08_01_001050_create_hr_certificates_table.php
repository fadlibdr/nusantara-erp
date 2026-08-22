<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register sertifikat kompetensi (SKK Konstruksi, K3/AK3, sertifikasi principal).
 *
 * Until now no table knew which certificates the company's people hold or when
 * they lapse. The price of finding out late is written in config/erp.php
 * (PP 9/2022): PPh final pelaksanaan konstruksi is 2,65% bersertifikat versus
 * 4,00% tanpa sertifikat — 1,35 points of every construction billing — plus
 * disqualification from any tender that scores personnel certificates. The
 * register exists so "whose SKK expires this quarter" is a query, not a memory.
 *
 * Renewal is an UPDATE of expiry_date; a certificate the company stops caring
 * about is soft-deleted to stop the reminder. No supersede chain — the bank's
 * paper is re-issued under the same identity, the row just moves its date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_certificates', function (Blueprint $table): void {
            $table->id();
            // restrict, not cascade: deleting an employee must not silently
            // shred the evidence a tender or a tax rate was scored on.
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('certificate_type', 30); // skk | k3 | principal | lainnya
            $table->string('name', 160); // "SKK Ahli Madya Teknik Bangunan Gedung"
            $table->string('number', 100)->nullable(); // registrar's number, when it has one
            $table->string('issuer', 160)->nullable(); // LPJK / Kemnaker / nama principal
            $table->date('issued_date')->nullable();
            // NULL means tidak kedaluwarsa (some principal certificates never
            // lapse) — the deadline watcher skips NULL instead of alarming on it.
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('certificate_type');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_certificates');
    }
};
