<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Core\Exceptions\SelfApprovalException;

/**
 * Maker-checker: the person who submitted a document may not be the person who
 * approves it.
 *
 * The failure this closes is one person, one login, one afternoon. On the demo
 * dataset a `finance` user could raise BIL/2026/III/0001 for Rp 232.545.000 to
 * a vendor of their choosing, submit it, approve it, and post the payment —
 * four clicks, no second pair of eyes anywhere on the path, and the resulting
 * core_approvals trail reads as a properly approved bill because both rows
 * carry a real user id.
 *
 * "WHO SUBMITTED IT" IS THE LATEST `submitted` ROW, NOT A created_by COLUMN.
 * Two reasons. The first is that created_by does not exist: none of the
 * thirteen models using Approvable carries one (only prj_daily_reports,
 * prj_safety_incidents and fin_revenue_recognition_runs do), so keying off
 * authorship would mean thirteen columns and a backfill with nothing to
 * backfill from. The second is the principled one — maker-checker guards the
 * ACT OF ASSERTION, not typing. A clerk who keys a bill somebody else submits
 * has asserted nothing; the person who clicked Ajukan has. Taking the LATEST
 * submission also gets reject-then-resubmit right: if Alice submits, Bob
 * rejects and Bob resubmits, Bob is the maker now and Bob is the one refused.
 *
 * A SUBMISSION WITH NO RECORDED ACTOR PASSES. submit(?User $by = null) is a
 * documented state (see ApprovableTest) and RetentionService depends on it: the
 * retention-release bill is minted by the engine out of one human act whose
 * route demands scm.post AND fin.approve, so it submits as nobody and the human
 * who released the retention approves it — a human who provably holds the AP
 * approval right. There is no maker to protect against, so the guard stays
 * quiet. On the demo dataset one further document is in that state — the
 * est_cost_budgets row, seeded straight to `submitted`.
 */
class SegregationOfDuties
{
    /**
     * Read through Erp:: below with this key spelled out literally, because
     * SettingServiceTest scans the source for literal Erp:: reads and fails a
     * registry entry that nothing is seen to read.
     */
    public const SETTING_KEY = 'approvals.segregation_of_duties';

    /**
     * Enforced by default. A company that genuinely has fewer people than it
     * has duties turns it off on Pengaturan → Proyek & Persetujuan; the
     * evidence survives either way, because core_approvals keeps both the
     * submitted and the approved row whatever the setting says.
     */
    public static function isEnforced(): bool
    {
        return Erp::bool('approvals.segregation_of_duties', true);
    }

    /**
     * The user id on the newest `submitted` row for this document, or null when
     * nobody is recorded as having submitted it.
     *
     * Deliberately NOT filtered by users.is_active: an employee who has since
     * left still submitted the document, and a resigned maker must not become a
     * hole in the guard. NotificationService applies that filter on top of this
     * for its own purposes — it must not write to a deactivated inbox — which is
     * why the filter lives there and not here.
     */
    public static function submitterIdOf(Model $document): ?int
    {
        $userId = DB::table('core_approvals')
            ->where('approvable_type', $document::class)
            ->where('approvable_id', $document->getKey())
            ->where('action', 'submitted')
            ->whereNotNull('user_id')
            ->orderByDesc('id')
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    /**
     * Refuse an approval by the document's own submitter.
     *
     * @throws SelfApprovalException
     */
    public static function assertNotSubmitter(Model $document, User $approver): void
    {
        if (! self::isEnforced()) {
            return;
        }

        $submitterId = self::submitterIdOf($document);

        if ($submitterId === null || $submitterId !== (int) $approver->getKey()) {
            return;
        }

        throw new SelfApprovalException(self::refusal($document, $submitterId));
    }

    /**
     * The refusal an operator reads. It names the submitter because the next
     * thing that operator has to do is walk to that person's desk, and it names
     * the way out because on a five-person contractor there may not be a desk
     * to walk to.
     */
    private static function refusal(Model $document, int $submitterId): string
    {
        $label = ApprovableDocuments::label($document);
        $code = (string) ($document->code ?? $document->getKey());
        $permission = ApprovableDocuments::approvePermission($document);

        $submitter = User::query()->find($submitterId)?->name ?? "pengguna #{$submitterId}";

        $who = $permission === null
            ? 'pengguna lain yang berwenang menyetujui'
            : "pengguna lain pemegang izin {$permission}";

        return "{$label} {$code} diajukan oleh {$submitter}; dokumen tidak boleh disetujui oleh pengajunya sendiri. "
            ."Minta persetujuan {$who}, atau matikan \"Wajib pemisahan tugas\" di Pengaturan → Proyek & Persetujuan "
            .'bila perusahaan Anda memang tidak memiliki petugas kedua.';
    }
}
