<?php

namespace Tests\Support;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Transport surel PALSU yang berlaku seperti server sungguhan bagi kode yang
 * diuji (P-3a): ia menerima pesan, MENYIMPANNYA untuk asersi, dan — karena
 * ia turunan AbstractTransport — Mail::send() memulangkan SentMessage
 * ber-Message-ID seperti pada SMTP yang selesai dengan 250.
 *
 * Kenapa bukan Mail::fake(): fake memulangkan null dari send(), dan sejak
 * P-3a null berarti "tidak ada bukti diterima" → baris `failed`. Kenapa bukan
 * transport 'array': ia ada di daftar MailTransport::UNDELIVERED (dan memang
 * tidak mengeluarkan apa pun) → baris `skipped`. Uji yang ingin membuktikan
 * `sent` membutuhkan transport yang BUKAN keduanya — dan yang tetap tidak
 * mengirim satu surel pun keluar dari mesin ini.
 *
 * Dipasang lewat UsesCapturingMailer::useCapturingMailer().
 */
final class CapturingMailTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public array $messages = [];

    protected function doSend(SentMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function __toString(): string
    {
        return 'uji://tangkap';
    }
}
