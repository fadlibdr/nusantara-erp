<?php

namespace Modules\Core\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Services\WebhookService;

/**
 * Pendengar KEDUA atas DocumentTransitioned (P-3d) — dan ia berjalan SESUDAH
 * transaksinya commit, dengan alasan yang sama persis dengan pendengar pertama.
 *
 * `DocumentTransitioned` dipancarkan DARI DALAM transaksi bisnis yang masih
 * menulis ke buku besar; docblock peristiwanya sendiri mengatakannya: "anything
 * a listener does has to be incapable of failing it". Sebuah persetujuan yang
 * dibatalkan sesaat kemudian — periode fiskal tertutup, jurnal tidak seimbang —
 * tidak boleh sudah terlanjur memberi tahu sistem lain bahwa dokumennya
 * disetujui. Baris kotak masuk akan ikut ter-rollback; sebuah POST ke server
 * orang lain tidak bisa ditarik kembali, dan itu yang terburuk dari keduanya.
 *
 * DUA LAPIS, dan keduanya diuji: pendengar ini
 * `ShouldHandleEventsAfterCommit`, dan job yang diantrekannya
 * `ShouldQueueAfterCommit`. `WebhookTransactionTest` membatalkan transaksi
 * sesudah dispatch dan menuntut NOL job dan NOL baris log.
 *
 * TIDAK MENJATUHKAN PERSETUJUANNYA. Langganan yang URL-nya busuk, tabel yang
 * belum termigrasi, langganan yang barisnya rusak: `WebhookService::dispatchFor`
 * menelan Throwable apa pun ke dalam log. Dokumennya sudah disetujui; webhook
 * bersifat advisory.
 */
class DispatchDocumentWebhooks implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function handle(DocumentTransitioned $event): void
    {
        $this->webhooks->dispatchFor($event->document, $event->action, $event->actor, $event->note);
    }
}
