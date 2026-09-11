<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Exceptions\DeliveryRetryRefusedException;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\FailedJob;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\Erp;
use Modules\Core\Support\NotificationTemplates;
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
                    $this->deciderPhrase($document, $actor, $action),
                    $approved ? 'menyetujui' : 'menolak',
                    mb_strtolower($label)." {$code}",
                    $note === null || $note === '' ? '' : " Catatan: {$note}",
                )),
                $actor,
            );
        });
    }

    /**
     * "Budi a.n. Sari" — nama yang dibaca pengaju di kotak masuknya.
     *
     * F-1 mencap "a.n." pada jejak (core_approvals.on_behalf_of_user_id) dan
     * merendernya di layar detail, tetapi pemberitahuan yang benar-benar
     * SAMPAI kepada pengaju masih berbunyi "Budi menyetujui" — dan bagi
     * pengaju itu, pemberitahuan itulah keseluruhan ceritanya (verifikasi F-1,
     * 7 Sep 2026: baris jejaknya benar, isi pemberitahuannya tidak menyebut
     * Sari sama sekali).
     *
     * Dibaca dari BARIS yang baru ditulis, bukan ditanyakan ulang kepada
     * ApprovalDelegations: pendengarnya berjalan sesudah commit, jadi barisnya
     * sudah ada, dan jawaban yang sudah tercatat tidak boleh dihitung ulang
     * dengan delegasi yang mungkin sudah dicabut semenit kemudian.
     *
     * Satu SELECT tambahan per keputusan, dan hanya bila kolomnya ada.
     */
    private function deciderPhrase(Model $document, ?User $actor, string $action): string
    {
        if ($actor === null) {
            return 'Seseorang';
        }

        if (! Schema::hasColumn('core_approvals', 'on_behalf_of_user_id')) {
            return (string) $actor->name;
        }

        $giverId = DB::table('core_approvals')
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->where('action', $action)
            ->where('user_id', $actor->getKey())
            ->orderByDesc('id')
            ->value('on_behalf_of_user_id');

        $giver = $giverId === null ? null : User::query()->find($giverId)?->name;

        return $giver === null ? (string) $actor->name : "{$actor->name} a.n. {$giver}";
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
     *
     * $template (P-3a, T3a.1) is one of the five NotificationTemplates keys,
     * stored on the row so the outside channels pick the right mail shape
     * and the right Meta-approved WhatsApp template. Null — every caller
     * that does not name one — means the GENERIC template, on purpose and
     * out loud: unknown keys are refused here rather than stored, so a typo
     * cannot become a silent "generic".
     */
    public function system(string $permission, string $title, string $body, ?string $link = null, ?int $renagAfterDays = null, ?string $signature = null, ?string $template = null): void
    {
        if ($template !== null && ! NotificationTemplates::has($template)) {
            throw new \InvalidArgumentException("Template notifikasi \"{$template}\" tidak terdaftar di NotificationTemplates.");
        }

        $this->guard(function () use ($permission, $title, $body, $link, $renagAfterDays, $signature, $template): void {
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

            $this->write($recipients, fn (User $recipient): array => [
                'user_id' => $recipient->id,
                'event' => Notification::SYSTEM,
                'template' => $template,
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'document_type' => null,
                'document_id' => null,
                'document_code' => $signature,
                'actor_id' => null,
                'read_at' => null,
            ]);
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

        $this->write($recipients, fn (User $recipient): array => [
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
    }

    /**
     * Dua tahap, dalam urutan ini: SEMUA baris dalam aplikasi dulu (kanal
     * kebenaran), baru kotak keluar per penerima — masing-masing di balik
     * guard()-nya sendiri. Menyelang keduanya per penerima berarti satu tulisan
     * kotak keluar yang gagal (tabel belum termigrasi, SQLite terkunci lewat
     * busy_timeout, alamat > 190 karakter di MySQL ketat) membuat penerima
     * berikutnya tidak pernah diberi tahu sama sekali — dua penyetuju, satu
     * baris (verifikasi P-0b, 5 Sep 2026). Tulisan kotak keluar yang gagal
     * tetap tercatat di log; yang tidak boleh ikut hilang adalah kanal yang
     * bekerja di setiap instalasi.
     *
     * @param  Collection<int, User>  $recipients
     * @param  callable(User): array<string, mixed>  $attributes
     */
    private function write(Collection $recipients, callable $attributes): void
    {
        $written = [];

        foreach ($recipients as $recipient) {
            $written[] = [Notification::query()->create($attributes($recipient)), $recipient];
        }

        foreach ($written as [$notification, $recipient]) {
            $this->guard(fn () => $this->outbox($notification, $recipient));
        }
    }

    /** Dipertahankan untuk pemanggil lama; kalimatnya kini milik DeliveryGate (P-3a). */
    public const SKIP_EMAIL_DISABLED = DeliveryGate::EMAIL_DISABLED;

    public const SKIP_NO_ADDRESS = DeliveryGate::EMAIL_NO_ADDRESS;

    /**
     * Kotak keluar: satu baris pengiriman per kanal luar untuk notifikasi yang
     * baru ditulis, lalu job-nya. Hari ini satu kanal (e-mail).
     *
     * Barisnya ditulis DULU — kalau tulisan ke tabel sendiri gagal, tidak ada
     * yang bisa dilaporkan selain log, dan write() menjaga agar kegagalan itu
     * berhenti pada penerima ini. Dispatch dijaga sendiri: antrean yang mati
     * tidak boleh membatalkan persetujuan yang sedang dilaporkan, dan baris
     * `queued` tanpa job adalah persis yang dilihat operator di layar (dan
     * yang dihitung core/health sebagai queued_deliveries_older_than_1h).
     *
     * Sebab `skipped` datang dari DeliveryGate — satu daftar untuk kotak
     * keluar, Kirim ulang, dan job (P-3a): e-mail dimatikan di Pengaturan,
     * MAIL_MAILER masih log (sebelumnya baris ini `queued` lalu `sent` dengan
     * Message-ID lokal — diukur 11 Sep 2026), atau penerima tanpa alamat.
     */
    private function outbox(Notification $notification, User $recipient): void
    {
        $channel = NotificationDelivery::CHANNEL_EMAIL;
        $reason = DeliveryGate::reasonToSkip($channel, $recipient);

        $delivery = new NotificationDelivery([
            'notification_id' => $notification->id,
            'channel' => $channel,
            'recipient' => DeliveryGate::address($channel, $recipient),
            'status' => $reason === null ? NotificationDelivery::QUEUED : NotificationDelivery::SKIPPED,
            'attempts' => 0,
            'error' => $reason,
        ]);

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
     * Alamatnya dibaca ULANG dari penggunanya, bukan dari `recipient` yang
     * dibekukan saat baris ditulis: baris `skipped` karena alamat kosong
     * menyuruh operator melengkapi alamat di Sistem › Pengguna lalu kirim ulang,
     * dan perintah itu hanya bisa dipenuhi bila yang dibaca adalah alamat yang
     * baru (verifikasi P-0b, 5 Sep 2026: dengan alamat beku, 422 selamanya).
     * `recipient` diperbarui ke alamat yang benar-benar dituju job baru.
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
     * @throws DeliveryRetryRefusedException bila tidak bisa dikirim ulang
     */
    public function retry(NotificationDelivery $delivery): NotificationDelivery
    {
        if ($delivery->status === NotificationDelivery::SENT) {
            throw new DeliveryRetryRefusedException('Pengiriman ini sudah diterima penyedia; tidak ada yang perlu dikirim ulang.');
        }

        $recipient = $delivery->notification?->user;

        if ($recipient === null) {
            throw new DeliveryRetryRefusedException('Penerima notifikasi ini sudah tidak ada; tidak ada alamat untuk dikirimi.');
        }

        // Gerbang yang SAMA dengan pengiriman pertama (DeliveryGate, P-3a):
        // sebabnya ditolak dengan kalimat + petunjuknya, bukan diantrekan
        // untuk `skipped` lagi.
        $reason = DeliveryGate::reasonToSkip($delivery->channel, $recipient);

        if ($reason !== null) {
            throw new DeliveryRetryRefusedException(DeliveryGate::retryRefusal($reason));
        }

        $delivery->recipient = DeliveryGate::address($delivery->channel, $recipient);

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

    /**
     * @return Collection<int, User>
     */
    /**
     * Siapa yang diberi tahu — pemegang izinnya, DAN delegat yang memegang
     * hak itu untuk sementara (F-1).
     *
     * Tanpa baris kedua, delegasi hanyalah setengah fitur: Budi boleh
     * menyetujui a.n. Sari tetapi tidak pernah tahu ada yang menunggu, jadi
     * dokumen tetap menua persis seperti sebelumnya (diukur 4 Sep 2026:
     * PAY/2026/VIII/0002 menunggu 33 hari). Yang dicegah delegasi adalah
     * antrean yang berhenti karena satu orang pergi; pemberitahuan yang tidak
     * ikut pindah tidak mencegah apa pun.
     *
     * Delegat yang KEBETULAN juga pemegang izinnya sendiri hanya muncul sekali
     * (unique), dan pengaju tetap dikecualikan sesudah penggabungan — bukan di
     * dalam kueri pertama, yang dulu melewatkan pengaju yang masuk lewat
     * jalur delegasi.
     */
    private function approvers(string $permission, ?User $actor): Collection
    {
        $holders = User::query()
            ->permission($permission)
            ->where('is_active', true)
            ->get();

        return $holders
            ->merge($this->delegatesFor($permission, $holders))
            ->unique(fn (User $user) => $user->getKey())
            ->reject(fn (User $user) => $actor !== null && (int) $user->getKey() === (int) $actor->getKey())
            ->values();
    }

    /**
     * Penerima delegasi hidup dari salah satu pemegang izin ini.
     *
     * Pemberinya harus ada di $holders: hak yang didelegasikan adalah hak yang
     * DIPEGANG pemberinya, jadi seseorang tidak dapat mewariskan izin yang
     * tidak dimilikinya — aturan yang sama yang ditegakkan
     * ApprovalDelegations::grants saat menyetujui, di sini supaya daftar yang
     * diberi tahu dan daftar yang boleh menyetujui adalah daftar yang sama.
     *
     * @param  Collection<int, User>  $holders
     * @return Collection<int, User>
     */
    private function delegatesFor(string $permission, Collection $holders): Collection
    {
        if ($holders->isEmpty() || ! Schema::hasTable('core_approval_delegations')) {
            return new Collection;
        }

        $prefix = str_contains($permission, '.') ? explode('.', $permission)[0] : null;
        $today = now()->toDateString();

        $ids = DB::table('core_approval_delegations')
            ->whereIn('giver_user_id', $holders->map(fn (User $user) => $user->getKey())->all())
            ->whereNull('revoked_at')
            ->whereDate('starts_at', '<=', $today)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today))
            ->where(fn ($query) => $query->whereNull('scope')->orWhere('scope', $prefix))
            ->pluck('delegate_user_id')
            ->unique()
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        return User::query()->whereIn('id', $ids)->where('is_active', true)->get();
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
