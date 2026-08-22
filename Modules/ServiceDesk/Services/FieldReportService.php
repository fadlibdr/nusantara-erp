<?php

namespace Modules\ServiceDesk\Services;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Inventory\Services\IssueService;
use Modules\Inventory\Services\StockService;
use Modules\ServiceDesk\Enums\FieldReportStatus;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\FieldReportPart;

class FieldReportService
{
    private const PART_FIELDS = ['item_id', 'qty', 'notes'];

    public function create(array $data): FieldReport
    {
        $parts = Arr::pull($data, 'parts', []);

        return DB::transaction(function () use ($data, $parts): FieldReport {
            $report = new FieldReport(Arr::except($data, ['code', 'status']));
            $report->status = FieldReportStatus::Draft;
            $report->save(); // HasDocumentNumber fills the PM code

            $this->createParts($report, $parts);

            return $report->load('parts', 'ticket');
        });
    }

    public function update(FieldReport $report, array $data): FieldReport
    {
        $this->assertEditable($report);

        return DB::transaction(function () use ($report, $data): FieldReport {
            $parts = Arr::pull($data, 'parts');

            $report->fill(Arr::except($data, ['code', 'status']));
            $report->save();

            if (is_array($parts)) {
                // Parts are replaced wholesale, like every line-item payload.
                $report->parts()->delete();
                $this->createParts($report, $parts);
            }

            return $report->load('parts', 'ticket');
        });
    }

    public function delete(FieldReport $report): void
    {
        $this->assertEditable($report);

        // Soft delete only; parts stay attached in case the report is restored.
        $report->delete();
    }

    /**
     * Hand the report over for the customer's signature — but only once the
     * signature could actually be given.
     *
     * SUBMITTING IS A ONE-WAY DOOR ONTO THE PERIOD CLOSE. A submitted report
     * carrying parts is a DanglingDocuments source rendered at BLOCK severity
     * with no override, and PeriodCloseService is sequential, so a report that
     * can never be acknowledged wedges its own month AND every month after it.
     * The three ways that used to happen are all permanent once the report has
     * left Draft, because isEditable() is Draft-only: no gudang (the remedy
     * issueParts() prints is the one field update() then refuses), a fiscal
     * period that closed over report_date, and a visit day that has fallen
     * behind the last inv_stock_ledger row for one of the parts — MAX(trx_date)
     * only ever grows, so that last one never comes back.
     *
     * So the preconditions are asked HERE, where the report is still a Draft and
     * every one of them is still fixable in the form the operator already has
     * open. assertPartsCanStillBeIssued() asks them by RUNNING the real thing.
     *
     * The status is judged on a row RE-READ inside this transaction, not on the
     * instance the route resolved. Since returnToDraft() exists, Submitted is no
     * longer a one-way door, so a report can move underneath a request that is
     * already in flight — and lockForUpdate is a no-op on SQLite, which makes
     * the re-read itself the protection rather than the lock.
     */
    public function submit(FieldReport $report): FieldReport
    {
        return DB::transaction(function () use ($report): FieldReport {
            $fresh = $this->lockedRead($report);

            if ($fresh->status !== FieldReportStatus::Draft) {
                throw new LogicException("Field report {$fresh->code} is {$fresh->status->value} and cannot be submitted.");
            }

            $this->assertPartsCanStillBeIssued($fresh);

            $fresh->forceFill(['status' => FieldReportStatus::Submitted])->save();

            return $fresh->load('parts', 'ticket');
        });
    }

    /**
     * The way back out of Submitted, which is what makes the wedge IMPOSSIBLE
     * rather than merely unlikely.
     *
     * Passing the dry run at submit time is not a promise that still holds
     * later: a goods receipt or another bon posted against the same
     * (warehouse, item) on a day after the visit moves MAX(trx_date) past
     * report_date, and from then on the chronology guard refuses this report's
     * bon for ever. Finance closing report_date's month does the same. Neither
     * is anything the technician did, and neither can be undone from the
     * Inventory side — so the report has to be able to retreat to Draft, be
     * re-dated or emptied of parts, and go round again.
     *
     * ACKNOWLEDGED IS STILL ONE-WAY — see FieldReportStatus::canReturnToDraft().
     * Nothing has been posted for a merely-submitted report, so there is
     * nothing here to unwind: only the status moves, and the signature
     * timestamp (which only acknowledge() ever writes) is not touched.
     * customer_sign_name is left alone too, though create() and update() can
     * both write it, so it is not proof of a signature on its own.
     *
     * THE STATUS IS RE-READ UNDER LOCK INSIDE THE TRANSACTION, and that re-read
     * is the whole guard rather than a formality. Deciding from the instance the
     * route resolved would let this method undo a posted acknowledgement: bind a
     * Submitted report, let a concurrent acknowledge() commit, and the stale
     * model still says Submitted — the report would drop back to Draft, editable,
     * with a posted bon and a posted journal still pointing at it. That is the
     * exact state canReturnToDraft() exists to make impossible, and lockForUpdate
     * cannot deliver it here because it is a no-op on SQLite.
     */
    public function returnToDraft(FieldReport $report): FieldReport
    {
        return DB::transaction(fn (): FieldReport => $this->returnLockedToDraft($this->lockedRead($report)));
    }

    private function returnLockedToDraft(FieldReport $report): FieldReport
    {
        if ($report->status === FieldReportStatus::Acknowledged) {
            $issueCode = $report->issue()->value('code');

            throw new LogicException(sprintf(
                'Laporan %s sudah disahkan pelanggan dan tidak dapat dikembalikan ke draf. %s',
                $report->code,
                $issueCode !== null
                    ? "Pengesahan itu sudah menerbitkan bon {$issueCode}: suku cadangnya sudah keluar dari "
                        .'gudang dan jurnalnya sudah ada di buku besar. Bon yang lahir dari berita acara tidak '
                        .'dapat dibatalkan, jadi koreksinya lewat opname.'
                    : 'Tanda tangan pelanggan adalah bukti kunjungan dan tidak ditarik kembali; '
                        .'terbitkan berita acara baru bila kunjungannya diulang.',
            ));
        }

        if (! $report->status->canReturnToDraft()) {
            throw new LogicException("Field report {$report->code} is {$report->status->value} and cannot be returned to draft.");
        }

        $report->forceFill(['status' => FieldReportStatus::Draft])->save();

        return $report->load('parts', 'ticket');
    }

    /**
     * Customer sign-off on the visit: name + timestamp lock the report — and
     * the spare parts it records leave stock in the same breath.
     *
     * ONE TRANSACTION, by design: the signature and the pengeluaran barang are
     * one fact ("the customer accepted a visit that consumed these parts"), so
     * the status flip and the posted issue commit together or not at all. A
     * refused issue — empty warehouse, missing gudang, closed fiscal period —
     * rolls the acknowledgement back with it; the report stays Submitted and
     * can be acknowledged again once the cause is fixed. Issuing twice is
     * impossible from both sides: this guard refuses a non-Submitted report,
     * and inv_issues.field_report_id is UNIQUE, so even two concurrent
     * acknowledgements (lockForUpdate is a no-op on SQLite) cannot both keep
     * their issue.
     *
     * HISTORICAL GAP, deliberately left open: PM/2026/VI/0001 was acknowledged
     * on 2026-06-10, before this bridge existed. Its 1 x ITM-0004 CCTV Dome
     * 4MP was consumed on site but never issued, so the sub-ledger and 1-1400
     * each still carry Rp 1.850.000 (30 units @ 1.850.000 in WH-PUSAT) for a
     * stock that is physically 29 — invisible to erp:inventory-method-check
     * because both sides are overstated equally. That row is NOT repaired here:
     * a retroactive issue would post a June journal that never happened.
     * Forward-only; the difference surfaces on the next stock opname, whose
     * variance account (6-4400) exists for exactly this.
     */
    public function acknowledge(FieldReport $report, string $customerSignName): FieldReport
    {
        return DB::transaction(function () use ($report, $customerSignName): FieldReport {
            // Re-read inside the transaction, for the mirror of returnToDraft()'s
            // race: a report that has since gone BACK to Draft would otherwise be
            // acknowledged off a stale Submitted instance, posting the bon and
            // jumping Draft -> Acknowledged without ever passing submit()'s dry run.
            $fresh = $this->lockedRead($report);

            if ($fresh->status !== FieldReportStatus::Submitted) {
                throw new LogicException("Field report {$fresh->code} must be submitted before the customer can acknowledge it.");
            }

            $fresh->forceFill([
                'status' => FieldReportStatus::Acknowledged,
                'customer_sign_name' => $customerSignName,
                'customer_signed_at' => now(),
            ])->save();

            $this->issueParts($fresh);

            return $fresh->load('parts', 'issue');
        });
    }

    /**
     * Turn the report's parts into a posted inventory issue.
     *
     * The issue goes through the SAME machinery as a hand-raised bon —
     * IssueService::create() + StockService::postIssue() — so it inherits every
     * existing rule unchanged: lines are valued at the warehouse moving average
     * at posting time, insufficient stock refuses in Indonesian ("Stok tidak
     * mencukupi…"), and the GL entry is Dr 6-4100 Beban Umum & Administrasi /
     * Cr 1-1400 Persediaan. project_id stays null on purpose: a maintenance
     * visit is not a construction project, and the schema offers no path from
     * svc_contracts to prj_projects — charging one would poison EVM's AC and
     * the PSAK 115 cost base. The cost is still attributable to the contract:
     * issue -> field_report_id -> svc_field_reports.ticket_id ->
     * svc_tickets.service_contract_id, and the journal description names the
     * report and ticket codes.
     *
     * A report with no parts issues nothing — the sign-off stays the pure
     * signature it always was.
     */
    private function issueParts(FieldReport $report): void
    {
        $parts = $report->parts()->get();

        if ($parts->isEmpty()) {
            return;
        }

        if ($report->warehouse_id === null) {
            throw new DomainException(
                "Laporan {$report->code} mencantumkan suku cadang, tetapi gudang asalnya belum diisi. "
                .'Isi kolom "Gudang suku cadang" pada laporan, lalu ulangi pengesahan pelanggan — '
                .'tanpa gudang, stok tidak dapat dikeluarkan.'
            );
        }

        $ticketCode = $report->ticket()->value('code');

        $issue = app(IssueService::class)->create([
            'field_report_id' => $report->id,
            'warehouse_id' => $report->warehouse_id,
            // Dated on the visit, not on the click: the parts left the shelf
            // the day the technician installed them, and that is the date the
            // GL period check must judge.
            'issue_date' => $report->report_date->toDateString(),
            'issued_by' => auth()->id(),
            'purpose' => Str::limit("Suku cadang servis {$report->code} — tiket {$ticketCode}", 497),
            'items' => $parts->map(fn (FieldReportPart $part): array => [
                'item_id' => $part->item_id,
                'qty' => (float) $part->qty,
            ])->all(),
        ]);

        app(StockService::class)->postIssue($issue);
    }

    /**
     * THE ISSUE PRECONDITIONS, ASKED BY RUNNING THE ISSUE.
     *
     * The guards that refuse a field report's bon are private to StockService
     * (assertStockPeriodOpen, assertMovementInOrder) and they are not the whole
     * list — issueParts() adds the gudang, applyOut() adds "Stok tidak
     * mencukupi…", postIssueJournal() adds the chart of accounts. Restating any
     * of that here would give this module a SECOND copy of the rules, and the
     * two would drift on the first change to either: the copy would keep saying
     * yes while the real posting says no, which is precisely the wedge.
     *
     * So nothing is restated. The real path runs — IssueService::create() ->
     * StockService::postIssue(), the same two calls acknowledge() makes — inside
     * a transaction that is ALWAYS rolled back. Whatever the signature would be
     * refused for, the draft is refused for now, in the same words.
     *
     * The rollback is total and includes the ISS number: DocumentNumberService
     * bumps inv/core number_sequences inside the same transaction, so a dry run
     * does not burn a document number and the bon the real sign-off finally
     * raises is still ISS/YYYY/RM/0001. Nothing else outside the database is
     * touched — no events, no files, no mail — so "leaves no trace" is a
     * property of the transaction, not a promise about the callee.
     *
     * A report with no parts is issued nothing and asked nothing, exactly as
     * acknowledge() treats it: a signature-only visit needs no warehouse, no
     * open period and no stock.
     *
     * QueryException is caught alongside the business refusals because running
     * the real path means this method now touches inventory tables, and a
     * database-level refusal there — inv_issues.field_report_id is UNIQUE — is
     * still a reason the sign-off would fail, not a bug to show the operator as
     * a 500. The controller catches only DomainException|LogicException, so an
     * unwrapped QueryException would reach them as one.
     */
    private function assertPartsCanStillBeIssued(FieldReport $report): void
    {
        if ($report->parts()->doesntExist()) {
            return;
        }

        DB::beginTransaction();

        try {
            $this->issueParts($report);
        } catch (DomainException|LogicException|QueryException $e) {
            throw new DomainException(sprintf(
                'Laporan %s belum dapat diajukan. Pengesahan pelanggan nanti mengeluarkan suku cadangnya '
                .'dari gudang, dan pengeluaran itu diuji sekarang — hasilnya ditolak: %s '
                .'Perbaiki selagi laporan masih berstatus draf: setelah diajukan seluruh kolomnya terkunci, '
                .'dan periode yang memuat tanggal kunjungan tidak dapat ditutup sampai laporan ini selesai. '
                .'Pemeriksaan ini tidak membuat bon maupun mutasi stok — nomor bon yang mungkin disebut di '
                .'atas hanya nomor uji coba.',
                $report->code,
                rtrim($e->getMessage()),
            ), previous: $e);
        } finally {
            // Success and failure alike: the dry run must never commit. Runs
            // before the wrapped exception leaves this method.
            DB::rollBack();
        }
    }

    /**
     * The row as it stands NOW, inside the caller's transaction.
     *
     * lockForUpdate() is a no-op on SQLite, which this application runs on, so
     * the re-read is the protection and the lock is what makes the same code
     * correct on a server that honours it. Every status transition goes through
     * here: once Submitted stopped being one-way, each of the three could
     * otherwise be decided from an instance the route resolved before another
     * request moved it.
     */
    private function lockedRead(FieldReport $report): FieldReport
    {
        return FieldReport::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
    }

    private function createParts(FieldReport $report, array $parts): void
    {
        foreach ($parts as $part) {
            $report->parts()->create([
                'item_id' => $part['item_id'],
                'qty' => round((float) $part['qty'], 3),
                'notes' => $part['notes'] ?? null,
            ]);
        }
    }

    private function assertEditable(FieldReport $report): void
    {
        if (! $report->status->isEditable()) {
            throw new LogicException("Field report {$report->code} is {$report->status->value} and can no longer be edited.");
        }
    }
}
