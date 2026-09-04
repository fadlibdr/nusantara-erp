<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * Two reasons. The first is that an authorship column is the exception, not
 * the rule: of the 28 tables in ApprovableDocuments only
 * prc_purchase_requisitions and prj_work_permits (requested_by) and
 * prj_baselines (created_by) carry one (counted 4 Sep 2026), so keying the
 * guard off authorship would mean twenty-five new columns and a backfill with
 * nothing to backfill from. The second is the principled one — maker-checker
 * guards the ACT OF ASSERTION, not typing. A clerk who keys a bill somebody
 * else submits has asserted nothing; the person who clicked Ajukan has. Taking
 * the LATEST submission also gets reject-then-resubmit right: if Alice
 * submits, Bob rejects and Bob resubmits, Bob is the maker now and Bob is the
 * one refused.
 *
 * A SUBMISSION WITH NO RECORDED ACTOR PASSES. submit(?User $by = null) is a
 * documented state (see ApprovableTest) and RetentionService depends on it: the
 * retention-release bill is minted by the engine out of one human act whose
 * route demands scm.post AND fin.approve, so it submits as nobody and the human
 * who released the retention approves it — a human who provably holds the AP
 * approval right. There is no maker to protect against, so the guard stays
 * quiet. AdvanceService mints the uang-muka bill the same way.
 *
 * A DOCUMENT WITH NO SUBMISSION AT ALL FALLS BACK TO ITS OWNER COLUMN. Measured
 * on production 4 Sep 2026 (HASIL-UJI §6 P-3, ANALISIS-PROSES §3 C3):
 * PR/2026/III/0002 had been seeded straight to `submitted` with no
 * core_approvals row, its detail read "Diminta oleh admin", and admin approved
 * it from the dashboard card in one click — `approvals: []` before and after.
 * The guard was quiet because it saw no row, and "no row" is not the state the
 * paragraph above defends: a row whose actor is nobody is something the engine
 * asserted on purpose; no row is a seed, an import or a hand edit that
 * asserted nothing and left the document's own requester as the only person
 * who ever vouched for it. So when NOTHING was recorded, requested_by /
 * created_by / submitted_by — whichever the table has — names the maker and
 * is refused exactly as a submitter would be. The seeders and
 * DocumentImportService now write the row they used to skip; this fallback is
 * the net under them, not the path. A table with no such column (fin_ap_bills,
 * where the retention-release bill lives) is untouched by it.
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
     * Owner columns consulted when — and only when — no `submitted` row was
     * ever recorded for the document, in this order. Name-only and checked
     * with Schema::hasColumn per table, because Core cannot import the model
     * to ask; a table that grows one of these columns is covered without an
     * edit here. Nothing carries submitted_by yet (4 Sep 2026) — it is listed
     * because it is the name a future migration would choose.
     */
    private const OWNER_COLUMNS = ['requested_by', 'created_by', 'submitted_by'];

    /**
     * The one owner column that is an EMPLOYEE number, not a login:
     * prj_work_permits.requested_by → hr_employees (its migration says so —
     * "pemohon adalah pegawai"). Resolved through users.employee_id instead of
     * compared with users.id, otherwise the permit of mandor EMP #4 would be
     * refused to whichever login happens to be user #4 and waved through for
     * the mandor's own login.
     */
    private const EMPLOYEE_OWNER_COLUMNS = ['prj_work_permits' => 'requested_by'];

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
     * Who the guard holds to be the maker: the recorded submitter, or — only
     * when no submission was ever recorded — the owner column. Null when there
     * is nobody to protect against: a submission recorded as nobody, or no
     * owner column on the table.
     */
    public static function makerIdOf(Model $document): ?int
    {
        $submitterId = self::submitterIdOf($document);

        if ($submitterId !== null || self::hasRecordedSubmission($document)) {
            return $submitterId;
        }

        return self::ownerIdOf($document);
    }

    /**
     * The user id the document's own owner column points at, or null when the
     * table has none or the column is empty. Read from the table rather than
     * the instance, so a caller holding a partial or stale model gets the
     * stored fact.
     */
    public static function ownerIdOf(Model $document): ?int
    {
        $table = $document->getTable();

        foreach (self::OWNER_COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $value = DB::table($table)
                ->where($document->getKeyName(), $document->getKey())
                ->value($column);

            if ($value === null) {
                continue;
            }

            if ((self::EMPLOYEE_OWNER_COLUMNS[$table] ?? null) === $column) {
                // An employee without a login cannot be the approver either,
                // so null here is "nobody to protect against", not a hole.
                $userId = DB::table('users')->where('employee_id', (int) $value)->orderBy('id')->value('id');

                return $userId === null ? null : (int) $userId;
            }

            return (int) $value;
        }

        return null;
    }

    /** Any `submitted` row at all — actor or not. */
    private static function hasRecordedSubmission(Model $document): bool
    {
        return DB::table('core_approvals')
            ->where('approvable_type', $document::class)
            ->where('approvable_id', $document->getKey())
            ->where('action', 'submitted')
            ->exists();
    }

    /**
     * Refuse an approval by the document's own maker — its recorded
     * submitter, or, for a document nothing ever recorded a submission for,
     * its owner column.
     *
     * @throws SelfApprovalException
     */
    public static function assertNotSubmitter(Model $document, User $approver): void
    {
        if (! self::isEnforced()) {
            return;
        }

        $makerId = self::makerIdOf($document);

        if ($makerId === null || $makerId !== (int) $approver->getKey()) {
            return;
        }

        throw new SelfApprovalException(self::refusal($document, $makerId));
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
