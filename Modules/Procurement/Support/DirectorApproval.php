<?php

namespace Modules\Procurement\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Core\Support\Money;
use Modules\Procurement\Exceptions\DirectorApprovalException;

/**
 * Enforcement for needs_director_approval — the check that ran nowhere.
 *
 * The flag is stamped on submit (PurchaseOrder / Subcontract against
 * approvals.*.threshold_two_level), returned by the API, labelled "Perlu
 * persetujuan direktur" on the detail screen and announced to the submitter,
 * and until this class no code path read it at approval time. The live proof:
 * SPK/2026/II/0001 — Rp 6.500.000.000, 32,5× the Rp 200 juta threshold — was
 * submitted AND approved by one non-director login while the screen displayed
 * that a director was required. PO/2026/II/0001 (Rp 232.545.000) and
 * PO/2026/III/0002 (Rp 128.316.000) are in the same state.
 *
 * Director-level is a PERMISSION (<prefix>.approve-director, held by the
 * direktur and admin roles), not a role check: routes gate on permissions
 * everywhere in this app, and an installation that renames or splits its roles
 * must be able to hand the right to whoever its director actually is.
 *
 * One shared guard called from PoService and SubcontractService rather than a
 * check in each controller — the two message formats would drift, and the next
 * document type that gets a threshold would copy whichever one was wrong.
 *
 * FORWARD-ONLY BY CONSTRUCTION. It only examines documents still awaiting
 * approval, so the three documents approved past the flag before it was
 * enforced stay approved — their core_approvals rows are the honest record of
 * what happened, not a state to rewrite. And it COMPOSES with maker-checker:
 * Approvable::approve still refuses the submitter, so a director who submitted
 * a Rp 6,5 miliar SPK cannot also be the director who approves it.
 */
final class DirectorApproval
{
    /**
     * Refuse a non-director approving a document stamped as needing one.
     *
     * $value and $threshold are only for the refusal text; the decision rides
     * on the PERSISTED flag, so the rule the approver is held to is the one the
     * submitter was shown, even if the threshold setting changed in between.
     *
     * @throws DirectorApprovalException
     */
    public static function assertMayApprove(Model $document, User $approver, float $value, float $threshold): void
    {
        // Not submitted -> let Approvable::assertStatus refuse it with the
        // canonical "while status is draft" message; a stale flag on a draft
        // must not shadow the more fundamental error.
        if ($document->status !== DocumentStatus::Submitted) {
            return;
        }

        if (! (bool) $document->needs_director_approval) {
            return;
        }

        if ($approver->can(self::permission($document))) {
            return;
        }

        throw new DirectorApprovalException(sprintf(
            '%s %s senilai %s mencapai ambang persetujuan direktur %s; dokumen ini hanya dapat disetujui oleh '
            .'pemegang izin %s — pada instalasi standar peran direktur. Minta persetujuan direktur, atau ubah '
            .'ambangnya di Pengaturan → Proyek & Persetujuan bila kebijakan perusahaan memang berbeda.',
            ApprovableDocuments::label($document),
            (string) ($document->code ?? $document->getKey()),
            Money::format($value, false),
            Money::format($threshold, false),
            self::permission($document),
        ));
    }

    /**
     * "prc.approve" -> "prc.approve-director": derived from the same table
     * (ApprovableDocuments) that names the ordinary approver, so the pair can
     * never disagree about a document's prefix.
     */
    private static function permission(Model $document): string
    {
        $base = ApprovableDocuments::approvePermission($document);

        if ($base === null) {
            // Only documents that stamp needs_director_approval reach here,
            // and both are in the table — an unknown class is a wiring bug, and
            // guessing a permission name would refuse everyone or no one.
            throw new LogicException(sprintf(
                'DirectorApproval does not know %s; add it to ApprovableDocuments first.',
                $document::class,
            ));
        }

        return "{$base}-director";
    }
}
