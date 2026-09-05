<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\FailedJob;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Core\Support\Erp;
use Modules\Core\Support\SegregationOfDuties;

/**
 * Who gets told when a document moves through approval.
 *
 * ONE RULE ABOVE ALL: notifying must never break the thing it is reporting on.
 * These calls sit inside approval flows that are already writing to the ledger,
 * so every delivery path is wrapped — a mail server that is down, a permission
 * that was renamed, a recipient whose account was deleted, none of them may roll
 * back an approval. Failures are logged and swallowed; a lost notification is an
 * inconvenience, a lost approval is a corrupted book.
 *
 * Delivery:
 *
 *  - IN-APP, always, synchronously. It needs no external service, so it is the
 *    channel that actually works on every installation — the channel of truth.
 *  - Every OUTSIDE channel goes through the outbox (Fase 0 / P-0b, T0b.3): one
 *    core_notification_deliveries row per recipient, then a queued
 *    DeliverNotification job. EMAIL is written `queued` when
 *    notifications.email_enabled is on AND the recipient has an address, and
 *    `skipped` with the reason otherwise — off by default, because
 *    config('mail.default') is 'log' on a fresh install and silently writing
 *    approval traffic into the application log is worse than not sending it.
 *    A failed send is `failed` with the provider's message, visible in
 *    Sistem › Pengiriman Notifikasi with a Kirim ulang button. What guard()
 *    still wraps is the DISPATCH: a dead queue must never roll back an
 *    approval, but the queued row it leaves behind is exactly what the screen
 *    shows.
 *  - WHATSAPP and web push are Fase 3: implement DeliveryChannel, register it
 *    in DeliveryChannels, and write their rows in outbox().
 */
class NotificationService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * A document was submitted: tell everyone who can approve it.
     *
     * The submitter is excluded even when they hold the permission themselves —
     * a notification telling you about your own click is noise, and noise is how
     * an inbox stops being read.
     */
    public function documentSubmitted(Model $document, ?User $actor = null): void
    {
        $this->guard(function () use ($document, $actor): void {
            $permission = ApprovableDocuments::approvePermission($document);

            if ($permission === null) {
                return;
            }

            $label = ApprovableDocuments::label($document);
            $code = (string) ($document->code ?? $document->getKey());

            $this->deliver(
                $this->approvers($permission, $actor),
                $document,
                Notification::SUBMITTED,
                "{$label} {$code} menunggu persetujuan",
                trim(($actor?->name ?? 'Seseorang').' mengajukan '.mb_strtolower($label)." {$code}."),
                $actor,
            );
        });
    }

    /**
     * A document was approved or rejected: tell whoever submitted it.
     */
    public function documentDecided(Model $document, string $action, ?User $actor = null, ?string $note = null): void
    {
        $this->guard(function () use ($document, $action, $actor, $note): void {
            $submitter = $this->submitterOf($document);

            if ($submitter === null || ($actor !== null && $submitter->id === $actor->id)) {
                return;
            }

            $label = ApprovableDocuments::label($document);
            $code = (string) ($document->code ?? $document->getKey());
            $approved = $action === 'approved';

            $this->deliver(
                new Collection([$submitter]),
                $document,
                $approved ? Notification::APPROVED : Notification::REJECTED,
                "{$label} {$code} ".($approved ? 'disetujui' : 'ditolak'),
                trim(sprintf(
                    '%s %s %s.%s',
                    $actor?->name ?? 'Seseorang',
                    $approved ? 'menyetujui' : 'menolak',
                    mb_strtolower($label)." {$code}",
                    $note === null || $note === '' ? '' : " Catatan: {$note}",
                )),
                $actor,
            );
        });
    }

    /**
     * An operational alarm from the system itself: backups gone stale, a cron
     * that stopped. Delivered to every active holder of a permission.
     *
     * Deduplicated on (event, title, unread): an alarm that fires daily must
     * nag, not bury — nine unread copies of "offsite backup stale" read as
     * noise, and noise is how an inbox stops being read.
     *
     * $renagAfterDays is a SECOND suppression for recurring watchers with long
     * leads: a recipient is also skipped when any same-title system
     * notification was created within the last N days, READ OR NOT. Without
     * it, erp:deadline-watch on a 60-day lead would re-insert "Sertifikat
     * mendekati kedaluwarsa" every morning after each read — 60 copies of one
     * fact. Null keeps the original read-then-refire behaviour byte-identical
     * for CloseWatch, BackupWatch and EnsureFiscalCalendar.
     *
     * $signature narrows BOTH suppressions to copies carrying the same content
     * fingerprint (stored in the otherwise-NULL document_code). Title alone is
     * deliberately stable, so without this a third PO going overdue the day
     * after "Total 2 PO." was delivered stayed hidden — indefinitely while the
     * old copy sat unread, 3 more days after a read — with the inbox actively
     * understating. A changed fingerprint fires immediately, exactly like the
     * tier-change escalation; bodies still mutate daily (ages), which is why
     * the comparison is this fingerprint and never the body text. Null keeps
     * the title-only dedupe byte-identical for every non-deadline caller.
     */
    public function system(string $permission, string $title, string $body, ?string $link = null, ?int $renagAfterDays = null, ?string $signature = null): void
    {
        $this->guard(function () use ($permission, $title, $body, $link, $renagAfterDays, $signature): void {
            $holders = $this->approvers($permission, null);

            // Silence here would be an alarm about alarms failing: a system
            // alert nobody can receive should at least leave a trace in the log.
            if ($holders->isEmpty()) {
                Log::warning("System alert '{$title}' has no recipients — no active user holds {$permission}.");

                return;
            }

            $recipients = $holders
                ->reject(function (User $user) use ($title, $renagAfterDays, $signature): bool {
                    $sameTitle = Notification::query()
                        ->where('user_id', $user->id)
                        ->where('event', Notification::SYSTEM)
                        ->where('title', $title)
                        ->when($signature !== null, fn ($query) => $query->where('document_code', $signature));

                    if ((clone $sameTitle)->whereNull('read_at')->exists()) {
                        return true;
                    }

                    return $renagAfterDays !== null
                        && (clone $sameTitle)->where('created_at', '>=', now()->subDays($renagAfterDays))->exists();
                });

            if ($recipients->isEmpty()) {
                return;
            }

            foreach ($recipients as $recipient) {
                $notification = Notification::query()->create([
                    'user_id' => $recipient->id,
                    'event' => Notification::SYSTEM,
                    'title' => $title,
                    'body' => $body,
                    'link' => $link,
                    'document_type' => null,
                    'document_id' => null,
                    'document_code' => $signature,
                    'actor_id' => null,
                    'read_at' => null,
                ]);

                $this->outbox($notification, $recipient);
            }
        });
    }

    // ------------------------------------------------------------------ reads

    public function unreadCount(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function forUser(User $user, bool $unreadOnly = false, int $limit = 50): Collection
    {
        return Notification::query()
            ->with('actor:id,name')
            ->where('user_id', $user->id)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->limit(min(200, max(1, $limit)))
            ->get();
    }

    /**
     * Marking read is scoped to the caller's own rows — an id from another
     * user's inbox matches nothing rather than being marked on their behalf.
     */
    public function markRead(User $user, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Notification::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    // ----------------------------------------------------------- delivery

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function deliver(
        Collection $recipients,
        Model $document,
        string $event,
        string $title,
        string $body,
        ?User $actor,
    ): void {
        if ($recipients->isEmpty()) {
            return;
        }

        $link = ApprovableDocuments::link($document);

        foreach ($recipients as $recipient) {
            $notification = Notification::query()->create([
                'user_id' => $recipient->id,
                'event' => $event,
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'document_type' => $document::class,
                'document_id' => $document->getKey(),
                'document_code' => $document->code ?? null,
                'actor_id' => $actor?->id,
                'read_at' => null,
            ]);

            $this->outbox($notification, $recipient);
        }
    }

    public const SKIP_EMAIL_DISABLED = 'E-mail dinonaktifkan di Pengaturan.';

    public const SKIP_NO_ADDRESS = 'Penerima tidak punya alamat e-mail.';

    /**
     * Kotak keluar: satu baris pengiriman per kanal luar untuk notifikasi yang
     * baru ditulis, lalu job-nya. Hari ini satu kanal (e-mail).
     *
     * Barisnya ditulis DULU dan tanpa guard — kalau tulisan ke tabel sendiri
     * gagal, tidak ada yang bisa dilaporkan. Yang dijaga hanya dispatch:
     * antrean yang mati tidak boleh membatalkan persetujuan yang sedang
     * dilaporkan, dan baris `queued` tanpa job adalah persis yang dilihat
     * operator di layar (dan yang dihitung core/health sebagai
     * queued_deliveries_older_than_1h).
     */
    private function outbox(Notification $notification, User $recipient): void
    {
        $address = trim((string) $recipient->email);

        $delivery = new NotificationDelivery([
            'notification_id' => $notification->id,
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
            'recipient' => $address,
            'status' => NotificationDelivery::QUEUED,
            'attempts' => 0,
        ]);

        if (! $this->emailEnabled()) {
            $delivery->status = NotificationDelivery::SKIPPED;
            $delivery->error = self::SKIP_EMAIL_DISABLED;
        } elseif ($address === '') {
            $delivery->status = NotificationDelivery::SKIPPED;
            $delivery->error = self::SKIP_NO_ADDRESS;
        }

        $delivery->save();

        if ($delivery->status === NotificationDelivery::QUEUED) {
            $this->guard(fn () => DeliverNotification::dispatch($delivery->id));
        }
    }

    /**
     * Kirim ulang dari layar (POST core/notification-deliveries/{id}/retry).
     *
     * Syarat yang sama dengan pengiriman pertama diperiksa ULANG — e-mail yang
     * masih dimatikan atau penerima tanpa alamat ditolak dengan kalimatnya,
     * bukan diantrekan untuk gagal lagi. Yang sudah `sent` tidak dikirim dua
     * kali. attempts TIDAK direset: ia riwayat, dan pekerja menghitung
     * percobaannya sendiri.
     *
     * Dispatch di sini TIDAK dijaga: orangnya baru saja menekan tombol, dan
     * antrean yang menolak harus sampai ke dia sebagai galat, bukan sebagai
     * baris `queued` yang diam.
     *
     * Catatan failed_jobs kerangka kerja untuk baris ini dihapus SETELAH job
     * baru diantrekan: job baru menggantikannya, dan tanpa ini Sistem › Antrean
     * Gagal (serta "N job gagal" di dasbor) terus menunjuk kegagalan yang sudah
     * ditangani. Antrean Gagal sendiri menolak mengembalikan job pengiriman
     * (QueueFailedJobController) — satu tombol Kirim ulang, di sini.
     *
     * @throws InvalidArgumentException bila tidak bisa dikirim ulang
     */
    public function retry(NotificationDelivery $delivery): NotificationDelivery
    {
        if ($delivery->status === NotificationDelivery::SENT) {
            throw new InvalidArgumentException('Pengiriman ini sudah diterima penyedia; tidak ada yang perlu dikirim ulang.');
        }

        if ($delivery->channel === NotificationDelivery::CHANNEL_EMAIL) {
            if (! $this->emailEnabled()) {
                throw new InvalidArgumentException('E-mail masih dinonaktifkan di Pengaturan — nyalakan dulu, lalu kirim ulang.');
            }

            if (trim((string) $delivery->recipient) === '') {
                throw new InvalidArgumentException('Penerima tidak punya alamat e-mail; lengkapi alamatnya di Sistem › Pengguna, lalu kirim ulang.');
            }
        }

        $delivery->forceFill([
            'status' => NotificationDelivery::QUEUED,
            'error' => null,
            'next_attempt_at' => null,
        ])->save();

        DeliverNotification::dispatch($delivery->id);
        $this->forgetFailedJobsFor($delivery);

        return $delivery->refresh();
    }

    private function forgetFailedJobsFor(NotificationDelivery $delivery): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        $failer = app(FailedJobProviderInterface::class);

        foreach (FailedJob::forDelivery($delivery->id) as $failed) {
            $failer->forget((string) $failed->uuid);
        }
    }

    private function emailEnabled(): bool
    {
        return Erp::bool('notifications.email_enabled', false);
    }

    /**
     * @return Collection<int, User>
     */
    private function approvers(string $permission, ?User $actor): Collection
    {
        return User::query()
            ->permission($permission)
            ->where('is_active', true)
            ->when($actor !== null, fn ($query) => $query->where('id', '!=', $actor->id))
            ->get();
    }

    private function submitterOf(Model $document): ?User
    {
        // One implementation of "who submitted this", shared with the
        // maker-checker guard, so the person the guard refuses and the person
        // the decision notice reaches can never be two different people.
        // The is_active filter belongs here and not there: a resigned employee
        // still submitted the document (so the guard must see them), but has no
        // inbox worth writing to.
        $userId = SegregationOfDuties::submitterIdOf($document);

        return $userId === null
            ? null
            : User::query()->where('is_active', true)->find($userId);
    }

    /**
     * A notification is a side effect of the transaction it reports on, and it
     * must behave like one: never able to fail it.
     */
    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::warning('Notification delivery failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
