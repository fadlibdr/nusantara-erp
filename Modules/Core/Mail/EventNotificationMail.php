<?php

namespace Modules\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Surel untuk salah satu dari lima peristiwa operasional NotificationTemplates
 * (P-3a, T3a.1) — saudara ApprovalNotificationMail, yang tetap menjadi
 * template UMUM untuk peristiwa tanpa entri registri.
 *
 * Yang membedakannya dari yang umum hanya tiga hal yang datang dari registri:
 * awalan subjek ("[Cadangan] …" — supaya filter kotak surat direktur bisa
 * memisahkan alarm dari persetujuan), satu kalimat pembuka yang menjelaskan
 * MENGAPA surat ini datang, dan label tombolnya. Isi dan judul tetap milik
 * notifikasi; template tidak mengarang fakta.
 *
 * HTML inline, bukan Blade, dengan alasan yang sama seperti saudaranya:
 * badannya empat baris, dan berkas view akan menaruh kalimatnya jauh dari
 * kode yang memutuskannya.
 */
class EventNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{key: string, label: string, mail: array{subject_prefix: string, intro: string, cta: string}}  $template
     */
    public function __construct(
        public readonly array $template,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trim($this->template['mail']['subject_prefix'].' '.$this->title));
    }

    public function content(): Content
    {
        $html = '<p style="font:14px/1.6 system-ui,sans-serif;color:#444">'.e($this->template['mail']['intro']).'</p>'
            .'<p style="font:15px/1.5 system-ui,sans-serif"><strong>'.e($this->title).'</strong></p>'
            .'<p style="font:14px/1.6 system-ui,sans-serif;color:#444">'.e($this->body).'</p>'
            .($this->url === null
                ? ''
                : '<p style="font:14px/1.6 system-ui,sans-serif"><a href="'.e($this->url).'">'.e($this->template['mail']['cta']).'</a></p>')
            .'<p style="font:12px/1.6 system-ui,sans-serif;color:#888">Nusantara ERP — pemberitahuan otomatis ('
            .e($this->template['label']).'). Jangan membalas surel ini.</p>';

        return new Content(htmlString: $html);
    }
}
