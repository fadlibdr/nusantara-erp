<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\NotificationService;
use Modules\Crm\Models\ContractTermin;
use Modules\Projects\Models\Milestone;

/**
 * Milestones, and the one thing a billing milestone is FOR: releasing a termin.
 *
 * The live data is the whole argument for this class existing. "Progres fisik
 * 50% — syarat penagihan Termin 2" was ticked achieved on 27-03-2026. The termin
 * it releases is worth Rp 14,55 miliar. Four months later billed_at was still
 * NULL. Nothing was broken and nobody was negligent — there was simply no wire
 * between the project manager who ticks the box and the finance staff who raise
 * the invoice, so the handoff lived in a WhatsApp group and one message was
 * never sent.
 *
 * The alert is deliberately quiet, because an alert nobody reads is worth less
 * than none:
 *
 *  - ONLY ON THE TRANSITION null→achieved. Renaming a milestone, correcting its
 *    notes or moving its due date after the fact must stay silent; the news
 *    already broke.
 *  - ONLY WHEN A TERMIN HANGS OFF IT. Most milestones are schedule markers with
 *    no money attached and no reason to reach finance at all.
 *  - NEVER FOR A TERMIN ALREADY BILLED. Somebody got there first — usually
 *    because the invoice was raised before the milestone was recorded — and
 *    telling finance to bill what they have billed teaches them to stop reading.
 */
class MilestoneService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function create(array $data): Milestone
    {
        $milestone = DB::transaction(fn (): Milestone => Milestone::query()->create($data));

        // Created already achieved — backfilling a milestone that was reached
        // before anyone entered it. That is still the first time the system
        // learns the termin is billable, so it is still news.
        if ($milestone->achieved_date !== null) {
            $this->announceBillableTermin($milestone);
        }

        return $milestone;
    }

    public function update(Milestone $milestone, array $data): Milestone
    {
        $wasAchieved = $milestone->achieved_date !== null;

        DB::transaction(function () use ($milestone, $data): void {
            $milestone->fill($data)->save();
        });

        if (! $wasAchieved && $milestone->achieved_date !== null) {
            $this->announceBillableTermin($milestone);
        }

        return $milestone->refresh();
    }

    public function delete(Milestone $milestone): void
    {
        $milestone->delete();
    }

    /**
     * Tell whoever raises invoices that a termin just became billable.
     *
     * fin.create is the permission that actually creates an AR invoice, so it is
     * the smallest audience that can act on this. The rupiah value goes in the
     * TITLE, not only the body: the title is what the bell shows and what
     * NotificationService deduplicates on, so it has to be both distinct per
     * termin and worth acting on at a glance.
     */
    private function announceBillableTermin(Milestone $milestone): void
    {
        if ($milestone->termin_id === null) {
            return;
        }

        $termin = ContractTermin::query()->with('contract')->find($milestone->termin_id);

        if ($termin === null || $termin->isBilled() || $termin->contract === null) {
            return;
        }

        $contract = $termin->contract;
        $value = 'Rp '.number_format((float) $termin->amount, 0, ',', '.');

        $this->notifications->system(
            'fin.create',
            "Termin {$termin->termin_no} kontrak {$contract->code} siap ditagih — {$value}",
            sprintf(
                'Milestone "%s" tercapai pada %s. Termin %d (%s) kontrak %s — %s — senilai %s sudah memenuhi syarat penagihan dan belum ditagihkan.',
                $milestone->name,
                $milestone->achieved_date->format('d-m-Y'),
                $termin->termin_no,
                $termin->name,
                $contract->code,
                $contract->title,
                $value,
            ),
            "#/d/crm/contracts/{$contract->id}",
        );
    }
}
