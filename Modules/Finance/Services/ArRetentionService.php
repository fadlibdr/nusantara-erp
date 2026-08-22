<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Models\ArRetention;
use Modules\Finance\Models\BankAccount;

/**
 * Releasing retention withheld by a customer.
 *
 * ArInvoiceService withheld it correctly — debit 1-1350 Piutang Retensi, a row
 * in fin_ar_retentions with released = false — and then nothing in the entire
 * codebase ever set released = true. There was no route, no screen, no service.
 * 1-1350 was only ever debited, so its balance grew for the life of the
 * installation and could never be cleared.
 *
 * Release here means THE MONEY ARRIVED. Retention becomes collectible after the
 * warranty period (BAST II), and when the customer pays it:
 *
 *   Dr 1-12xx Bank            amount
 *       Cr 1-1350 Piutang Retensi
 *
 * It does not go through PaymentService, which settles invoices: retention is a
 * receivable created by a journal, not by an invoice, and there is no invoice
 * for it to allocate against. What it does share with PaymentService is the
 * bank COA, so a released retention appears in bank reconciliation like any
 * other receipt.
 */
class ArRetentionService
{
    private const RETENTION_RECEIVABLE_ACCOUNT = '1-1350';

    public function __construct(private readonly JournalService $journals) {}

    /**
     * Retention still held, with when it becomes collectible.
     *
     * The due date comes from the project's BAST — ProjectService already
     * computes prj_bast.retention_release_due as handover + warranty months,
     * and until now nothing read it. Retention with no BAST yet has no due date
     * rather than a guessed one.
     */
    public function outstanding(?int $projectId = null): array
    {
        $rows = ArRetention::query()
            ->with(['contract:id,code,customer_id', 'project:id,code,name', 'sourceInvoice:id,code,invoice_date'])
            ->where('released', false)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('project_id')
            ->get();

        $dueByProject = DB::table('prj_bast')
            ->whereIn('project_id', $rows->pluck('project_id')->filter()->unique())
            ->whereNotNull('retention_release_due')
            ->orderBy('retention_release_due')
            ->pluck('retention_release_due', 'project_id');

        /*
         * Tanggalnya boleh datang dari BAST mana pun, tetapi TAGIH baru sah
         * setelah BAST II disetujui: pencairan retensi bersyarat serah terima
         * akhir, dan sebuah BAST I draf yang mencantumkan tanggal bukan bukti
         * proyek pernah diserahkan kembali. Tanpa saringan ini is_due menyala
         * untuk Rp 2.425.000.000 retensi kontrak 1 atas dasar draf semata.
         */
        $handedBack = DB::table('prj_bast')
            ->whereIn('project_id', $rows->pluck('project_id')->filter()->unique())
            ->where('bast_type', 'bast2')
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->pluck('id', 'project_id');

        $today = Carbon::today()->toDateString();
        $total = 0.0;

        $items = $rows->map(function (ArRetention $retention) use ($dueByProject, $handedBack, $today, &$total): array {
            $due = $dueByProject[$retention->project_id] ?? null;
            $due = $due === null ? null : substr((string) $due, 0, 10);
            $total += (float) $retention->amount;

            return [
                'id' => $retention->id,
                'project' => $retention->project?->code,
                'project_name' => $retention->project?->name,
                'contract' => $retention->contract?->code,
                'source_invoice' => $retention->sourceInvoice?->code,
                'amount' => (float) $retention->amount,
                'due_date' => $due,
                // The whole point of surfacing this: nobody was being told when
                // retention became collectible, so nobody chased it. Due needs
                // BOTH the date passing and the project really handed back
                // (BAST II approved) — see the lookup above.
                'is_due' => $due !== null && $due <= $today && isset($handedBack[$retention->project_id]),
            ];
        })->values()->all();

        return [
            'as_of' => $today,
            'total_outstanding' => round($total, 2),
            'due_now' => round(array_sum(array_map(
                fn (array $row): float => $row['is_due'] ? $row['amount'] : 0.0,
                $items,
            )), 2),
            'rows' => $items,
        ];
    }

    /**
     * Record the customer paying released retention.
     */
    public function release(ArRetention $retention, string $receivedOn, int $bankAccountId, ?int $userId = null): ArRetention
    {
        return DB::transaction(function () use ($retention, $receivedOn, $bankAccountId, $userId): ArRetention {
            /** @var ArRetention $retention */
            $retention = ArRetention::query()->whereKey($retention->id)->lockForUpdate()->firstOrFail();

            if ($retention->released) {
                throw new LogicException(
                    "Retensi ini sudah dicairkan pada {$retention->released_at?->toDateString()}."
                );
            }

            $amount = round((float) $retention->amount, 2);

            if ($amount <= 0) {
                throw new LogicException('Nilai retensi nol; tidak ada yang dapat dicairkan.');
            }

            $bank = BankAccount::query()->with('coaAccount')->findOrFail($bankAccountId);

            $this->journals->autoPost(
                'ar_retention',
                (int) $retention->id,
                [
                    [
                        'account_id' => (int) $bank->coa_account_id,
                        'debit' => $amount,
                        'description' => "Pencairan retensi — {$bank->name}",
                        'project_id' => $retention->project_id,
                    ],
                    [
                        'account_code' => self::RETENTION_RECEIVABLE_ACCOUNT,
                        'credit' => $amount,
                        'description' => 'Pelunasan piutang retensi',
                        'project_id' => $retention->project_id,
                    ],
                ],
                $receivedOn,
                sprintf(
                    'Pencairan retensi %s%s',
                    $retention->project?->code ?? '',
                    $retention->sourceInvoice?->code ? " — {$retention->sourceInvoice->code}" : '',
                ),
                $userId,
            );

            $retention->forceFill([
                'released' => true,
                'released_at' => $receivedOn,
            ])->save();

            return $retention->refresh();
        });
    }
}
