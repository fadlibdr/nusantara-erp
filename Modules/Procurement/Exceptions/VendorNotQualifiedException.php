<?php

namespace Modules\Procurement\Exceptions;

use LogicException;
use Modules\Procurement\Models\Vendor;

/**
 * Refused because the vendor fails prequalification: nonaktif, or a document
 * flagged wajib has passed its masa berlaku.
 *
 * It extends LogicException on purpose, exactly like DirectorApprovalException:
 * the PO submit/create controllers already answer `catch (LogicException)` with
 * ApiController::error() — a 422 carrying the message verbatim — so the refusal
 * reaches the operator's screen in Indonesian without new controller plumbing.
 *
 * The message ends by naming the escape hatch (qualification_override_reason),
 * because a refusal that hides its override path teaches operators to fix the
 * data by flipping the vendor's mandatory flag off instead.
 */
class VendorNotQualifiedException extends LogicException
{
    /** @param  list<string>  $blockers */
    public static function make(Vendor $vendor, array $blockers): self
    {
        return new self(
            "Vendor {$vendor->code} ({$vendor->name}) belum lolos prakualifikasi: "
            .implode('; ', $blockers)
            .'. Sertakan alasan override (qualification_override_reason) bila tetap harus diajukan.'
        );
    }
}
