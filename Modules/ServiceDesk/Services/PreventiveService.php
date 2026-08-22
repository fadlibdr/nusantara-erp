<?php

namespace Modules\ServiceDesk\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\ServiceDesk\Enums\ContractStatus;
use Modules\ServiceDesk\Enums\TicketCategory;
use Modules\ServiceDesk\Enums\TicketPriority;
use Modules\ServiceDesk\Models\PreventiveSchedule;

class PreventiveService
{
    public function __construct(private readonly TicketService $ticketService) {}

    /**
     * Create one preventive ticket per schedule whose next_due_date has arrived,
     * then roll next_due_date forward by the schedule frequency. Rolling happens
     * in the same transaction as the ticket, so a re-run (daily cron, manual
     * generate-now) never double-generates the same visit. Schedules missed for
     * several periods roll until they land past the as-of date — one catch-up
     * ticket, not a backlog flood.
     *
     * @return int number of tickets created
     */
    public function generateDueTickets(?CarbonInterface $asOf = null): int
    {
        $asOf = Carbon::parse($asOf ?? today())->startOfDay();
        $created = 0;

        $schedules = PreventiveSchedule::query()
            ->where('is_active', true)
            ->whereDate('next_due_date', '<=', $asOf)
            ->with('contract')
            ->orderBy('id')
            ->get();

        foreach ($schedules as $schedule) {
            $contract = $schedule->contract;

            // No PM visits for missing, expired, or terminated contracts.
            if (! $contract || $contract->status !== ContractStatus::Active) {
                continue;
            }

            DB::transaction(function () use ($schedule, $contract, $asOf): void {
                $dueDate = Carbon::parse($schedule->next_due_date);

                $this->ticketService->create([
                    'service_contract_id' => $schedule->service_contract_id,
                    'customer_id' => $contract->customer_id,
                    'site_id' => $schedule->site_id,
                    'title' => $schedule->name.' — '.$dueDate->format('d/m/Y'),
                    'description' => $this->checklistBody($schedule),
                    'category' => TicketCategory::Preventive->value,
                    'priority' => TicketPriority::Low->value,
                    'channel' => 'system',
                    'reported_by_name' => 'Jadwal PM otomatis',
                    'reported_at' => now(),
                    'assigned_to' => $schedule->assigned_to,
                ]);

                $intervalMonths = $schedule->frequency->months();
                $next = $dueDate->copy();

                while ($next->lte($asOf)) {
                    $next = $next->addMonthsNoOverflow($intervalMonths);
                }

                $schedule->forceFill(['next_due_date' => $next->toDateString()])->save();
            });

            $created++;
        }

        return $created;
    }

    private function checklistBody(PreventiveSchedule $schedule): ?string
    {
        $checklist = $schedule->checklist ?? [];

        if ($checklist === []) {
            return null;
        }

        return "Checklist PM:\n- ".implode("\n- ", $checklist);
    }
}
