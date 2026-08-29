<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Exceptions\ApprovalLevelException;

/**
 * The n-level approval resolver (P2).
 *
 * A document type opts into multi-level approval by naming a ladder key
 * (config('erp.approvals.<key>.ladder')); Core\Traits\Approvable then asks this
 * class two things: how many DISTINCT approvers a given amount needs, and
 * whether the person offering the next approval is allowed to be one of them.
 *
 * WHY A LADDER AND NOT A SECOND threshold_two_level. PO and SPK have exactly two
 * tiers — below the threshold anyone may approve, at or above it a director must.
 * An award decision has three (an award of Rp 1,5 miliar is a board-level act),
 * and the owner wanted the tiers configurable per amount without minting a new
 * flag and a new permission for each. The ladder generalises the two-level idea
 * the app already shipped: PO/SPK ARE the degenerate ladder [<threshold → 1,
 * ≥threshold → 2], and this class would resolve them identically — they keep
 * their original mechanism only because rewriting a green, audited gate buys
 * nothing (see config/erp.php approvals).
 *
 * BRACKET SEMANTICS. `to` is the EXCLUSIVE upper bound: an amount AT a boundary
 * falls into the next bracket, so exactly Rp 100 juta needs the higher level —
 * the same >= reading needs_director_approval used ((float) total >= threshold).
 * Brackets are sorted ascending here so a misordered config cannot shadow one.
 */
class ApprovalLevels
{
    /**
     * How many distinct approvers a document of this amount needs.
     *
     * A key with no ladder (or an empty one) resolves to 1 — the ordinary
     * single-approval lifecycle, which is what every document that does NOT opt
     * in keeps.
     */
    public static function forAmount(string $ladderKey, float $amount): int
    {
        $ladder = self::ladder($ladderKey);

        if ($ladder === []) {
            return 1;
        }

        foreach ($ladder as $bracket) {
            $to = $bracket['to'];

            if ($to === null || $amount < (float) $to) {
                return max(1, (int) $bracket['levels']);
            }
        }

        // No catch-all bracket (config without a null `to`): the highest
        // declared level applies rather than silently dropping to one.
        return max(1, (int) end($ladder)['levels']);
    }

    /**
     * Refuse the next approval when THIS approver may not supply it.
     *
     * Called after maker-checker (Approvable::approve runs SegregationOfDuties
     * first), so the submitter is already gone. Two further rules:
     *
     *   REPEAT APPROVER — a person who already approved this document cannot be
     *   counted twice; the ladder wants DISTINCT approvers.
     *
     *   LEVEL 2+ IS A DIRECTOR — the slot this approval fills is (distinct
     *   approvals so far + 1). From the second level up the approver must hold
     *   the module's <prefix>.approve-director permission, derived from the same
     *   ApprovableDocuments row that names the ordinary approver, so the two can
     *   never disagree about the document's prefix.
     *
     * @throws ApprovalLevelException
     */
    public static function assertMayApproveNext(Model $document, User $approver, int $priorDistinctApprovals): void
    {
        $label = ApprovableDocuments::label($document);
        $code = (string) ($document->code ?? $document->getKey());

        if (self::hasAlreadyApproved($document, $approver)) {
            throw new ApprovalLevelException(sprintf(
                '%s %s sudah Anda setujui pada tingkat sebelumnya; persetujuan berjenjang menuntut penyetuju yang '
                .'BERBEDA di tiap tingkat. Minta tingkat berikutnya kepada pengguna lain.',
                $label,
                $code,
            ));
        }

        $level = $priorDistinctApprovals + 1;

        if ($level < 2) {
            return; // level 1: any holder of the ordinary approve permission
        }

        $directorPermission = self::directorPermission($document);

        if ($directorPermission !== null && $approver->can($directorPermission)) {
            return;
        }

        throw new ApprovalLevelException(sprintf(
            'Persetujuan tingkat %d atas %s %s hanya dapat diberikan oleh pemegang izin %s (persetujuan direktur). '
            .'Minta persetujuan direktur untuk melengkapi jenjangnya.',
            $level,
            $label,
            $code,
            $directorPermission ?? 'persetujuan direktur',
        ));
    }

    /** Distinct users who have recorded an 'approved' row on this document. */
    public static function distinctApprovals(Model $document): int
    {
        return (int) $document->approvals()
            ->where('action', 'approved')
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');
    }

    private static function hasAlreadyApproved(Model $document, User $approver): bool
    {
        return $document->approvals()
            ->where('action', 'approved')
            ->where('user_id', $approver->getKey())
            ->exists();
    }

    /** "prc.approve" -> "prc.approve-director", from the shared registry. */
    private static function directorPermission(Model $document): ?string
    {
        $base = ApprovableDocuments::approvePermission($document);

        return $base === null ? null : "{$base}-director";
    }

    /**
     * The ladder for this key, sorted by bound ascending (null last).
     *
     * Read straight off config: the ladder is an install-time policy, not a
     * per-request setting, and keeping it out of SettingService keeps the same
     * "an operator who can weaken a control from a web form will" reasoning the
     * closing reminders and backup watch use.
     *
     * @return list<array{to: int|float|null, levels: int}>
     */
    private static function ladder(string $ladderKey): array
    {
        $raw = config("erp.approvals.{$ladderKey}.ladder");

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $ladder = [];

        foreach ($raw as $bracket) {
            if (! is_array($bracket) || ! array_key_exists('to', $bracket) || ! array_key_exists('levels', $bracket)) {
                continue;
            }

            $ladder[] = ['to' => $bracket['to'], 'levels' => (int) $bracket['levels']];
        }

        usort($ladder, static function (array $a, array $b): int {
            $aTo = $a['to'] === null ? INF : (float) $a['to'];
            $bTo = $b['to'] === null ? INF : (float) $b['to'];

            return $aTo <=> $bTo;
        });

        return $ladder;
    }
}
