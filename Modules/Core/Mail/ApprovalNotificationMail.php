<?php

namespace Modules\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email twin of an in-app approval notification.
 *
 * Built from an inline HTML string rather than a Blade view: the body is three
 * lines and a link, and a view file would put the wording somewhere other than
 * the code that decides it. Deliberately plain — approval mail that renders
 * differently in Outlook is worse than approval mail that renders identically
 * everywhere.
 */
class ApprovalNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        $html = '<p style="font:15px/1.5 system-ui,sans-serif"><strong>'.e($this->title).'</strong></p>'
            .'<p style="font:14px/1.6 system-ui,sans-serif;color:#444">'.e($this->body).'</p>'
            .($this->url === null
                ? ''
                : '<p style="font:14px/1.6 system-ui,sans-serif"><a href="'.e($this->url).'">Buka dokumen</a></p>')
            .'<p style="font:12px/1.6 system-ui,sans-serif;color:#888">Nusantara ERP</p>';

        return new Content(htmlString: $html);
    }
}
