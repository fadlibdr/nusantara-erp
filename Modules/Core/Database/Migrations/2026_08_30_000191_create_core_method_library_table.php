<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: pustaka metode kerja — "Metode Pelaksanaan" yang dirujuk penawaran.
 *
 * DI CORE, seperti core_locations, karena dua sisi memakainya: Crm merujuknya
 * dari penawaran (crm_quotations.method_library_id) dan sisi pelaksanaan
 * membacanya sebagai instruksi kerja. Core tidak boleh bergantung ke modul mana
 * pun (ARCHITECTURE.md), jadi tabel ini tidak menyimpan satu pun kolom milik
 * modul lain — tidak ada project_id, tidak ada boq_item_id. Ia adalah master
 * perusahaan, bukan dokumen proyek.
 *
 * IZINNYA est.*, BUKAN core.*, dengan alasan yang persis sama yang membuat
 * core_locations memakai prj.*: yang MENULIS sebuah metode pelaksanaan adalah
 * estimator/drafter yang menyusunnya bersama RAB, sementara core.* hanya
 * dipegang admin dan direktur di RoleSeeder — memakai core.* akan membuat
 * pustaka ini praktis hanya-admin dan mendorong orang menempelkan metodenya di
 * tempat lain.
 *
 * SATU BARIS = SATU VERSI. Revisi tidak menimpa: MethodLibraryService menerbitkan
 * baris baru berversi n+1 dan menstempel superseded_by_id pada yang lama, pola
 * yang sama dengan revisi submittal P1-ENG. Versi lama tetap terbaca — sebuah
 * penawaran yang sudah dikirim mengutip versi yang berlaku SAAT ITU, dan
 * menimpanya akan mengubah lampiran surat yang sudah keluar. Yang tidak boleh
 * adalah penawaran BARU mengutip versi yang sudah digantikan; itu dijaga
 * QuotationService, bukan di sini.
 *
 * category adalah string bebas, bukan enum, dan itu keputusan: taksonomi metode
 * kerja berbeda antar perusahaan (satu memakai disiplin, satu memakai jenis
 * pekerjaan), dan enum yang tidak memuat "pekerjaan tanah" akan memaksa orang
 * memilih kategori yang salah — yang lebih buruk daripada string yang jujur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_method_library', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('category', 60);            // struktur / arsitektur / mep / elv / …
            $table->string('work_package', 200);       // paket pekerjaan yang dimetodekan
            $table->string('title', 250);
            $table->unsignedSmallInteger('version')->default(1);
            $table->text('summary')->nullable();
            $table->date('effective_date')->nullable();
            // Baris pengganti. constrained di dalam modul sendiri (Core → Core);
            // nullOnDelete supaya menghapus revisi tidak menghapus asalnya.
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('core_method_library')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // users — indexed, no FK
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['category', 'work_package', 'version']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_method_library');
    }
};
