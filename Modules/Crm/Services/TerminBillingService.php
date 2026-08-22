<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\ContractTermin;

/**
 * Antrean "siap tagih" — the PM→Finance handoff, moved inside the application.
 *
 * The handoff had no home here. A project manager ticks a milestone achieved; a
 * finance staffer raises the invoice; between the two there was a WhatsApp
 * message, and the live data records what happens when it is not sent. Milestone
 * "Progres fisik 50% — syarat penagihan Termin 2" was achieved on 27-03-2026.
 * The termin it releases is worth Rp 14,55 miliar. Four months later billed_at
 * was still NULL, and nothing anywhere in the system was able to say so.
 *
 * Two things make a termin billable, and they are not the same thing:
 *
 *  - MILESTONE. Somebody certified the physical progress the contract names as
 *    the condition. The trigger date is when it was achieved.
 *  - JADWAL. A calendar termin (maintenance, retainer) came due because a
 *    quarter ended. The trigger date is crm_contract_termins.due_date. This is
 *    the case a milestone cannot cover, and the case that lost a whole quarter
 *    of CTR/2026/III/0003.
 *
 * WHEN BOTH APPLY, THE EARLIER DATE WINS. The queue is sorted by how long the
 * money has been waiting, so the trigger has to be the moment it first became
 * billable — taking the later of the two would quietly reset the clock on the
 * oldest debt in the list, which is the one item the queue exists to surface.
 *
 * Only approved contracts are read. A draft's termin schedule is still being
 * negotiated and its amounts mean nothing yet; a cancelled contract is owed
 * nothing at all.
 */
class TerminBillingService
{
    /** Released by a certified milestone. */
    public const REASON_MILESTONE = 'milestone';

    /** Came due by the calendar (kontrak pemeliharaan / retainer). */
    public const REASON_SCHEDULE = 'jadwal';

    /**
     * Termins that may be invoiced today but have not been.
     *
     * KOPLING LINTAS MODUL (Finance): CashFlowService::projectTermins()
     * consumes this READ-ONLY (class_exists-guarded — it degrades to an empty
     * termin lane if this class vanishes) and expects each row to carry
     * `termin_id` and `amount`; it also reads crm_contract_termins.due_date
     * and billed_at directly via DB::table. Renaming those keys/columns or
     * changing this signature needs a matching touch on the Finance
     * projection — it will degrade or misreport rather than 500, so the
     * coupling is easy to miss.
     *
     * @return array<int, array<string, mixed>> longest-waiting first
     */
    public function billingReady(?string $asOf = null, ?int $contractId = null): array
    {
        $today = ($asOf === null ? Carbon::now() : Carbon::parse($asOf))->startOfDay();
        $achieved = $this->achievedMilestones();

        $termins = ContractTermin::query()
            ->join('crm_contracts', 'crm_contracts.id', '=', 'crm_contract_termins.contract_id')
            ->leftJoin('crm_customers', 'crm_customers.id', '=', 'crm_contracts.customer_id')
            ->whereNull('crm_contracts.deleted_at')
            ->whereIn('crm_contracts.status', $this->billableContractStatuses())
            ->whereNull('crm_contract_termins.billed_at')
            ->when($contractId !== null, fn ($query) => $query->where('crm_contract_termins.contract_id', $contractId))
            ->where(function ($where) use ($achieved, $today): void {
                $where->whereIn('crm_contract_termins.id', $achieved->keys()->all())
                    // whereDate, not a raw comparison: a `date` column written
                    // through Eloquent lands in SQLite as "2026-06-30 00:00:00",
                    // and a string compare against "2026-06-30" would drop every
                    // termin falling due exactly today.
                    ->orWhere(fn ($due) => $due
                        ->whereNotNull('crm_contract_termins.due_date')
                        ->whereDate('crm_contract_termins.due_date', '<=', $today->toDateString()));
            })
            ->select([
                'crm_contract_termins.*',
                'crm_contracts.code as contract_code',
                'crm_contracts.title as contract_title',
                'crm_customers.name as customer_name',
            ])
            ->get();

        $rows = $termins->map(function (ContractTermin $termin) use ($achieved, $today): array {
            $milestone = $achieved->get($termin->id);
            $milestoneDate = $milestone === null ? null : Carbon::parse($milestone->achieved_date)->startOfDay();
            $dueDate = $termin->isDue($today) ? $termin->due_date->copy()->startOfDay() : null;

            [$reason, $trigger] = $milestoneDate !== null && ($dueDate === null || $milestoneDate->lte($dueDate))
                ? [self::REASON_MILESTONE, $milestoneDate]
                : [self::REASON_SCHEDULE, $dueDate];

            return [
                'termin_id' => (int) $termin->id,
                'contract_id' => (int) $termin->contract_id,
                'contract_code' => $termin->contract_code,
                'contract_title' => $termin->contract_title,
                'customer_name' => $termin->customer_name,
                'termin_no' => (int) $termin->termin_no,
                'termin_name' => $termin->name,
                'amount' => round((float) $termin->amount, 2),
                'reason' => $reason,
                'trigger_date' => $trigger->toDateString(),
                'days_waiting' => max(0, (int) $trigger->diffInDays($today)),
                'milestone_name' => $reason === self::REASON_MILESTONE ? $milestone->name : null,
                'billing_condition' => $termin->billing_condition,
                'link' => "#/d/crm/contracts/{$termin->contract_id}",
            ];
        });

        // Oldest debt on top. Value breaks the tie so that two termins released
        // on the same day do not swap places between two calls.
        return $rows
            ->sortByDesc(fn (array $row): array => [$row['days_waiting'], $row['amount']])
            ->values()
            ->all();
    }

    /**
     * Move the planned billing date of a single termin.
     *
     * ContractService replaces a schedule wholesale and refuses once ANY termin
     * on it is billed — correct, because those amounts are the basis of invoices
     * already raised. The side effect is that a maintenance contract, whose
     * first quarter is billed almost immediately, could never afterwards record
     * when its remaining quarters fall due — exactly the contract this column
     * exists for. This is the narrow way in: it moves a date, never an amount,
     * and never on a termin that has already been invoiced.
     */
    public function reschedule(ContractTermin $termin, array $data): ContractTermin
    {
        if ($termin->isBilled()) {
            throw new LogicException(
                "Termin {$termin->termin_no} sudah ditagihkan pada {$termin->billed_at->format('d-m-Y')} dan jadwalnya tidak dapat diubah lagi.",
            );
        }

        return DB::transaction(function () use ($termin, $data): ContractTermin {
            $termin->fill(array_intersect_key($data, array_flip(['due_date', 'billing_condition'])))->save();

            return $termin->refresh();
        });
    }

    /**
     * Earliest achieved milestone per termin, keyed by termin id.
     *
     * prj_milestones is read as a table rather than through
     * Modules\Projects\Models\Milestone on purpose: this is CRM reading one
     * project fact for a CRM question, and it should not make the CRM module
     * depend on the Projects module at runtime to answer it.
     *
     * @return Collection<int, object>
     */
    private function achievedMilestones(): Collection
    {
        return DB::table('prj_milestones')
            ->select('termin_id', 'name', 'achieved_date')
            ->whereNotNull('termin_id')
            ->whereNotNull('achieved_date')
            ->orderBy('achieved_date')
            ->get()
            ->groupBy('termin_id')
            // Several milestones may release the same termin; the first one
            // achieved is when the money became billable.
            ->map(fn (Collection $rows): object => $rows->first());
    }

    /**
     * @return array<int, string>
     */
    private function billableContractStatuses(): array
    {
        // A CRM contract has no separate "active" state: activate() moves it to
        // approved, and that is the state it works under until it is closed.
        return [DocumentStatus::Approved->value];
    }
}
