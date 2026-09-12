<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Mail;

/**
 * Pasang CapturingMailTransport sebagai mailer bawaan untuk satu uji.
 *
 * Nama mailer dan transport-nya 'uji' — sengaja bukan 'smtp', supaya sebuah
 * uji yang lupa memanggil ini tidak pernah tersambung ke SMTP sungguhan dari
 * konfigurasi .env (tidak satu surel pun boleh keluar dari mesin ini selama
 * pembangunan; perangkap I P-3a).
 */
trait UsesCapturingMailer
{
    protected function useCapturingMailer(): CapturingMailTransport
    {
        $transport = new CapturingMailTransport;

        config([
            'mail.mailers.uji' => ['transport' => 'uji'],
            'mail.default' => 'uji',
        ]);

        Mail::extend('uji', static fn (): CapturingMailTransport => $transport);
        // Mailer 'uji' yang mungkin sudah diresolusi uji sebelumnya di proses
        // yang sama memegang transport lama; buang supaya yang baru dipakai.
        Mail::forgetMailers();

        return $transport;
    }
}
