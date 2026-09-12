<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger folder terpantau rekening koran (P-3c, T3c.2).
 *
 * Aplikasi TIDAK PERNAH menulis ke folder itu — tidak memindah berkas yang
 * sudah diproses, tidak menandai, tidak menghapus (docblock
 * BankStatementParseRequest: "nothing in this application writes to disk").
 * Yang membuat pemeriksaan per jam idempoten adalah tabel ini: satu baris per
 * (jalur relatif, sha256 isi). Berkas yang sama dipindai lagi jam berikutnya →
 * baris yang sama, checked_at bergerak; berkas yang DIGANTI NAMA → baris baru
 * berstatus duplicate yang menunjuk rekening koran yang sama; berkas yang
 * ISINYA berubah di bawah nama lama → baris baru, diproses sebagai berkas baru.
 *
 * status: imported | failed | duplicate | ignored. error = kalimat Indonesia
 * untuk operator (juga untuk baris duplicate/ignored — sebabnya). Jalur yang
 * disimpan RELATIF terhadap folder terpantau; jalur absolut server tidak
 * pernah masuk basis data, API, atau notifikasi.
 *
 * bank_account_id / bank_statement_id tanpa FK: rekening bisa dihapus lunak
 * dan rekening koran bisa dihapus (obat pemetaan yang salah) — barisnya tetap
 * sejarah bahwa berkas itu pernah diproses. Keempat slot blok lanjutan Finance
 * 001500– (CONVENTIONS §2, tabel blok adalah sumber kebenarannya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_inbox_files', function (Blueprint $table): void {
            $table->id();
            $table->string('relative_path', 400);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size')->default(0);
            $table->dateTime('file_mtime')->nullable();
            $table->string('status', 20);
            // Finance-owned semantics (fin_bank_accounts.id / fin_bank_statements.id) — no DB constraint on purpose.
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->unsignedBigInteger('bank_statement_id')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('checked_at');
            $table->timestamps();

            $table->unique(['relative_path', 'sha256']);
            $table->index('sha256');
            $table->index('status');
            $table->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_inbox_files');
    }
};
