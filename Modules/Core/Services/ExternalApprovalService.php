<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\ExternalDecision;
use Modules\Core\Models\Attachment;
use Modules\Core\Models\ExternalApproval;
use Modules\Core\Support\ExternalApprovableDocuments;
use Modules\Core\Support\SegregationOfDuties;

/**
 * Persetujuan eksternal MK/Owner — keputusan pemilik #1 (✅ 22 Agu): tautan
 * sekali-pakai (setuju / setuju dengan catatan / tolak) atau lembar fisik.
 *
 * Empat aturan yang ditegakkan DI SINI, bukan di controller:
 *
 *  1. TOKEN POLOS HIDUP SATU KALI. issue() mengembalikannya sekali di nilai
 *     balik dan menyimpan sha256-nya saja; tidak ada jalan membaca ulang, dan
 *     tidak ada satu pun jalur log yang menyentuh teks polosnya.
 *  2. SEKALI-PAKAI DI BAWAH BALAPAN. decide() membaca ulang barisnya
 *     TERKUNCI di dalam transaksi (idiom StaleInstanceEditTest — lockForUpdate
 *     no-op di SQLite, maka yang diuji adalah pemeriksaan ulang pada baca
 *     ulang): dua klik pada tautan yang sama tidak pernah mencatat dua kali,
 *     siapa pun yang menang balapan, dan klik kedua diberi tahu jujur.
 *  3. PENERBITAN ADALAH KUASA SETINGKAT MENYETUJUI. Izin {prefix}.approve
 *     modul pemilik diperiksa controller (pola deny AttachmentController);
 *     identitas penerbit menempel di baris (issued_by) karena pada mode
 *     transisi keputusan diterapkan ATAS NAMANYA — dan maker-checker menolak
 *     penerbit yang adalah pengaju dokumennya sendiri, di sini saat terbit
 *     dan sekali lagi di adapter saat keputusan diterapkan (pengaju bisa
 *     berganti oleh reject-resubmit di antara keduanya).
 *  4. LEMBAR FISIK BUKAN KABAR ANGIN. recordPhysical() menuntut scan lembar
 *     bertanda tangan yang terlampir pada dokumen YANG SAMA; keputusan tanpa
 *     bukti tidak dicatat. Ia TIDAK dibatasi status dokumen: kertas bisa
 *     kembali berhari-hari setelah proksi internal menggerakkan dokumen, dan
 *     menolak mencatatnya berarti membuang bukti — kecuali pada mode
 *     transisi, di mana aturan Approvable di adapter tetap yang memutuskan.
 */
class ExternalApprovalService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** Masa berlaku bawaan tautan bila penerbit tidak memilih tanggal. */
    public const DEFAULT_VALIDITY_DAYS = 7;

    // ---------------------------------------------------------------- issue

    /**
     * @return array{approval: ExternalApproval, token: string, url: string}
     */
    public function issue(User $by, array $data): array
    {
        $slug = (string) $data['document_type'];
        $document = $this->resolveDocument($slug, (int) $data['document_id']);

        $this->assertIssuable($slug, $document);
        $this->assertIssuerIsNotMaker($slug, $document, $by);

        $token = Str::random(40);

        $approval = ExternalApproval::query()->create([
            'document_slug' => $slug,
            'document_id' => $document->getKey(),
            'party' => $data['party'],
            'name' => $data['name'],
            'organization' => $data['organization'] ?? null,
            'email' => $data['email'] ?? null,
            'token_hash' => hash('sha256', $token),
            'expires_at' => isset($data['expires_at'])
                ? Carbon::parse($data['expires_at'])
                : now()->addDays(self::DEFAULT_VALIDITY_DAYS),
            'issued_by' => $by->id,
        ]);

        return [
            'approval' => $approval,
            'token' => $token,
            'url' => url('persetujuan/'.$token),
        ];
    }

    public function revoke(ExternalApproval $approval, User $by): ExternalApproval
    {
        return DB::transaction(function () use ($approval, $by): ExternalApproval {
            /** @var ExternalApproval $locked */
            $locked = ExternalApproval::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isDecided()) {
                throw ValidationException::withMessages(['revoke' => sprintf(
                    'Tautan ini sudah dipakai mencatat keputusan (%s, %s) — keputusan adalah bukti dan tidak dapat dicabut.',
                    $locked->decision?->label(),
                    $locked->decided_at?->format('d-m-Y H:i'),
                )]);
            }

            if ($locked->isRevoked()) {
                throw ValidationException::withMessages(['revoke' => sprintf(
                    'Tautan sudah dicabut pada %s.',
                    $locked->revoked_at?->format('d-m-Y H:i'),
                )]);
            }

            $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $by->id])->save();

            return $locked;
        });
    }

    // ------------------------------------------------------------- physical

    public function recordPhysical(User $by, array $data): ExternalApproval
    {
        $slug = (string) $data['document_type'];
        $document = $this->resolveDocument($slug, (int) $data['document_id']);
        $decision = ExternalDecision::from((string) $data['decision']);

        $this->assertAttachmentBelongsToDocument($slug, $document, (int) $data['attachment_id']);

        // Mode transisi: pencatat berdiri sebagai proksi yang menggerakkan
        // dokumen, maka maker-checker berlaku padanya seperti pada penerbit
        // tautan.
        $this->assertIssuerIsNotMaker($slug, $document, $by);

        return DB::transaction(function () use ($slug, $document, $decision, $by, $data): ExternalApproval {
            $approval = ExternalApproval::query()->create([
                'document_slug' => $slug,
                'document_id' => $document->getKey(),
                'party' => $data['party'],
                'name' => $data['name'],
                'organization' => $data['organization'] ?? null,
                'issued_by' => $by->id,
                'decision' => $decision,
                'decision_notes' => filled($data['decision_notes'] ?? null) ? $data['decision_notes'] : null,
                'decided_at' => isset($data['decided_at']) ? Carbon::parse($data['decided_at']) : now(),
                'decided_via' => ExternalApproval::VIA_PHYSICAL,
                'attachment_id' => (int) $data['attachment_id'],
            ]);

            $this->afterDecision($approval);

            return $approval;
        });
    }

    // ----------------------------------------------------------- public path

    public function findByToken(string $token): ?ExternalApproval
    {
        return ExternalApproval::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Jalan masuk halaman publik: token → keputusan, sekali saja.
     *
     * Status berbalik DI DALAM transaksi dengan baca ulang terkunci. Dua klik
     * pada tautan yang sama berarti dua transaksi; yang kalah membaca baris
     * yang sudah berkeputusan dan ditolak di sini — bukan oleh salinan usang
     * yang kebetulan dipegang controller-nya.
     */
    public function decide(string $token, string $decision, ?string $notes): ExternalApproval
    {
        $value = ExternalDecision::from($decision);

        return DB::transaction(function () use ($token, $value, $notes): ExternalApproval {
            /** @var ExternalApproval|null $row */
            $row = ExternalApproval::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw (new ModelNotFoundException)->setModel(ExternalApproval::class);
            }

            if ($row->isDecided()) {
                throw new LogicException('Tautan sudah digunakan — keputusan pertama yang berlaku dan tidak ditimpa.');
            }

            if ($row->isRevoked()) {
                throw new LogicException('Tautan sudah dicabut oleh penerbitnya.');
            }

            if ($row->isExpired()) {
                throw new LogicException(sprintf(
                    'Tautan sudah kedaluwarsa sejak %s.',
                    $row->expires_at?->format('d-m-Y H:i'),
                ));
            }

            // "Setuju dengan catatan" TANPA catatan bukan keputusan yang
            // utuh: kewajiban yang dibebankan stempel itu hidup di catatannya,
            // dan baris kosong berarti kewajiban yang tidak pernah tertulis.
            if ($value === ExternalDecision::ApprovedWithNotes && ! filled($notes)) {
                throw new LogicException(
                    'Keputusan "Setuju dengan catatan" harus menyertakan catatannya — '
                    .'tuliskan catatan Anda, atau pilih "Setuju".'
                );
            }

            $row->forceFill([
                'decision' => $value,
                'decision_notes' => filled($notes) ? $notes : null,
                'decided_at' => now(),
                'decided_via' => ExternalApproval::VIA_LINK,
            ])->save();

            // Hook/adapter di dalam transaksi yang sama: adapter transisi yang
            // menolak (maker-checker, status) menggulung keputusan ini ikut —
            // tidak ada "keputusan tercatat tetapi tidak diterapkan" yang
            // setengah benar.
            $this->afterDecision($row);

            return $row;
        });
    }

    // ------------------------------------------------------------ side effects

    /**
     * Sesudah SETIAP keputusan tercatat, dari pintu mana pun: hook registri
     * (record: kunci laporan harian; transisi: adapter yang menggerakkan
     * dokumen), lalu lonceng untuk pemegang {prefix}.approve. Notifikasi
     * dipagari NotificationService sendiri dan tidak pernah menggagalkan
     * keputusan yang dilaporkannya.
     */
    private function afterDecision(ExternalApproval $approval): void
    {
        $slug = $approval->document_slug;
        $class = ExternalApprovableDocuments::classFor($slug);

        /** @var Model|null $document */
        $document = $class === null ? null : $class::query()->find($approval->document_id);

        if ($document === null) {
            Log::warning("Keputusan eksternal #{$approval->id} tercatat untuk {$slug}/{$approval->document_id} yang sudah tidak ada.");

            return;
        }

        $hook = ExternalApprovableDocuments::hookFor($slug);

        if ($hook !== null) {
            [$serviceClass, $method] = $hook;

            ExternalApprovableDocuments::modeFor($slug) === ExternalApprovableDocuments::MODE_TRANSITION
                ? app($serviceClass)->{$method}($document, $approval)
                : app($serviceClass)->{$method}($document);
        }

        $prefix = ExternalApprovableDocuments::prefixFor($slug);
        $label = ExternalApprovableDocuments::labelFor($slug);
        $code = (string) ($document->code ?? $document->getKey());

        $this->notifications->system(
            "{$prefix}.approve",
            "Keputusan eksternal tercatat: {$label} {$code}",
            sprintf(
                '%s %s%s memutuskan: %s%s (via %s).',
                $approval->partyLabel(),
                $approval->name,
                filled($approval->organization) ? " ({$approval->organization})" : '',
                $approval->decision?->label(),
                filled($approval->decision_notes) ? " — {$approval->decision_notes}" : '',
                $approval->decided_via === ExternalApproval::VIA_PHYSICAL ? 'lembar fisik' : 'tautan',
            ),
            ExternalApprovableDocuments::linkFor($slug, (int) $approval->document_id),
            null,
            'ext:'.$approval->id,
        );
    }

    // ---------------------------------------------------------------- guards

    private function resolveDocument(string $slug, int $id): Model
    {
        $class = ExternalApprovableDocuments::classFor($slug);

        if ($class === null) {
            throw ValidationException::withMessages([
                'document_type' => "Jenis dokumen \"{$slug}\" tidak menerima persetujuan eksternal.",
            ]);
        }

        /** @var Model|null $document */
        $document = $class::query()->find($id);

        if ($document === null) {
            throw ValidationException::withMessages([
                'document_id' => ExternalApprovableDocuments::labelFor($slug).' tidak ditemukan.',
            ]);
        }

        return $document;
    }

    /**
     * Status yang mengizinkan PENERBITAN tautan — CCO hanya submitted
     * (keputusan pemilik #7), izin kerja hanya submitted (mode transisi:
     * tautan atas draf pasti gagal diterapkan). Lihat komentar registri.
     */
    private function assertIssuable(string $slug, Model $document): void
    {
        $statuses = ExternalApprovableDocuments::issuableStatusesFor($slug);

        if ($statuses === null) {
            return;
        }

        $current = $document->status instanceof \BackedEnum ? $document->status->value : (string) $document->status;

        if (! in_array($current, $statuses, true)) {
            throw ValidationException::withMessages(['document_id' => sprintf(
                'Tautan persetujuan %s hanya dapat diterbitkan saat dokumen berstatus %s — saat ini %s.',
                mb_strtolower(ExternalApprovableDocuments::labelFor($slug)),
                implode('/', $statuses),
                $current,
            )]);
        }
    }

    /**
     * Maker-checker pada mode transisi: keputusan dari tautan diterapkan atas
     * nama PENERBITNYA, maka pengaju dokumen yang menerbitkan tautan untuk
     * dokumennya sendiri sedang memegang kedua sisi asersi. Ditolak saat
     * terbit (gagal cepat, di meja yang benar); adapter memeriksa ulang saat
     * keputusan diterapkan, karena pengaju bisa berganti lewat
     * reject-resubmit di antara terbit dan klik. Tunduk pada saklar
     * approvals.segregation_of_duties yang sama dengan trait-nya.
     */
    private function assertIssuerIsNotMaker(string $slug, Model $document, User $by): void
    {
        if (ExternalApprovableDocuments::modeFor($slug) !== ExternalApprovableDocuments::MODE_TRANSITION) {
            return;
        }

        if (! SegregationOfDuties::isEnforced()) {
            return;
        }

        if (SegregationOfDuties::submitterIdOf($document) === (int) $by->getKey()) {
            throw ValidationException::withMessages(['document_id' => sprintf(
                'Maker-checker: pengaju %s ini tidak boleh menerbitkan tautan persetujuan eksternal untuk dokumennya sendiri — '
                    .'keputusan dari tautan diterapkan atas nama penerbitnya. Minta pemegang izin approve yang lain menerbitkannya.',
                mb_strtolower(ExternalApprovableDocuments::labelFor($slug)),
            )]);
        }
    }

    private function assertAttachmentBelongsToDocument(string $slug, Model $document, int $attachmentId): void
    {
        $attachment = Attachment::query()->find($attachmentId);

        if ($attachment === null) {
            throw ValidationException::withMessages(['attachment_id' => 'Lampiran lembar fisik tidak ditemukan.']);
        }

        if ($attachment->attachable_type !== $document::class
            || (int) $attachment->attachable_id !== (int) $document->getKey()) {
            throw ValidationException::withMessages(['attachment_id' => sprintf(
                'Lampiran "%s" terpasang pada dokumen lain — scan lembar fisik harus dilampirkan pada %s %s itu sendiri sebelum dicatat.',
                $attachment->original_name,
                mb_strtolower(ExternalApprovableDocuments::labelFor($slug)),
                (string) ($document->code ?? $document->getKey()),
            )]);
        }
    }
}
