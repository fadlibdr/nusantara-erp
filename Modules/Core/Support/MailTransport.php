<?php

namespace Modules\Core\Support;

/**
 * Apakah mailer yang sedang aktif benar-benar MENGELUARKAN surat dari mesin
 * ini (P-3a, langkah "kejujuran status").
 *
 * Satu jawaban untuk tiga penanya yang sebelumnya menjawab sendiri-sendiri:
 * halaman masuk (Iam\Support\PasswordHelp: "tautan reset sampai?"), kotak
 * keluar (NotificationService: tulis `queued` atau `skipped`?), dan kanal
 * e-mail (MailChannel: kirim atau lempar skipped?). Diukur 11 Sep 2026: dengan
 * MAIL_MAILER=log — keadaan .env pengembangan DAN produksi — Mail::send()
 * memulangkan SentMessage ber-Message-ID buatan lokal (…@example.co.id), job
 * menandai baris `sent`, dan tidak ada satu surel pun yang pernah keluar.
 * `sent` adalah klaim; `skipped` adalah kejujuran.
 *
 * Yang diperiksa adalah TRANSPORT mailer bawaan, bukan hanya nama mailer-nya:
 * `mail.mailers.<nama>.transport` adalah yang menentukan ke mana surat pergi,
 * dan nama boleh saja 'smtp' sementara transport-nya 'log' (konfigurasi
 * uji). Nama mailer ikut diperiksa sebagai jaring kedua.
 */
final class MailTransport
{
    /**
     * Transport yang "berhasil" tanpa ada orang yang menerima: log menulis ke
     * berkas, array menyimpan di memori proses (uji), null membuang.
     */
    public const UNDELIVERED = ['log', 'array', 'null'];

    public static function mailerName(): string
    {
        return trim((string) config('mail.default'));
    }

    public static function transportName(): string
    {
        $name = self::mailerName();
        $transport = config("mail.mailers.{$name}.transport");

        return trim((string) ($transport ?? $name));
    }

    public static function leavesTheMachine(): bool
    {
        $name = self::mailerName();

        if ($name === '') {
            return false;
        }

        return ! in_array($name, self::UNDELIVERED, true)
            && ! in_array(self::transportName(), self::UNDELIVERED, true);
    }

    /**
     * Sebab `skipped` bila mailer tidak mengeluarkan surat — kalimat yang
     * tampil di kolom "Galat / alasan" layar Pengiriman Notifikasi. Null bila
     * mailer sungguhan.
     */
    public static function skipReason(): ?string
    {
        if (self::leavesTheMachine()) {
            return null;
        }

        $name = self::mailerName() === '' ? '(kosong)' : self::mailerName();
        $where = match (self::transportName()) {
            'array' => 'pesan hanya disimpan di memori proses',
            'null' => 'pesan dibuang',
            default => 'pesan hanya ditulis ke berkas log',
        };

        return "MAIL_MAILER={$name} — belum ada server surel; {$where}, tidak keluar dari mesin. "
            .'Arahkan MAIL_* di .env ke server sungguhan (DEPLOYMENT.md §11).';
    }
}
