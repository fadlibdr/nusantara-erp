<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Enums\PettyCashCategory;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\PettyCashFund;

/**
 * Kasbon: cash advanced to an employee out of the drawer, accounted for later
 * with receipts (pertanggungjawaban).
 *
 * ISSUE BOOKS A RECEIVABLE, NEVER A COST. Dr 1-1370 Piutang Karyawan /
 * Cr fund: nothing has been bought yet, so nothing may reach the project cost
 * ledger or the PSAK 115 percentage — that would recognise cost on money that
 * might come back untouched. Cost recognition happens at settlement, per
 * receipt line, which is the honest PSAK position.
 *
 * ONE SETTLEMENT, IN ONE TRANSACTION. draft -> issued -> settled with exactly
 * one pertanggungjawaban (the site norm): lines, journal, project cost rows
 * and status all commit together, so a settled kasbon can never dangle
 * half-booked — which is why DanglingDocuments only scans DRAFT kasbon.
 * A returned untouched advance is a settlement with zero lines and full cash
 * back; there is no separate cancel path to guard.
 */
class KasbonService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly PettyCashFundService $funds,
        private readonly ProjectCostService $projectCosts,
    ) {}

    public function create(array $data, User $by): Kasbon
    {
        return DB::transaction(function () use ($data, $by): Kasbon {
            $fund = PettyCashFund::query()->findOrFail((int) $data['fund_id']);

            $this->assertAmountPositive((float) $data['amount']);
            $this->assertEmployeeExists((int) $data['employee_id']);

            $kasbon = new Kasbon(Arr::only($data, [
                'fund_id', 'employee_id', 'advance_date', 'amount', 'purpose', 'due_date',
            ]));
            // Site advances almost always belong to the site's project; a
            // blank project on the form means "the fund's own".
            $kasbon->project_id = $data['project_id'] ?? $fund->project_id;
            $kasbon->status = KasbonStatus::Draft;
            $kasbon->created_by = $by->id;
            $kasbon->save(); // HasDocumentNumber fills the KSB code

            return $kasbon->refresh();
        });
    }

    public function update(Kasbon $kasbon, array $data): Kasbon
    {
        $this->assertDraft($kasbon);

        if (array_key_exists('amount', $data)) {
            $this->assertAmountPositive((float) $data['amount']);
        }

        if (array_key_exists('employee_id', $data)) {
            $this->assertEmployeeExists((int) $data['employee_id']);
        }

        $kasbon->fill(Arr::only($data, [
            'employee_id', 'advance_date', 'amount', 'purpose', 'project_id', 'due_date',
        ]))->save();

        return $kasbon->refresh();
    }

    public function delete(Kasbon $kasbon): void
    {
        $this->assertDraft($kasbon);

        $kasbon->delete();
    }

    /**
     * Hand the cash over: Dr 1-1370, Cr fund. Custodian-only, capped, and ONE
     * outstanding kasbon per employee per fund — the standard site rule:
     * account for the last advance before asking for the next.
     */
    public function issue(Kasbon $kasbon, User $by): Kasbon
    {
        return DB::transaction(function () use ($kasbon, $by): Kasbon {
            // lockForUpdate() is a no-op on SQLite; the status re-read is the
            // actual double-issue protection.
            /** @var Kasbon $kasbon */
            $kasbon = Kasbon::query()->whereKey($kasbon->id)->lockForUpdate()->firstOrFail();

            $this->assertDraft($kasbon);

            $fund = PettyCashFund::query()->findOrFail($kasbon->fund_id);

            $this->assertCustodian($fund, $by, 'mencairkan kasbon');
            $this->assertFundActive($fund);

            if ($fund->max_kasbon_amount !== null
                && $this->cents((float) $kasbon->amount - (float) $fund->max_kasbon_amount) > 0) {
                throw new LogicException(
                    "Kasbon {$kasbon->code} sebesar {$kasbon->amount} melebihi batas per kasbon "
                    ."{$fund->max_kasbon_amount} pada kas kecil {$fund->code}."
                );
            }

            // Bounded by the advance date — the journal's own date — so a
            // back-dated pencairan cannot spend cash the drawer had not yet
            // received on that date (the voucher-post rule, same reasoning).
            $balance = $this->funds->balance($fund, $kasbon->advance_date->toDateString());

            if ($this->cents((float) $kasbon->amount - $balance) > 1) {
                throw new LogicException(
                    "Kasbon {$kasbon->code} sebesar {$kasbon->amount} melebihi saldo laci "
                    ."{$fund->code} per {$kasbon->advance_date->toDateString()} ({$balance}). "
                    .'Isi ulang dananya lebih dulu.'
                );
            }

            $outstanding = Kasbon::query()
                ->where('fund_id', $fund->id)
                ->where('employee_id', $kasbon->employee_id)
                ->where('status', KasbonStatus::Issued->value)
                ->where('id', '!=', $kasbon->id)
                ->first();

            if ($outstanding !== null) {
                throw new LogicException(
                    "Karyawan ini masih membawa kasbon {$outstanding->code} sebesar {$outstanding->amount} "
                    ."yang belum dipertanggungjawabkan pada kas kecil {$fund->code}; selesaikan itu lebih dulu."
                );
            }

            $this->journals->autoPost(
                'kasbon',
                (int) $kasbon->id,
                [
                    [
                        'account_code' => '1-1370',
                        'debit' => (float) $kasbon->amount,
                        'description' => "Kasbon {$kasbon->code} — {$kasbon->purpose}",
                    ],
                    [
                        'account_id' => (int) $fund->coa_account_id,
                        'credit' => (float) $kasbon->amount,
                        'description' => "{$fund->name} — {$kasbon->code}",
                    ],
                ],
                $kasbon->advance_date->toDateString(),
                "Pencairan kasbon {$kasbon->code}",
                (int) $by->id,
            );

            $kasbon->forceFill([
                'status' => KasbonStatus::Issued,
                'issued_at' => now(),
            ])->save();

            return $kasbon->refresh();
        });
    }

    /**
     * The pertanggungjawaban, in one transaction:
     *
     *   Dr beban per receipt line (5-xxxx berproyek / 6-xxxx kantor)
     *   Dr fund   cash returned to the drawer      (when lines < advance)
     *       Cr 1-1370   the FULL advance
     *       Cr fund     extra cash paid out        (when lines > advance)
     *
     * cash_returned = amount − Σ lines is stored (negative = overspend paid
     * out of the drawer at settlement). Project cost rows are written per LINE
     * with reference ('kasbon_line', line id), so two same-category lines on
     * different WBS tasks do not collapse under record()'s idempotency key.
     *
     * @param  array<int, array<string, mixed>>  $lines  [[category, description, amount, project_id?, wbs_task_id?], ...]
     */
    public function settle(Kasbon $kasbon, array $lines, string $settlementDate, User $by): Kasbon
    {
        return DB::transaction(function () use ($kasbon, $lines, $settlementDate, $by): Kasbon {
            /** @var Kasbon $kasbon */
            $kasbon = Kasbon::query()->whereKey($kasbon->id)->lockForUpdate()->firstOrFail();

            if ($kasbon->status !== KasbonStatus::Issued) {
                throw new LogicException(
                    "Kasbon {$kasbon->code} berstatus {$kasbon->status->value}; "
                    .'hanya kasbon yang sudah cair yang dapat dipertanggungjawabkan.'
                );
            }

            $fund = PettyCashFund::query()->findOrFail($kasbon->fund_id);

            $this->assertCustodian($fund, $by, 'menerima pertanggungjawaban kasbon');

            $advance = (float) $kasbon->amount;
            $spent = 0.0;
            $journalLines = [];
            $costRows = [];

            foreach ($lines as $index => $input) {
                $category = PettyCashCategory::tryFrom((string) ($input['category'] ?? ''));

                if ($category === null) {
                    $known = implode(', ', PettyCashCategory::values());

                    throw new LogicException(
                        'Kategori baris pertanggungjawaban tidak dikenal; gunakan salah satu dari: '.$known.'.'
                    );
                }

                $amount = round((float) ($input['amount'] ?? 0), 2);

                if ($amount <= 0) {
                    throw new LogicException('Nilai setiap baris pertanggungjawaban harus lebih besar dari nol.');
                }

                $description = trim((string) ($input['description'] ?? ''));

                if ($description === '') {
                    throw new LogicException('Setiap baris pertanggungjawaban harus menyebut belanjanya.');
                }

                $projectId = isset($input['project_id']) && $input['project_id'] !== null && $input['project_id'] !== ''
                    ? (int) $input['project_id']
                    : null;
                $wbsTaskId = isset($input['wbs_task_id']) && $input['wbs_task_id'] !== null && $input['wbs_task_id'] !== ''
                    ? (int) $input['wbs_task_id']
                    : null;

                $journalLines[] = [
                    'account_code' => $projectId !== null
                        ? $category->cogsAccountCode()
                        : $category->opexAccountCode(),
                    'debit' => $amount,
                    'description' => $description,
                    'project_id' => $projectId,
                ];

                $costRows[$index] = [
                    'category' => $category,
                    'description' => $description,
                    'amount' => $amount,
                    'project_id' => $projectId,
                    'wbs_task_id' => $wbsTaskId,
                ];

                $spent = round($spent + $amount, 2);
            }

            $cashReturned = round($advance - $spent, 2);

            // Overspend is paid out of the drawer at settlement, so the drawer
            // must actually hold it — the same rule a voucher answers to,
            // bounded by the settlement date its journal will carry.
            if ($cashReturned < 0) {
                $balance = $this->funds->balance($fund, $settlementDate);

                if ($this->cents(-$cashReturned - $balance) > 1) {
                    throw new LogicException(
                        'Belanja pertanggungjawaban '.number_format($spent, 2, ',', '.')
                        ." melebihi kasbon {$kasbon->code} dan kelebihannya ".number_format(-$cashReturned, 2, ',', '.')
                        ." melebihi saldo laci {$fund->code} per {$settlementDate} ({$balance}). "
                        .'Isi ulang dananya lebih dulu.'
                    );
                }
            }

            // Drawer legs: cash back in (Dr) or extra cash out (Cr). Zero legs
            // are dropped by autoPost, so an exact-spend settlement books none.
            $journalLines[] = [
                'account_id' => (int) $fund->coa_account_id,
                'debit' => max($cashReturned, 0.0),
                'description' => "Sisa kasbon {$kasbon->code} kembali ke {$fund->name}",
            ];
            $journalLines[] = [
                'account_id' => (int) $fund->coa_account_id,
                'credit' => max(-$cashReturned, 0.0),
                'description' => "Kekurangan kasbon {$kasbon->code} dibayar dari {$fund->name}",
            ];
            $journalLines[] = [
                'account_code' => '1-1370',
                'credit' => $advance,
                'description' => "Pertanggungjawaban kasbon {$kasbon->code}",
            ];

            // assertPeriodOpen runs inside autoPost, on the settlement date —
            // a settlement discovered today is an event of today.
            $this->journals->autoPost(
                'kasbon_settlement',
                (int) $kasbon->id,
                $journalLines,
                $settlementDate,
                "Pertanggungjawaban kasbon {$kasbon->code}",
                (int) $by->id,
            );

            foreach ($costRows as $row) {
                $line = $kasbon->lines()->create([
                    'category' => $row['category'],
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'project_id' => $row['project_id'],
                    'wbs_task_id' => $row['wbs_task_id'],
                ]);

                if ($row['project_id'] !== null) {
                    $this->projectCosts->record(
                        $row['project_id'],
                        $settlementDate,
                        $row['category']->costCategory(),
                        'kasbon_line',
                        (int) $line->id,
                        $row['description'],
                        $row['amount'],
                        $row['wbs_task_id'],
                    );
                }
            }

            $kasbon->forceFill([
                'status' => KasbonStatus::Settled,
                'settlement_date' => $settlementDate,
                'cash_returned' => $cashReturned,
                'settled_at' => now(),
            ])->save();

            return $kasbon->refresh()->load('lines');
        });
    }

    // ------------------------------------------------------------- guards

    private function assertDraft(Kasbon $kasbon): void
    {
        if ($kasbon->status !== KasbonStatus::Draft) {
            throw new LogicException("Kasbon {$kasbon->code} sudah {$kasbon->status->value}.");
        }
    }

    private function assertAmountPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw new LogicException('Nilai kasbon harus lebih besar dari nol.');
        }
    }

    private function assertEmployeeExists(int $employeeId): void
    {
        $exists = DB::table('hr_employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw new LogicException('Karyawan penerima kasbon tidak ditemukan.');
        }
    }

    private function assertFundActive(PettyCashFund $fund): void
    {
        if (! $fund->is_active) {
            throw new LogicException("Kas kecil {$fund->code} sudah nonaktif dan tidak menerima transaksi baru.");
        }
    }

    /** Strict custodian binding — same reasoning as PettyCashVoucherService. */
    private function assertCustodian(PettyCashFund $fund, User $by, string $action): void
    {
        if ((int) $by->id !== (int) $fund->custodian_id) {
            throw new LogicException(
                "Hanya pemegang kas kecil {$fund->code} yang dapat {$action} — "
                .'uang tunainya ada di laci pemegang, bukan di layar orang lain. '
                .'Bila pemegangnya berganti, ubah dulu pemegang pada data kas kecilnya.'
            );
        }
    }

    /** Whole-cent compare, same reason as JournalService::assertBalanced(). */
    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
