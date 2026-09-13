<?php

namespace Tests\Support;

use Minishlink\WebPush\MessageSentReport;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Support\WebPushSender;
use RuntimeException;

/**
 * Pengirim yang MELEDAK bila dipanggil (P-3e, T3e.3).
 *
 * Dipakai untuk memaku satu janji yang tidak bisa dipaku
 * `Http::preventStrayRequests()`: tanpa konfigurasi VAPID, tidak ada satu
 * permintaan pun yang boleh keluar dari mesin. Karena pustaka pengirimnya
 * memakai Guzzle mentah, penjaga Laravel tidak melihatnya — yang melihatnya
 * adalah kelas ini, dipasang lewat container.
 */
class ExplodingWebPushSender extends WebPushSender
{
    public int $calls = 0;

    public function send(PushSubscription $subscription, string $payload): MessageSentReport
    {
        $this->calls++;

        throw new RuntimeException(
            'Pengirim web push dipanggil padahal gerbang seharusnya menahannya: sebuah permintaan sungguhan '
            .'akan keluar dari mesin ini.',
        );
    }
}
