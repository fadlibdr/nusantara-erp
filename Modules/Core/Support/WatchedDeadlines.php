<?php

namespace Modules\Core\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;

/**
 * Every date in the system that can slide past in silence, listed once.
 *
 * The demo dataset shows what silence costs: PO/2026/II/0001 (Rp 232,5 jt) was
 * promised for 1 Mar 2026 and sat 153 days past that date with zero receipts
 * and zero reminders, and both kontrak employees (EMP-0007 Joko Susilo,
 * EMP-0008 Made Wirawan) had no PKWT end date recorded anywhere — a PKWT worked
 * past its end date becomes PKWTT demi hukum (PP 35/2021), a conversion nobody
 * chose. Each entry here is one such date and, crucially, its "still somebody's
 * problem" scope: a won quotation, a closed PO, a returned deployment, a
 * released guarantee all drop out, so an alarm always has an action left.
 *
 * In the taste of AuditedModels and Finance's DanglingDocuments: one
 * declarative list, so the next date worth watching is added as one array
 * entry — never a new command, never a second loop.
 *
 * TWO RULES THE ENTRIES LIVE BY.
 *
 *  - DB::table, string literals, no feature-module imports. Core is the module
 *    everything else depends on; importing Modules\Crm\Enums\GuaranteeStatus
 *    here would invert that direction (GlobalSearchService solves the same
 *    problem the same way). The status strings below ('active', 'kontrak',
 *    'open', …) are pinned by DeadlineWatchTest, so a rename in another team's
 *    lane fails a test instead of silently emptying a scope.
 *
 *  - HALF-OPEN date ranges, never BETWEEN, never equality. Every date column
 *    here is cast to `date` on its model, so SQLite stores the string
 *    "2026-06-30 00:00:00" — which sorts AFTER "2026-06-30". The comparisons in
 *    scan() are built so both storage forms land in the right tier; this is the
 *    exact footgun DanglingDocuments documents, the one that missed every
 *    document dated on the last day of a month.
 *
 * Two tiers per entry: MENIPIS (date in (today, today+lead_days]) and LEWAT
 * (date <= today). lead_days = 0 means overdue-only — a future expected date on
 * a PO or an AR invoice is normal, only lateness alarms. Two bespoke flags in
 * the DanglingDocuments tradition: 'alarm_when_date_missing' (an active kontrak
 * employee with NO recorded end date is itself the alarm) and
 * 'latest_per_group' (only the newest maintenance row per asset carries a live
 * next_due_date — a newer service record supersedes the old reminder).
 *
 * scan() degrades per entry: a table or column that does not exist yet is a
 * 'skipped' line in the CLI, never a crash and never a notification — two other
 * teams are mid-migration in this repository and that is normal, not an alarm.
 */
class WatchedDeadlines
{
    public const MENIPIS = 'menipis';

    public const LEWAT = 'lewat';

    public const TANPA_TANGGAL = 'tanpa_tanggal';

    /** Rows carried per finding — enough for the tenggat screen, bounded. */
    private const MAX_ITEMS = 50;

    /** Codes named in a notification body before it collapses into a count. */
    private const BODY_ITEMS = 5;

    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * One entry per watched date. Keys:
     *
     *  key / table / date  — identity and the column that can slide past.
     *  display             — the column a human recognises the row by.
     *  value               — optional rupiah column quoted in the body.
     *  lead_days           — MENIPIS window; 0 = overdue-only.
     *  permission          — who can ACT (not merely see) — the notification target.
     *  link                — SPA route the notification and tenggat screen open.
     *  unit / date_word    — body grammar ("Total 2 PO.", "dijanjikan 1 Mar 2026").
     *  titles              — STABLE per entry+tier; counts live in the body, never
     *                        the title, so the (event, title, unread) dedupe holds
     *                        and a tier change = a new title = fires immediately.
     *  scope               — the "still somebody's problem" WHERE.
     *  columns             — every column the scope closure filters or joins on:
     *                        bare name for the entry's own table, table.column
     *                        for a 'requires' table. missingSchema() checks each
     *                        one, so a scope column mid-rename in another team's
     *                        lane is a SKIP line, never a QueryException that
     *                        kills the whole 08:30 run.
     *  requires            — extra tables a scope's EXISTS touches, checked before
     *                        querying so a half-migrated sibling module skips
     *                        instead of crashing.
     *  valid_through_end   — the date is a "berlaku s/d": the row is still valid
     *                        ON its end day, so that day reports as MENIPIS
     *                        ("hari ini") and LEWAT starts the day after. Needs
     *                        lead_days > 0, or the end day would land in no tier.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function entries(): array
    {
        return [
            [
                // QTN/2026/VII/0004 (Rp 33,97 jt) is approved, not won, not
                // lost, valid s/d 2026-08-31 — sales gets 14 days to close or
                // re-issue it instead of finding out from the customer.
                'key' => 'quotation_valid_until',
                'table' => 'crm_quotations',
                'date' => 'valid_until',
                'display' => 'code',
                'value' => 'total',
                'label' => 'Penawaran',
                'unit' => 'penawaran',
                'date_word' => 'berlaku s/d',
                'lead_days' => 14,
                'permission' => 'crm.update',
                'link' => 'r/crm/quotations',
                'title_upcoming' => 'Penawaran mendekati akhir masa berlaku',
                'title_overdue' => 'Penawaran lewat masa berlaku',
                'columns' => ['deleted_at', 'won_at', 'lost_at', 'status'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('deleted_at')
                    ->whereNull('won_at')
                    ->whereNull('lost_at')
                    ->where('status', '!=', DocumentStatus::Rejected->value),
            ],
            [
                // CTR/2026/II/0002 (Rp 9,8 M) ends 2026-12-18: perpanjangan,
                // EOT or BAST has to move before that date, and wanprestasi is
                // a management event — hence crm.approve, the direktur.
                'key' => 'contract_end',
                'table' => 'crm_contracts',
                'date' => 'end_date',
                'display' => 'code',
                'value' => 'value',
                'label' => 'Kontrak',
                'unit' => 'kontrak',
                'date_word' => 'berakhir',
                'lead_days' => 30,
                'permission' => 'crm.approve',
                'link' => 'r/crm/contracts',
                'title_upcoming' => 'Kontrak mendekati tanggal berakhir',
                'title_overdue' => 'Kontrak lewat tanggal berakhir',
                'columns' => ['status', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', DocumentStatus::Approved->value)
                    ->whereNull('deleted_at'),
            ],
            [
                // The RS Medika "Triwulan II 25%" termin (Rp 120 jt) let its
                // whole quarter pass unbilled — a dated, unbilled termin on an
                // approved contract is finance's to raise.
                'key' => 'termin_due',
                'table' => 'crm_contract_termins',
                'date' => 'due_date',
                'display' => 'name',
                'value' => 'amount',
                'label' => 'Termin kontrak',
                'unit' => 'termin',
                'date_word' => 'rencana tagih',
                'lead_days' => 7,
                'permission' => 'fin.create',
                'link' => 'siap-tagih',
                'title_upcoming' => 'Termin kontrak mendekati jadwal tagih',
                'title_overdue' => 'Termin kontrak lewat jadwal tagih',
                'requires' => ['crm_contracts'],
                'columns' => ['billed_at', 'contract_id', 'crm_contracts.status', 'crm_contracts.deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('billed_at')
                    ->whereExists(static fn (Builder $contract) => $contract
                        ->select(DB::raw(1))
                        ->from('crm_contracts')
                        ->whereColumn('crm_contracts.id', 'crm_contract_termins.contract_id')
                        ->where('crm_contracts.status', DocumentStatus::Approved->value)
                        ->whereNull('crm_contracts.deleted_at')),
            ],
            [
                // 'expired' is never stored on a guarantee, so a stale status
                // cannot silence this: only active/released/claimed exist
                // (Modules\Crm\Enums\GuaranteeStatus) and released/claimed
                // drop out here. valid_through_end: a bank guarantee is
                // claimable THROUGH its stated end day ("berlaku s/d"), and
                // Guarantee::isExpired plus the register's is_expired/days_left
                // all call that day Berlaku / 0 hari lagi — so the end day
                // reports as MENIPIS "hari ini" and LEWAT starts the day
                // after, instead of the direktur reading "lewat" in the inbox
                // while the register shows the same bond green.
                'key' => 'guarantee_end',
                'table' => 'crm_guarantees',
                'date' => 'end_date',
                'display' => 'number',
                'value' => 'value',
                'label' => 'Jaminan & asuransi',
                'unit' => 'jaminan',
                'date_word' => 'berakhir',
                'lead_days' => 30,
                'permission' => 'crm.approve',
                'link' => 'r/crm/guarantees',
                'title_upcoming' => 'Jaminan/asuransi mendekati tanggal berakhir',
                'title_overdue' => 'Jaminan/asuransi lewat tanggal berakhir',
                'valid_through_end' => true,
                'columns' => ['status', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', 'active') // GuaranteeStatus::Active
                    ->whereNull('deleted_at'),
            ],
            [
                // Retention money is only recoverable while somebody remembers
                // it: an approved BAST whose release date approaches, while an
                // unreleased retention row for its project still exists. The
                // demo holds Rp 2,425 M + Rp 490 jt of retention conditioned
                // on "selesai masa garansi 12 bulan".
                'key' => 'bast_retention_release',
                'table' => 'prj_bast',
                'date' => 'retention_release_due',
                'display' => 'code',
                'label' => 'Retensi BAST',
                'unit' => 'BAST',
                'date_word' => 'jatuh tempo',
                'lead_days' => 14,
                'permission' => 'fin.create',
                'link' => 'retensi',
                'title_upcoming' => 'Retensi mendekati jadwal pengembalian',
                'title_overdue' => 'Retensi lewat jadwal pengembalian',
                'requires' => ['fin_ar_retentions'],
                'columns' => ['status', 'deleted_at', 'project_id', 'fin_ar_retentions.project_id', 'fin_ar_retentions.released'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', DocumentStatus::Approved->value)
                    ->whereNull('deleted_at')
                    ->whereExists(static fn (Builder $retention) => $retention
                        ->select(DB::raw(1))
                        ->from('fin_ar_retentions')
                        ->whereColumn('fin_ar_retentions.project_id', 'prj_bast.project_id')
                        ->where('fin_ar_retentions.released', 0)),
            ],
            [
                // An open K3 corrective action past its due date is exactly the
                // finding an SMK3 audit writes up. Statuses pinned against
                // Modules\Projects\Enums\IncidentStatus.
                'key' => 'safety_incident_due',
                'table' => 'prj_safety_incidents',
                'date' => 'due_date',
                'display' => 'code',
                'label' => 'Tindak lanjut insiden K3',
                'unit' => 'insiden',
                'date_word' => 'batas waktu',
                'lead_days' => 3,
                'permission' => 'prj.update',
                'link' => 'r/projects/safety-incidents',
                'title_upcoming' => 'Tindak lanjut insiden K3 mendekati batas waktu',
                'title_overdue' => 'Tindak lanjut insiden K3 lewat batas waktu',
                'columns' => ['status', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereIn('status', ['open', 'investigating'])
                    ->whereNull('deleted_at'),
            ],
            [
                // The day-one alarm: PO/2026/II/0001 (Rp 232,5 jt, promised
                // 1 Mar) and PO/2026/III/0002 (Rp 128,3 jt, promised 23 Mar),
                // both approved, neither closed, zero receipts. lead 0 — a
                // future expected date is normal, only lateness alarms.
                // closed_at is the done marker: tutup PO bila sudah diterima
                // penuh, or this keeps nagging on purpose.
                'key' => 'po_expected',
                'table' => 'prc_purchase_orders',
                'date' => 'expected_date',
                'display' => 'code',
                'value' => 'total',
                'label' => 'Pesanan pembelian',
                'unit' => 'PO',
                'date_word' => 'dijanjikan',
                'lead_days' => 0,
                'permission' => 'prc.update',
                'link' => 'r/procurement/purchase-orders',
                'title_upcoming' => null,
                'title_overdue' => 'Pesanan pembelian lewat tanggal terima',
                'columns' => ['status', 'closed_at', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', DocumentStatus::Approved->value)
                    ->whereNull('closed_at')
                    ->whereNull('deleted_at'),
            ],
            [
                // A PR nobody turned into a PO — approved OR still submitted:
                // PR/2026/III/0002 sat in 'submitted' with needed_date
                // 2026-04-01, 122 days past, and an approved-only scope kept
                // it invisible while the site went without the material. A
                // submitted PR past its needed date is just as unpurchased,
                // and prc.create can chase the approval. PR/2026/II/0001 in
                // the demo IS covered by PO 1 and must stay silent — the
                // NOT EXISTS below is what the demo row proves correct.
                'key' => 'pr_needed',
                'table' => 'prc_purchase_requisitions',
                'date' => 'needed_date',
                'display' => 'code',
                'label' => 'Permintaan pembelian',
                'unit' => 'PR',
                'date_word' => 'dibutuhkan',
                'lead_days' => 7,
                'permission' => 'prc.create',
                'link' => 'r/procurement/purchase-requisitions',
                'title_upcoming' => 'Permintaan pembelian mendekati tanggal dibutuhkan',
                'title_overdue' => 'Permintaan pembelian lewat tanggal dibutuhkan',
                'requires' => ['prc_purchase_orders'],
                'columns' => ['status', 'deleted_at', 'prc_purchase_orders.purchase_requisition_id', 'prc_purchase_orders.deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereIn('status', [DocumentStatus::Submitted->value, DocumentStatus::Approved->value])
                    ->whereNull('deleted_at')
                    ->whereNotExists(static fn (Builder $order) => $order
                        ->select(DB::raw(1))
                        ->from('prc_purchase_orders')
                        ->whereColumn('prc_purchase_orders.purchase_requisition_id', 'prc_purchase_requisitions.id')
                        ->whereNull('prc_purchase_orders.deleted_at')),
            ],
            [
                // SPK/2026/II/0001 ends 2026-11-30 — the final opname and any
                // perpanjangan must be settled before the SPK lapses under the
                // subcontractor's feet.
                'key' => 'subcontract_end',
                'table' => 'scm_subcontracts',
                'date' => 'end_date',
                'display' => 'code',
                'value' => 'value',
                'label' => 'SPK subkontraktor',
                'unit' => 'SPK',
                'date_word' => 'berakhir',
                'lead_days' => 14,
                'permission' => 'scm.update',
                'link' => 'r/subcontract/subcontracts',
                'title_upcoming' => 'SPK subkontraktor mendekati tanggal berakhir',
                'title_overdue' => 'SPK subkontraktor lewat tanggal berakhir',
                'columns' => ['status', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', DocumentStatus::Approved->value)
                    ->whereNull('deleted_at'),
            ],
            [
                // An unachieved milestone is the lock on its termin: the open
                // one due 2026-10-31 on PRJ-2026-002 releases the next
                // Rp 3,92 M of billing. The parent guard exists because an
                // unachieved milestone of an on-hold/closed/soft-deleted
                // project will NEVER be achieved — without it, a contract
                // terminated mid-build nags prj.update every 3 days forever,
                // the cancelled-document noise every other scope avoids.
                'key' => 'milestone_due',
                'table' => 'prj_milestones',
                'date' => 'due_date',
                'display' => 'name',
                'label' => 'Milestone proyek',
                'unit' => 'milestone',
                'date_word' => 'jatuh tempo',
                'lead_days' => 7,
                'permission' => 'prj.update',
                'link' => 'r/projects/milestones',
                'title_upcoming' => 'Milestone proyek mendekati jatuh tempo',
                'title_overdue' => 'Milestone proyek lewat jatuh tempo',
                'requires' => ['prj_projects'],
                'columns' => ['achieved_date', 'project_id', 'prj_projects.status', 'prj_projects.deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('achieved_date')
                    ->whereExists(static fn (Builder $project) => $project
                        ->select(DB::raw(1))
                        ->from('prj_projects')
                        ->whereColumn('prj_projects.id', 'prj_milestones.project_id')
                        // ProjectStatus::OnHold / ::Closed
                        ->whereNotIn('prj_projects.status', ['on_hold', 'closed'])
                        ->whereNull('prj_projects.deleted_at')),
            ],
            [
                // lead 0: an invoice inside its payment term is normal, only an
                // approved, unpaid, uncancelled one past due_date alarms.
                'key' => 'ar_invoice_due',
                'table' => 'fin_ar_invoices',
                'date' => 'due_date',
                'display' => 'code',
                'value' => 'total',
                'label' => 'Invoice pelanggan',
                'unit' => 'invoice',
                'date_word' => 'jatuh tempo',
                'lead_days' => 0,
                'permission' => 'fin.create',
                'link' => 'r/finance/ar-invoices',
                'title_upcoming' => null,
                'title_overdue' => 'Invoice pelanggan lewat jatuh tempo',
                'columns' => ['status', 'paid_at', 'cancelled_at', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', DocumentStatus::Approved->value)
                    ->whereNull('paid_at')
                    ->whereNull('cancelled_at')
                    ->whereNull('deleted_at'),
            ],
            [
                // next_due_date is pure manual entry — nothing in the codebase
                // rolls it forward. Only the NEWEST maintenance row per asset
                // carries a live reminder (latest_per_group): recording the
                // 14 Jun service on asset 1 must silence the reminder its
                // previous service left behind. Disposed assets drop out.
                // alarm_when_date_missing (the PKWT pattern): a newest service
                // saved WITHOUT a next_due_date would otherwise both match no
                // tier AND supersede the older dated row — one forgotten field
                // and the Excavator Komatsu PC200-8 drops out of the watch
                // list forever, NULL read as "no more service" when it means
                // "forgot to schedule".
                'key' => 'maintenance_next_due',
                'table' => 'ast_maintenances',
                'date' => 'next_due_date',
                'display' => 'code',
                'label' => 'Servis aset',
                'unit' => 'jadwal servis',
                'date_word' => 'jadwal berikut',
                'lead_days' => 14,
                'permission' => 'ast.update',
                'link' => 'r/assets/maintenances',
                'title_upcoming' => 'Servis aset mendekati jadwal berikut',
                'title_overdue' => 'Servis aset lewat jadwal berikut',
                'alarm_when_date_missing' => true,
                'title_missing' => 'Servis aset tanpa jadwal berikut',
                'missing_text' => 'servis terakhir tercatat tanpa jadwal berikut',
                'requires' => ['ast_assets'],
                'columns' => ['deleted_at', 'asset_id', 'ast_assets.status', 'ast_assets.deleted_at'],
                'latest_per_group' => ['group' => 'asset_id', 'order' => 'maintenance_date', 'soft_deletes' => true],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('deleted_at')
                    ->whereExists(static fn (Builder $asset) => $asset
                        ->select(DB::raw(1))
                        ->from('ast_assets')
                        ->whereColumn('ast_assets.id', 'ast_maintenances.asset_id')
                        ->where('ast_assets.status', '!=', 'disposed') // AssetStatus::Disposed
                        ->whereNull('ast_assets.deleted_at')),
            ],
            [
                // DEP/2026/V/0003 plans to return asset 5 from PRJ-2026-002 on
                // 2026-09-30 — an asset that quietly stays on site is an asset
                // the next project cannot plan around.
                'key' => 'deployment_planned_until',
                'table' => 'ast_deployments',
                'date' => 'planned_until',
                'display' => 'code',
                'label' => 'Penempatan aset',
                'unit' => 'penempatan',
                'date_word' => 'rencana kembali',
                'lead_days' => 7,
                'permission' => 'ast.update',
                'link' => 'r/assets/deployments',
                'title_upcoming' => 'Penempatan aset mendekati rencana kembali',
                'title_overdue' => 'Penempatan aset lewat rencana kembali',
                'columns' => ['status', 'returned_at', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', 'active') // DeploymentStatus::Active
                    ->whereNull('returned_at')
                    ->whereNull('deleted_at'),
            ],
            [
                // Renewal is a SALES motion producing a new quotation, so this
                // goes to crm.update — svc.update holders are teknisi, and
                // teknisi must not be the ones chasing a Rp 480 jt/tahun
                // renewal (SVC/2026/III/0001 ends 2027-03-31; 60-day lead
                // starts the clock 2027-01-30).
                'key' => 'svc_contract_period_end',
                'table' => 'svc_contracts',
                'date' => 'period_end',
                'display' => 'code',
                'value' => 'contract_value',
                'label' => 'Kontrak layanan',
                'unit' => 'kontrak layanan',
                'date_word' => 'berakhir',
                'lead_days' => 60,
                'permission' => 'crm.update',
                'link' => 'r/servicedesk/contracts',
                'title_upcoming' => 'Kontrak layanan mendekati akhir periode',
                'title_overdue' => 'Kontrak layanan lewat akhir periode',
                'columns' => ['status', 'deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('status', 'active') // ServiceDesk ContractStatus::Active
                    ->whereNull('deleted_at'),
            ],
            [
                // PP 35/2021: a PKWT worked past its end date becomes PKWTT
                // demi hukum. The missing-date flag is the enforcement the
                // nullable column deliberately lacks — EMP-0007 and EMP-0008
                // are live kontrak rows with NULL pkwt_end_date, so this fires
                // on day one.
                'key' => 'pkwt_end',
                'table' => 'hr_employees',
                'date' => 'pkwt_end_date',
                'display' => 'name',
                'label' => 'PKWT karyawan',
                'unit' => 'karyawan',
                'date_word' => 'PKWT berakhir',
                'lead_days' => 60,
                'permission' => 'hr.update',
                'link' => 'r/hr/employees',
                'title_upcoming' => 'PKWT mendekati tanggal berakhir',
                'title_overdue' => 'PKWT lewat tanggal berakhir',
                'alarm_when_date_missing' => true,
                'title_missing' => 'PKWT tanpa tanggal berakhir',
                'missing_text' => 'berstatus kontrak tanpa tanggal akhir PKWT tercatat',
                'columns' => ['employment_type', 'status', 'deleted_at', 'pkwt_basis'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('employment_type', 'kontrak') // EmploymentType::Kontrak
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
                // Selesainya-pekerjaan is dateless BY LAW (PP 35/2021 Pasal 9);
                // only jangka-waktu rows — and rows whose basis was never
                // recorded, which is its own omission — owe a calendar date.
                'missing_scope' => static fn (Builder $query): Builder => $query
                    ->where(static fn (Builder $inner) => $inner
                        ->where('pkwt_basis', 'jangka_waktu')
                        ->orWhereNull('pkwt_basis')),
            ],
            [
                // The price of a lapsed certificate is written in fin_taxes:
                // PPh final pelaksanaan bersertifikat 2,65% vs 4,00% tanpa —
                // 1,35 points of every construction billing. NULL expiry means
                // "tidak kedaluwarsa" and never lands in a tier; renewal =
                // update expiry_date, a dropped cert = soft delete.
                'key' => 'certificate_expiry',
                'table' => 'hr_certificates',
                'date' => 'expiry_date',
                'display' => 'name',
                'label' => 'Sertifikat',
                'unit' => 'sertifikat',
                'date_word' => 'kedaluwarsa',
                'lead_days' => 60,
                'permission' => 'hr.update',
                'link' => 'r/hr/certificates',
                'title_upcoming' => 'Sertifikat mendekati kedaluwarsa',
                'title_overdue' => 'Sertifikat sudah kedaluwarsa',
                'requires' => ['hr_employees'],
                'columns' => ['deleted_at', 'employee_id', 'hr_employees.status', 'hr_employees.deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('deleted_at')
                    ->whereExists(static fn (Builder $employee) => $employee
                        ->select(DB::raw(1))
                        ->from('hr_employees')
                        ->whereColumn('hr_employees.id', 'hr_certificates.employee_id')
                        ->where('hr_employees.status', 'active')
                        ->whereNull('hr_employees.deleted_at')),
            ],
            [
                // Register prakualifikasi vendor (temuan #35/#69): SBU subkon
                // yang kadaluarsa di proyek pemerintah bisa menggugurkan
                // pembayaran, dan tarif PPh final PP 9/2022 (2,65% vs 4%)
                // bersandar pada sertifikat yang masih berlaku.
                // valid_through_end: "berlaku s/d" masih sah PADA hari
                // terakhirnya — bacaan yang sama dengan VendorDocument::isExpired
                // dan gate VendorQualificationService. NULL = tidak kedaluwarsa
                // (NPWP). Dokumen vendor nonaktif/terhapus keluar dari cakupan:
                // alarm tanpa tindak lanjut hanya melatih orang mengabaikan alarm.
                'key' => 'vendor_document_valid_until',
                'table' => 'prc_vendor_documents',
                'date' => 'valid_until',
                'display' => 'name',
                'label' => 'Dokumen vendor',
                'unit' => 'dokumen',
                'date_word' => 'berlaku s/d',
                'lead_days' => 30,
                'permission' => 'prc.update',
                'link' => 'r/procurement/vendor-documents',
                'title_upcoming' => 'Dokumen vendor mendekati akhir masa berlaku',
                'title_overdue' => 'Dokumen vendor lewat masa berlaku',
                'valid_through_end' => true,
                'requires' => ['prc_vendors'],
                'columns' => ['deleted_at', 'vendor_id', 'prc_vendors.status', 'prc_vendors.deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('deleted_at')
                    ->whereExists(static fn (Builder $vendor) => $vendor
                        ->select(DB::raw(1))
                        ->from('prc_vendors')
                        ->whereColumn('prc_vendors.id', 'prc_vendor_documents.vendor_id')
                        ->where('prc_vendors.status', 'active') // VendorStatus::Active
                        ->whereNull('prc_vendors.deleted_at')),
            ],
            [
                // Kalender pajak (#25): satu baris per (jenis, masa) di
                // fin_tax_obligations, tenggat setor dari TaxDeadlines — aturan
                // yang SAMA dengan proyeksi kas, jadi alarm ini dan rencana kas
                // tidak pernah menyebut tanggal berbeda. Baris keluar dari
                // cakupan begitu disetor ATAU (masa nihil) begitu dilapor:
                // alarm berhenti saat tindakan penyetorannya sudah terjadi —
                // pelaporan dikawal di layar kalender pajak, bukan lewat alarm.
                // Bunga keterlambatan setor berjalan per bulan; 7 hari cukup
                // untuk menyiapkan SSP dan approval pembayarannya.
                'key' => 'tax_masa_due',
                'table' => 'fin_tax_obligations',
                'date' => 'due_date',
                'display' => 'name',
                'value' => 'amount',
                'label' => 'Setoran pajak masa',
                'unit' => 'kewajiban',
                'date_word' => 'jatuh tempo setor',
                'lead_days' => 7,
                'permission' => 'fin.create',
                'link' => 'kalender-pajak',
                'title_upcoming' => 'Setoran pajak masa mendekati jatuh tempo',
                'title_overdue' => 'Setoran pajak masa lewat jatuh tempo',
                'columns' => ['disetor_date', 'dilapor_date'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('disetor_date')
                    ->whereNull('dilapor_date'),
            ],
        ];
    }

    /**
     * Run every watcher against one "today".
     *
     * 'undated' is the honesty line: a watcher that matched nothing because
     * EVERY row in its scope has a NULL date is not "all clear", it is blind —
     * on day one termin_due matched 0 of 13 live crm_contract_termins rows
     * (all due_date NULL, including the RS Medika "Triwulan II 25%" Rp 120 jt
     * that fell due 1 Jul), and without this line that silence read as health.
     * The dates are NOT invented here; the operator is told to enter them.
     *
     * @return array{checked: int, skipped: array<int, array{key: string, table: string, reason: string}>, undated: array<int, array{key: string, table: string, column: string, count: int}>, findings: array<int, array<string, mixed>>}
     */
    public static function scan(CarbonImmutable $today): array
    {
        $checked = 0;
        $skipped = [];
        $undated = [];
        $findings = [];

        // Both boundaries are next-day strings so that a stored
        // "2026-08-01 00:00:00" AND a plain "2026-08-01" fall on the same side:
        // < tomorrow catches today, >= tomorrow starts strictly after today.
        $tomorrow = $today->addDay()->toDateString();

        foreach (self::entries() as $entry) {
            $reason = self::missingSchema($entry);

            if ($reason !== null) {
                $skipped[] = ['key' => $entry['key'], 'table' => $entry['table'], 'reason' => $reason];

                continue;
            }

            $checked++;
            $date = $entry['date'];

            // valid_through_end shifts BOTH tier boundaries one day: a
            // "berlaku s/d" row is still valid ON its end day, so that day is
            // MENIPIS ("hari ini") and LEWAT starts strictly after it. The
            // today-string comparisons keep the storage footgun covered:
            // "2026-08-01 00:00:00" >= "2026-08-01" and NOT < "2026-08-01".
            $boundary = ($entry['valid_through_end'] ?? false) ? $today->toDateString() : $tomorrow;

            // LEWAT: date passed. whereNotNull is redundant for '<' but says
            // out loud that a missing date is never "overdue" — it is either
            // ignored or, with the flag below, its own alarm.
            $lewat = self::finding($entry, self::LEWAT, $today,
                static fn (Builder $query): Builder => $query
                    ->whereNotNull($date)
                    ->where($date, '<', $boundary));
            $findings[] = $lewat;

            $menipis = null;

            if ($entry['lead_days'] > 0) {
                $until = $today->addDays($entry['lead_days'] + 1)->toDateString();
                $menipis = self::finding($entry, self::MENIPIS, $today,
                    static fn (Builder $query): Builder => $query
                        ->where($date, '>=', $boundary)
                        ->where($date, '<', $until));
                $findings[] = $menipis;
            }

            if ($entry['alarm_when_date_missing'] ?? false) {
                // missing_scope narrows WHO owes a date. PP 35/2021 Pasal 9
                // makes a selesainya-pekerjaan PKWT lawfully dateless — nagging
                // HR weekly about it pushes them to invent a date, which is the
                // exact corruption the pkwt_basis column exists to prevent.
                $missingScope = $entry['missing_scope'] ?? null;
                $findings[] = self::finding($entry, self::TANPA_TANGGAL, $today,
                    static fn (Builder $query): Builder => $missingScope
                        ? $missingScope($query->whereNull($date))
                        : $query->whereNull($date));
            } elseif ($lewat === null && $menipis === null) {
                // The watcher matched nothing — distinguish "all dates are
                // fine" from "there are no dates to watch". Only reached when
                // the entry is silent, so the two extra counts cost nothing on
                // the mornings that matter.
                $inScope = self::scoped($entry)->count();

                if ($inScope > 0 && self::scoped($entry)->whereNotNull($date)->count() === 0) {
                    $undated[] = ['key' => $entry['key'], 'table' => $entry['table'], 'column' => $date, 'count' => $inScope];
                }
            }
        }

        return [
            'checked' => $checked,
            'skipped' => $skipped,
            'undated' => $undated,
            'findings' => array_values(array_filter($findings)),
        ];
    }

    /**
     * The Indonesian notification body for one finding: up to five rows named
     * with their date, age and rupiah, then the count — the count lives HERE
     * and never in the title, or every change would mint a fresh title and
     * stack unread copies.
     */
    public static function body(array $finding): string
    {
        $entry = self::entryFor($finding['key']);

        $sentences = array_map(
            static fn (array $item): string => self::sentence($entry, $finding['tier'], $item),
            array_slice($finding['items'], 0, self::BODY_ITEMS),
        );

        if ($finding['count'] > 1) {
            $sentences[] = "Total {$finding['count']} {$entry['unit']}.";
        }

        return implode(' ', $sentences);
    }

    /**
     * A stable fingerprint of WHICH rows a finding names, for the
     * NotificationService dedupe: same title + same fingerprint = the same
     * fact, safe to keep suppressing; a third PO going overdue the day after
     * "Total 2 PO." was delivered changes the fingerprint, so the fresh count
     * fires immediately instead of the unread copy understating for weeks.
     * Codes are sorted so row order never rotates it, and the count guards the
     * rare >MAX_ITEMS group whose named slice stays put while rows join. md5
     * keeps it inside core_notifications.document_code (40 chars).
     */
    public static function signature(array $finding): string
    {
        $codes = array_column($finding['items'], 'code');
        sort($codes);

        return md5($finding['count'].'|'.implode('|', $codes));
    }

    // ---------------------------------------------------------------- internal

    /**
     * One tier of one entry, or null when nothing is in it.
     */
    private static function finding(array $entry, string $tier, CarbonImmutable $today, \Closure $constrain): ?array
    {
        $count = $constrain(self::scoped($entry))->count();

        if ($count === 0) {
            return null;
        }

        $columns = array_values(array_unique(array_filter([
            $entry['display'],
            $entry['date'],
            $entry['value'] ?? null,
        ])));

        $rows = $constrain(self::scoped($entry))
            ->orderBy($tier === self::TANPA_TANGGAL ? $entry['display'] : $entry['date'])
            ->limit(self::MAX_ITEMS)
            ->get($columns);

        $items = $rows->map(static function (object $row) use ($entry, $today): array {
            $raw = $row->{$entry['date']} ?? null;
            $date = $raw === null ? null : substr((string) $raw, 0, 10);

            return [
                'code' => (string) $row->{$entry['display']},
                'date' => $date,
                // Signed: positive = days remaining, negative = days past.
                'days' => $date === null ? null : (int) $today->diffInDays(CarbonImmutable::parse($date)),
                'value' => isset($entry['value']) ? (float) $row->{$entry['value']} : null,
            ];
        })->all();

        $title = match ($tier) {
            self::LEWAT => $entry['title_overdue'],
            self::MENIPIS => $entry['title_upcoming'],
            self::TANPA_TANGGAL => $entry['title_missing'],
        };

        return [
            'key' => $entry['key'],
            'label' => $entry['label'],
            'tier' => $tier,
            'title' => $title,
            'permission' => $entry['permission'],
            'link' => $entry['link'],
            'lead_days' => $entry['lead_days'],
            'count' => $count,
            'items' => $items,
        ];
    }

    /**
     * Public (not private) because CalendarEvents composes it: the calendar
     * windows the SAME eighteen scopes by month instead of tiering them by
     * urgency, and duplicating the closures there is how the calendar and the
     * watcher would drift into contradicting each other. Behavior unchanged.
     */
    public static function scoped(array $entry): Builder
    {
        $query = ($entry['scope'])(DB::table($entry['table']));

        if (isset($entry['latest_per_group'])) {
            $latest = $entry['latest_per_group'];
            $table = $entry['table'];

            // Newest row per group by (order column, id): a newer service
            // record supersedes the reminder the previous one left behind,
            // and a soft-deleted newer row supersedes nothing.
            $query->whereNotExists(static function (Builder $newer) use ($table, $latest): void {
                $newer->select(DB::raw(1))
                    ->from("{$table} as newer")
                    ->whereColumn("newer.{$latest['group']}", "{$table}.{$latest['group']}")
                    ->when($latest['soft_deletes'] ?? false, static fn (Builder $query): Builder => $query->whereNull('newer.deleted_at'))
                    ->where(static function (Builder $wins) use ($table, $latest): void {
                        $wins->whereColumn("newer.{$latest['order']}", '>', "{$table}.{$latest['order']}")
                            ->orWhere(static function (Builder $tie) use ($table, $latest): void {
                                $tie->whereColumn("newer.{$latest['order']}", "{$table}.{$latest['order']}")
                                    ->whereColumn('newer.id', '>', "{$table}.id");
                            });
                    });
            });
        }

        return $query;
    }

    /**
     * Why this entry cannot be scanned right now, or null when it can. Checked
     * before any query so a sibling team's half-run migration yields a CLI
     * "skipped" line instead of a crash at 08:30.
     *
     * EVERY column the entry's queries touch is checked, not just the date:
     * the date itself, the display/value columns finding() selects, the
     * latest_per_group pair, and each column the scope closure filters on
     * (declared in 'columns'). Two teams migrate this repository daily — with
     * only the date checked, Finance consolidating fin_ar_retentions.released
     * into released_at would pass hasTable, then throw QueryException inside
     * the retention EXISTS and kill all remaining watchers mid-loop.
     *
     * Public (not private) because CalendarEvents runs the same check over its
     * own entries — one schema-degradation rule, defined once. Behavior
     * unchanged.
     */
    /**
     * Per-request memo of each table's column listing (null = table absent).
     *
     * Schema::hasColumn() issues two SQLite queries per call and caches
     * nothing; with ~12 declared columns x 23 sources, one calendar render
     * paid ~276 introspection queries before fetching a single event — on the
     * endpoint the dashboard fires on every load. One getColumnListing() per
     * DISTINCT table serves every column check from memory instead.
     *
     * @var array<string, ?array<int, string>>
     */
    private static array $schemaMemo = [];

    private static function tableColumns(string $table): ?array
    {
        if (! array_key_exists($table, self::$schemaMemo)) {
            self::$schemaMemo[$table] = Schema::hasTable($table)
                ? array_map('strval', Schema::getColumnListing($table))
                : null;
        }

        return self::$schemaMemo[$table];
    }

    /** Tests migrate mid-process; a memo that outlives the schema lies. */
    public static function flushSchemaMemo(): void
    {
        self::$schemaMemo = [];
    }

    public static function missingSchema(array $entry): ?string
    {
        foreach ([$entry['table'], ...($entry['requires'] ?? [])] as $table) {
            if (self::tableColumns($table) === null) {
                return "table {$table} does not exist";
            }
        }

        $latest = $entry['latest_per_group'] ?? null;

        $columns = array_merge(
            [$entry['date'], $entry['display']],
            isset($entry['value']) ? [$entry['value']] : [],
            $latest === null ? [] : [$latest['group'], $latest['order']],
            $entry['columns'] ?? [],
        );

        foreach ($columns as $column) {
            [$table, $name] = str_contains($column, '.')
                ? explode('.', $column, 2)
                : [$entry['table'], $column];

            if (! in_array($name, self::tableColumns($table) ?? [], true)) {
                return "column {$table}.{$name} does not exist";
            }
        }

        return null;
    }

    private static function entryFor(string $key): array
    {
        foreach (self::entries() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        throw new \InvalidArgumentException("Unknown watched deadline [{$key}].");
    }

    private static function sentence(array $entry, string $tier, array $item): string
    {
        if ($tier === self::TANPA_TANGGAL) {
            return "{$item['code']} {$entry['missing_text']}.";
        }

        $value = isset($entry['value']) && $item['value'] !== null
            ? ' senilai '.self::rupiahShort($item['value'])
            : '';

        $days = abs((int) $item['days']);

        // 'hari ini' can land in EITHER tier: LEWAT for a date that counts as
        // late the day it arrives (a PO promised today with no receipt), and
        // MENIPIS for a valid_through_end entry on its last valid day (a
        // guarantee "berlaku s/d" today is still claimable — urgent, not lewat).
        $age = match (true) {
            $days === 0 => 'hari ini',
            $tier === self::LEWAT => "{$days} hari lalu",
            default => "{$days} hari lagi",
        };

        return "{$item['code']}{$value} {$entry['date_word']} ".self::tanggal($item['date'])." — {$age}.";
    }

    /** '2026-03-01' → '1 Mar 2026'. */
    private static function tanggal(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return $day->day.' '.self::BULAN[$day->month - 1].' '.$day->year;
    }

    /** 232500000 → 'Rp 232,5 jt'; 9700000000 → 'Rp 9,7 M'. */
    private static function rupiahShort(float $value): string
    {
        if ($value >= 1_000_000_000) {
            return 'Rp '.self::compact($value / 1_000_000_000).' M';
        }

        if ($value >= 1_000_000) {
            return 'Rp '.self::compact($value / 1_000_000).' jt';
        }

        return Money::format($value, false);
    }

    private static function compact(float $value): string
    {
        $formatted = number_format($value, 1, ',', '.');

        return str_ends_with($formatted, ',0') ? substr($formatted, 0, -2) : $formatted;
    }
}
