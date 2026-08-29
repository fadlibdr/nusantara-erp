<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Subcontract\Enums\HandoverType;
use Modules\Subcontract\Models\Handover;
use Modules\Subcontract\Models\Subcontract;

/**
 * BAST SUBKON I/II — and the two prerequisites that make it mean something.
 *
 * The gates run at APPROVAL, not at submit, following prj_bast: approving is
 * the act that starts (BAST I) or ends (BAST II) the masa pemeliharaan, and a
 * draft must still be refused with the trait's own "while status is draft"
 * message, which is the more fundamental error.
 *
 * BAST I:
 *   1. the last opname is APPROVED — no non-advance klaim of this SPK may still
 *      be sitting in draft or submitted, and at least one must have been
 *      accepted. A handover certifies that the work is done; work whose
 *      measurement nobody has accepted is work still in dispute, and signing
 *      over it hands the subcontractor the retention clock while the volume is
 *      still an open question. A REJECTED claim does not block: it is dead
 *      paper, not a pending question.
 *   2. the retention has NOT been released. Retention is the jaminan cacat
 *      mutu for the period BAST I begins; if it is already gone, either the
 *      period is over (in which case this is a BAST II) or the money left
 *      early — and back-dating a BAST I over that would paper the second case
 *      over as the first.
 *
 * BAST II adds the ordering rule prj_bast has and drops rule 2 (a release is
 * exactly what BAST II is expected to be followed by): there must be an
 * approved BAST I, and this handover cannot predate it.
 *
 * ALL BLOCKS, NO WARNINGS. BastPrerequisiteService's line — "can the business
 * always satisfy it" — is satisfied here by every one of them: approve the
 * opname, issue the BAST I first, or correct a date. None is a stale number
 * nobody can fix.
 */
class HandoverService
{
    public function create(array $data): Handover
    {
        return DB::transaction(function () use ($data): Handover {
            /** @var Subcontract $subcontract */
            $subcontract = Subcontract::query()
                ->whereKey($data['subcontract_id'])->lockForUpdate()->firstOrFail();

            if ($subcontract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "SPK {$subcontract->code} berstatus {$subcontract->status->value}; "
                    .'berita acara serah terima hanya atas SPK yang sudah disetujui.'
                );
            }

            $type = $this->typeOf($data['handover_type']);

            $this->assertNoLiveHandover($subcontract, $type, null);

            $handover = new Handover([
                'subcontract_id' => $subcontract->id,
                'handover_date' => $data['handover_date'],
                'retention_release_due' => $data['retention_release_due']
                    ?? $this->defaultReleaseDue($subcontract, $type),
                'scope_notes' => $data['scope_notes'] ?? null,
                'handed_over_by' => $data['handed_over_by'] ?? null,
                'received_by' => $data['received_by'] ?? null,
            ]);
            $handover->handover_type = $type;
            $handover->status = DocumentStatus::Draft;
            $handover->save(); // HasDocumentNumber fills the BSK code

            return $handover->load('subcontract');
        });
    }

    public function update(Handover $handover, array $data): Handover
    {
        return DB::transaction(function () use ($handover, $data): Handover {
            /** @var Handover $handover */
            $handover = Handover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($handover);

            if (array_key_exists('handover_type', $data)) {
                $type = $this->typeOf($data['handover_type']);
                $this->assertNoLiveHandover($handover->subcontract()->firstOrFail(), $type, (int) $handover->id);
                $handover->handover_type = $type;
            }

            $handover->fill(Arr::only($data, [
                'handover_date', 'retention_release_due', 'scope_notes', 'handed_over_by', 'received_by',
            ]))->save();

            return $handover->refresh()->load('subcontract');
        });
    }

    public function delete(Handover $handover): void
    {
        DB::transaction(function () use ($handover): void {
            /** @var Handover $handover */
            $handover = Handover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($handover);
            $handover->delete();
        });
    }

    public function approve(Handover $handover, User $by, ?string $note = null): Handover
    {
        return DB::transaction(function () use ($handover, $by, $note): Handover {
            /** @var Handover $handover */
            $handover = Handover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            // The trait's status guard runs FIRST, deliberately — a draft is
            // refused with "while status is draft" rather than with a
            // prerequisite message about paperwork it does not yet need.
            if ($handover->status !== DocumentStatus::Submitted) {
                return $handover->approve($by, $note); // throws the trait's own message
            }

            $this->assertPrerequisites($handover);

            $handover->approve($by, $note);

            return $handover->refresh()->load('subcontract');
        });
    }

    public function reject(Handover $handover, User $by, ?string $note = null): Handover
    {
        return DB::transaction(fn (): Handover => $handover->reject($by, $note));
    }

    /**
     * The live checklist, for the screen to read BEFORE anybody clicks —
     * prj_bast's `prerequisites` endpoint, in its shape.
     *
     * @return array{handover_id: int, handover_code: string, handover_type: ?string,
     *               can_approve: bool, checks: list<array<string, mixed>>}
     */
    public function evaluate(Handover $handover): array
    {
        $checks = $this->checks($handover);

        return [
            'handover_id' => (int) $handover->id,
            'handover_code' => (string) $handover->code,
            'handover_type' => $handover->handover_type?->value,
            'can_approve' => array_filter($checks, fn (array $check): bool => ! $check['passed']) === [],
            'checks' => $checks,
        ];
    }

    // ------------------------------------------------------------------ rules

    private function assertPrerequisites(Handover $handover): void
    {
        $failed = array_values(array_filter($this->checks($handover), fn (array $check): bool => ! $check['passed']));

        if ($failed === []) {
            return;
        }

        throw ValidationException::withMessages(['status' => sprintf(
            '%s %s belum dapat disetujui — %s.',
            $handover->handover_type?->label() ?? 'BAST subkon',
            $handover->code,
            implode('; ', array_column($failed, 'detail')),
        )]);
    }

    /** @return list<array{key: string, passed: bool, label: string, detail: string}> */
    private function checks(Handover $handover): array
    {
        /** @var Subcontract $subcontract */
        $subcontract = $handover->subcontract()->firstOrFail();

        $checks = [$this->lastClaimApproved($subcontract)];

        if ($handover->isFirst()) {
            $checks[] = $this->retentionNotReleased($subcontract);

            return $checks;
        }

        $checks[] = $this->firstHandoverApproved($handover, $subcontract);

        return $checks;
    }

    /** @return array{key: string, passed: bool, label: string, detail: string} */
    private function lastClaimApproved(Subcontract $subcontract): array
    {
        $pending = $subcontract->claims()
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Submitted->value])
            ->orderBy('claim_no')
            ->pluck('code')
            ->all();

        if ($pending !== []) {
            return $this->check(
                'opname_terakhir_disetujui',
                false,
                'Opname terakhir sudah disetujui',
                sprintf(
                    'opname %s pada SPK %s belum disetujui, sehingga volume yang diserahterimakan masih terbuka',
                    implode(', ', $pending),
                    $subcontract->code,
                ),
            );
        }

        $approved = $subcontract->claims()
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->count();

        return $this->check(
            'opname_terakhir_disetujui',
            $approved > 0,
            'Opname terakhir sudah disetujui',
            $approved > 0
                ? "{$approved} opname SPK {$subcontract->code} sudah disetujui"
                : "SPK {$subcontract->code} belum memiliki opname yang disetujui, sehingga tidak ada pekerjaan terukur untuk diserahterimakan",
        );
    }

    /**
     * Retention already released — read the way RetentionService reads it, so
     * a release whose bill was cancelled does not count: that cancellation put
     * the money back on the balance sheet, and the leverage with it.
     *
     * @return array{key: string, passed: bool, label: string, detail: string}
     */
    private function retentionNotReleased(Subcontract $subcontract): array
    {
        $cancelled = ApBill::query()
            ->where('status', DocumentStatus::Cancelled->value)
            ->whereIn('id', $subcontract->retentionReleases()->whereNotNull('ap_bill_id')->pluck('ap_bill_id'))
            ->pluck('id')
            ->all();

        $released = $subcontract->retentionReleases()
            ->when($cancelled !== [], fn ($query) => $query->where(
                fn ($where) => $where->whereNull('ap_bill_id')->orWhereNotIn('ap_bill_id', $cancelled)
            ))
            ->orderBy('release_date')
            ->first();

        return $this->check(
            'retensi_belum_dilepas',
            $released === null,
            'Retensi belum dilepas',
            $released === null
                ? "Retensi SPK {$subcontract->code} masih ditahan"
                : sprintf(
                    'retensi SPK %s sudah dilepas sebesar %s pada %s, sehingga masa pemeliharaan yang dimulai BAST I sudah tidak dijamin retensi',
                    $subcontract->code,
                    number_format((float) $released->amount, 2, ',', '.'),
                    Carbon::parse($released->release_date)->format('d-m-Y'),
                ),
        );
    }

    /** @return array{key: string, passed: bool, label: string, detail: string} */
    private function firstHandoverApproved(Handover $handover, Subcontract $subcontract): array
    {
        /** @var ?Handover $first */
        $first = Handover::query()
            ->where('subcontract_id', $subcontract->id)
            ->where('handover_type', HandoverType::Bast1->value)
            ->where('status', DocumentStatus::Approved->value)
            ->first();

        if ($first === null) {
            return $this->check(
                'bast1_disetujui',
                false,
                'BAST I sudah disetujui',
                "SPK {$subcontract->code} belum memiliki BAST I yang disetujui, padahal BAST II mengakhiri masa pemeliharaan yang dimulai BAST I",
            );
        }

        $ordered = $handover->handover_date === null
            || $first->handover_date === null
            || $handover->handover_date->gte($first->handover_date);

        return $this->check(
            'bast1_disetujui',
            $ordered,
            'BAST I sudah disetujui',
            $ordered
                ? "BAST I {$first->code} disetujui pada {$first->handover_date?->format('d-m-Y')}"
                : sprintf(
                    'tanggal BAST II (%s) mendahului BAST I %s (%s)',
                    $handover->handover_date->format('d-m-Y'),
                    $first->code,
                    $first->handover_date->format('d-m-Y'),
                ),
        );
    }

    /** @return array{key: string, passed: bool, label: string, detail: string} */
    private function check(string $key, bool $passed, string $label, string $detail): array
    {
        return ['key' => $key, 'passed' => $passed, 'label' => $label, 'detail' => $detail];
    }

    private function assertNoLiveHandover(Subcontract $subcontract, HandoverType $type, ?int $excludeId): void
    {
        $existing = Handover::query()
            ->where('subcontract_id', $subcontract->id)
            ->where('handover_type', $type->value)
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->value('code');

        if ($existing !== null) {
            throw new LogicException(sprintf(
                'SPK %s sudah memiliki %s (%s).',
                $subcontract->code,
                $type->label(),
                $existing,
            ));
        }
    }

    /**
     * BAST I publishes when the retention becomes releasable. The date is
     * COPIED from the SPK's own defect_liability_until, never computed: an SPK
     * carries no maintenance-period length, and a BAST that guessed one would
     * put a date on a signed sheet that nothing in the database supports
     * (PANDUAN §13.5). An SPK without the date leaves the cell blank, which is
     * exactly the state RetentionService already refuses to release against.
     */
    private function defaultReleaseDue(Subcontract $subcontract, HandoverType $type): ?string
    {
        return $type->isFirst()
            ? $subcontract->defect_liability_until?->toDateString()
            : null;
    }

    private function typeOf(mixed $type): HandoverType
    {
        if ($type instanceof HandoverType) {
            return $type;
        }

        $resolved = HandoverType::tryFrom((string) $type);

        if ($resolved === null) {
            throw new LogicException("Jenis serah terima \"{$type}\" tidak dikenal.");
        }

        return $resolved;
    }

    private function assertEditable(Handover $handover): void
    {
        if (! $handover->status->isEditable()) {
            throw new LogicException(
                "Berita acara {$handover->code} berstatus {$handover->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
