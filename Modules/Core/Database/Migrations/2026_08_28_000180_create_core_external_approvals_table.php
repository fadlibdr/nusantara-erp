<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan eksternal — keputusan MK/Owner atas satu dokumen, lewat tautan
 * sekali-pakai atau lembar fisik bertanda tangan (keputusan pemilik #1,
 * ✅ 22 Agu). SATU BARIS ADALAH SATU MANDAT: diterbitkan untuk satu pihak
 * dengan nama orangnya, dan setelah terpakai baris yang sama membawa
 * keputusannya. Bukti penerbitan dan bukti keputusan tidak bisa saling lepas.
 *
 * TOKEN TIDAK PERNAH DISIMPAN. Kolomnya adalah sha256 dari token; teks
 * polosnya tampil tepat sekali di respons penerbitan lalu hilang dari server.
 * Siapa pun yang membaca tabel ini (backup, dump, log query) tidak bisa
 * membangun kembali tautannya — persis alasan password disimpan sebagai hash.
 * Baris lembar fisik tidak punya token sama sekali, maka kolomnya nullable
 * dan unique (NULL ganda sah di MySQL maupun SQLite).
 *
 * document_slug + document_id, TANPA FK dan tanpa kolom class: dokumen
 * pemiliknya tersebar di modul lain (Projects, Crm), dan aturan FK
 * lintas-modul CONVENTIONS §3 berlaku untuk Core juga. Slug divalidasi lewat
 * registri ExternalApprovableDocuments — pola AttachableDocuments, bukan
 * string kelas dari kawat.
 *
 * attachment_id menunjuk core_attachments (tabel Core sendiri, maka
 * constrained): scan lembar fisik yang ditandatangani. ExternalApprovalService
 * menolak lampiran yang terpasang pada dokumen lain — bukti harus menempel
 * pada dokumen yang diputuskan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_external_approvals', function (Blueprint $table): void {
            $table->id();

            $table->string('document_slug', 60);
            $table->unsignedBigInteger('document_id');

            // mk | owner — pihak yang dimintai keputusan.
            $table->string('party', 10);
            $table->string('name', 120);
            $table->string('organization', 150)->nullable();
            $table->string('email', 150)->nullable();

            $table->char('token_hash', 64)->nullable()->unique();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');

            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');

            // approved | approved_with_notes | rejected — NULL selama tautan
            // hidup. decided_via: link | physical.
            $table->string('decision', 30)->nullable();
            $table->string('decision_notes', 1000)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('decided_via', 10)->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('core_attachments');

            $table->timestamps();

            $table->index(['document_slug', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_external_approvals');
    }
};
