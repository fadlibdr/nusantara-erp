<?php

namespace Modules\ServiceDesk\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\ServiceDesk\Enums\TicketPriority;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Models\Ticket;

class SlaService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const WORKDAY_START_HOUR = 8;  // 08:00 WIB

    public const WORKDAY_END_HOUR = 17;   // 17:00 WIB

    /**
     * Compute response/resolution due moments from the ticket's contract SLA.
     *
     * Critical tickets run on the 24/7 wall clock; all other priorities count
     * business hours only (Mon-Fri 08:00-17:00 Asia/Jakarta). Tickets without
     * a maintenance contract carry no SLA.
     *
     * @return array{response_due_at: ?Carbon, resolution_due_at: ?Carbon}
     */
    public function computeDueDates(Ticket $ticket): array
    {
        $contract = $ticket->service_contract_id
            ? ($ticket->serviceContract ?? ServiceContract::query()->find($ticket->service_contract_id))
            : null;

        if (! $contract || ! $ticket->reported_at) {
            return ['response_due_at' => null, 'resolution_due_at' => null];
        }

        $start = Carbon::parse($ticket->reported_at);

        $priority = $ticket->priority instanceof TicketPriority
            ? $ticket->priority
            : TicketPriority::tryFrom((string) $ticket->priority);

        if ($priority?->usesClockHours()) {
            return [
                'response_due_at' => $start->copy()->addHours((int) $contract->sla_response_hours),
                'resolution_due_at' => $start->copy()->addHours((int) $contract->sla_resolution_hours),
            ];
        }

        return [
            'response_due_at' => $this->addBusinessHours($start, (float) $contract->sla_response_hours),
            'resolution_due_at' => $this->addBusinessHours($start, (float) $contract->sla_resolution_hours),
        ];
    }

    /**
     * Advance a moment by N working hours (Mon-Fri 08:00-17:00 Asia/Jakarta),
     * skipping weekends. A start outside the working window first snaps to the
     * next window opening, then hours are consumed window by window. The result
     * is returned in the caller's original timezone.
     */
    public function addBusinessHours(CarbonInterface $start, float $hours): Carbon
    {
        $originalTimezone = $start->getTimezone();
        $current = Carbon::parse($start)->setTimezone(self::TIMEZONE);
        $remaining = (int) round($hours * 60); // work in whole minutes

        $current = $this->snapToBusinessWindow($current);

        while ($remaining > 0) {
            $windowEnd = $current->copy()->setTime(self::WORKDAY_END_HOUR, 0, 0);
            // Timestamp arithmetic avoids Carbon 2/3 diff sign differences.
            $available = intdiv($windowEnd->getTimestamp() - $current->getTimestamp(), 60);

            if ($available <= 0) {
                $current = $this->snapToBusinessWindow(
                    $current->copy()->addDay()->setTime(self::WORKDAY_START_HOUR, 0, 0)
                );

                continue;
            }

            $used = min($available, $remaining);
            $current = $current->addMinutes($used);
            $remaining -= $used;

            if ($remaining > 0) {
                // Window exhausted: continue at the next working day's opening.
                $current = $this->snapToBusinessWindow(
                    $current->copy()->addDay()->setTime(self::WORKDAY_START_HOUR, 0, 0)
                );
            }
        }

        return $current->setTimezone($originalTimezone);
    }

    /**
     * Move a moment to the nearest working instant at or after it:
     * weekends roll to Monday 08:00, before-hours snaps to 08:00 the same day,
     * after-hours rolls to the next working day's 08:00.
     */
    private function snapToBusinessWindow(Carbon $moment): Carbon
    {
        $moment = $moment->copy();

        while (true) {
            if ($moment->isWeekend()) {
                $moment = $moment->addDay()->setTime(self::WORKDAY_START_HOUR, 0, 0);

                continue;
            }

            if ($moment->lt($moment->copy()->setTime(self::WORKDAY_START_HOUR, 0, 0))) {
                return $moment->setTime(self::WORKDAY_START_HOUR, 0, 0);
            }

            if ($moment->gte($moment->copy()->setTime(self::WORKDAY_END_HOUR, 0, 0))) {
                $moment = $moment->addDay()->setTime(self::WORKDAY_START_HOUR, 0, 0);

                continue;
            }

            return $moment;
        }
    }
}
