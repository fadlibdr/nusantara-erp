<?php

namespace Modules\Iam\Support;

use App\Models\User;

/**
 * Apa yang halaman masuk boleh katakan soal kata sandi — dijawab server,
 * bukan ditebak SPA.
 *
 * Sampai 2 Sep 2026 menu akun hanya "Tutup · Keluar" dan setiap penggantian
 * sandi lewat administrator (HASIL-UJI §1, S9). "Lupa kata sandi" lewat email
 * hanya jujur bila suratnya sampai ke orang: dengan MAIL_MAILER=log (bawaan
 * .env.example dan erp1) tautannya mendarat di storage/logs — "terkirim"
 * menurut Laravel, tidak sampai menurut siapa pun. Selama itu halaman masuk
 * menyebut nama administrator, dan alur emailnya ditolak di server juga,
 * supaya klien API mana pun mendapat jawaban yang sama dengan SPA.
 */
final class PasswordHelp
{
    /**
     * Mailer yang "berhasil" tanpa ada orang yang menerima: log menulis ke
     * berkas, array menyimpan di memori proses (uji), null membuang.
     */
    private const UNDELIVERED = ['log', 'array', 'null'];

    public static function resetByEmail(): bool
    {
        return ! in_array((string) config('mail.default'), self::UNDELIVERED, true);
    }

    /**
     * Nama (bukan email) pemegang peran admin yang masih aktif, yang paling
     * dulu dibuat — pada instalasi baku itu akun bootstrap AdminUserSeeder.
     * Hanya nama: halaman masuk itu publik, dan nama orang yang mengurus ERP
     * sudah diketahui rekan sekantornya; alamatnya tidak perlu ikut tercetak.
     * Null bila tidak ada — halaman masuk lalu menyebut "administrator sistem"
     * alih-alih mengarang nama.
     */
    public static function administratorName(): ?string
    {
        $name = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'admin'))
            ->orderBy('id')
            ->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * Satu kalimat untuk orang yang lupa sandinya dan tidak bisa dikirimi
     * tautan — dipakai halaman masuk dan penolakan 409 di forgot-password.
     */
    public static function askAdministrator(): string
    {
        $name = self::administratorName();

        return $name !== null
            ? "Minta {$name} (administrator) mengatur ulang kata sandi Anda."
            : 'Minta administrator sistem mengatur ulang kata sandi Anda.';
    }

    /**
     * Tautan di dalam surat mengarah ke layar SPA, bukan route('password.reset')
     * bawaan Laravel yang tidak ada di aplikasi ini (routes/web.php hanya
     * punya /login sebagai penjawab 401). Query di belakang hash: router.js
     * memisahkan '?' sebelum mencocokkan pola, dan app.js membacanya SEBELUM
     * memeriksa sesi.
     */
    public static function resetUrl(User $user, string $token): string
    {
        return url('/').'#/reset-password?token='.rawurlencode($token)
            .'&email='.rawurlencode($user->getEmailForPasswordReset());
    }
}
