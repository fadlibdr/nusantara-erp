<?php

namespace Modules\ServiceDesk\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\HrPayroll\Models\Employee;
use Modules\ServiceDesk\Enums\TicketStatus;
use Modules\ServiceDesk\Models\ContractSite;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Models\TicketActivity;

class TicketService
{
    public function __construct(private readonly SlaService $slaService) {}

    public function create(array $data, ?int $userId = null): Ticket
    {
        return DB::transaction(function () use ($data, $userId): Ticket {
            $ticket = new Ticket(Arr::except($data, ['code', 'status']));
            $ticket->reported_at = $ticket->reported_at ?: now();
            $ticket->channel = $ticket->channel ?: 'phone';

            $contract = $ticket->service_contract_id
                ? ServiceContract::query()->find($ticket->service_contract_id)
                : null;

            if ($contract && ! $ticket->customer_id) {
                $ticket->customer_id = $contract->customer_id;
            }

            $this->assertSiteBelongsToContract($ticket);

            $ticket->status = $ticket->assigned_to ? TicketStatus::Assigned : TicketStatus::Open;
            $this->applySlaDueDates($ticket);
            $ticket->save(); // HasDocumentNumber fills the TKT code

            if ($ticket->assigned_to) {
                $this->recordAssignmentActivity($ticket, (int) $ticket->assigned_to, $userId);
            }

            return $ticket->load('serviceContract', 'site');
        });
    }

    public function update(Ticket $ticket, array $data): Ticket
    {
        if ($ticket->status->isFinal() || $ticket->status === TicketStatus::Resolved) {
            throw new LogicException("Ticket {$ticket->code} is {$ticket->status->value} and can no longer be edited.");
        }

        return DB::transaction(function () use ($ticket, $data): Ticket {
            $ticket->fill(Arr::except($data, [
                'code', 'status', 'first_response_at', 'resolved_at', 'closed_at', 'assigned_to',
            ]));

            $this->assertSiteBelongsToContract($ticket);

            // SLA inputs changed => the due dates move with them.
            if ($ticket->isDirty(['service_contract_id', 'priority', 'reported_at'])) {
                $ticket->unsetRelation('serviceContract');
                $this->applySlaDueDates($ticket);
            }

            $ticket->save();

            return $ticket->load('serviceContract', 'site');
        });
    }

    public function delete(Ticket $ticket): void
    {
        if (! in_array($ticket->status, [TicketStatus::Open, TicketStatus::Cancelled], true)) {
            throw new LogicException("Ticket {$ticket->code} is {$ticket->status->value}; only open or cancelled tickets can be deleted.");
        }

        $ticket->delete();
    }

    public function assign(Ticket $ticket, int $employeeId, ?int $userId = null): Ticket
    {
        if ($ticket->status->isFinal() || $ticket->status === TicketStatus::Resolved) {
            throw new LogicException("Ticket {$ticket->code} is {$ticket->status->value} and can no longer be assigned.");
        }

        return DB::transaction(function () use ($ticket, $employeeId, $userId): Ticket {
            $ticket->assigned_to = $employeeId;

            if ($ticket->status === TicketStatus::Open) {
                $ticket->status = TicketStatus::Assigned;
            }

            $ticket->save();
            $this->recordAssignmentActivity($ticket, $employeeId, $userId);

            return $ticket;
        });
    }

    /**
     * Log an activity. The first substantive entry (comment/work log) once the
     * ticket has an assignee stamps first_response_at — the SLA response mark.
     * A first work log also flips an assigned ticket to in_progress.
     */
    public function addActivity(Ticket $ticket, array $data, ?int $userId = null): TicketActivity
    {
        if ($ticket->status->isFinal()) {
            throw new LogicException("Ticket {$ticket->code} is {$ticket->status->value}; activities can no longer be added.");
        }

        return DB::transaction(function () use ($ticket, $data, $userId): TicketActivity {
            $activity = $ticket->activities()->create([
                'user_id' => $userId,
                'activity_type' => $data['activity_type'],
                'body' => $data['body'],
                'minutes_spent' => $data['minutes_spent'] ?? null,
            ]);

            $changes = [];

            $isSubstantive = in_array($activity->activity_type, [
                TicketActivity::TYPE_COMMENT,
                TicketActivity::TYPE_WORK_LOG,
            ], true);

            if ($isSubstantive && $ticket->assigned_to !== null && $ticket->first_response_at === null) {
                $changes['first_response_at'] = $activity->created_at ?? now();
            }

            if ($activity->activity_type === TicketActivity::TYPE_WORK_LOG
                && $ticket->status === TicketStatus::Assigned) {
                $changes['status'] = TicketStatus::InProgress;
            }

            if ($changes !== []) {
                $ticket->forceFill($changes)->save();
            }

            return $activity;
        });
    }

    public function resolve(Ticket $ticket, string $resolutionNotes, ?int $userId = null): Ticket
    {
        $this->assertTransition($ticket, TicketStatus::Resolved);

        return DB::transaction(function () use ($ticket, $resolutionNotes, $userId): Ticket {
            $now = now();

            $ticket->forceFill([
                'status' => TicketStatus::Resolved,
                'resolved_at' => $now,
                'resolution_notes' => $resolutionNotes,
                // Resolving without any prior logged response counts as the response.
                'first_response_at' => $ticket->first_response_at ?? $now,
            ])->save();

            $ticket->activities()->create([
                'user_id' => $userId,
                'activity_type' => TicketActivity::TYPE_STATUS_CHANGE,
                'body' => 'Tiket diselesaikan (resolved). '.$resolutionNotes,
            ]);

            return $ticket;
        });
    }

    public function close(Ticket $ticket, ?int $userId = null): Ticket
    {
        $this->assertTransition($ticket, TicketStatus::Closed);

        return DB::transaction(function () use ($ticket, $userId): Ticket {
            $ticket->forceFill([
                'status' => TicketStatus::Closed,
                'closed_at' => now(),
            ])->save();

            $ticket->activities()->create([
                'user_id' => $userId,
                'activity_type' => TicketActivity::TYPE_STATUS_CHANGE,
                'body' => 'Tiket ditutup (closed).',
            ]);

            return $ticket;
        });
    }

    /**
     * Tickets currently in breach of their SLA: response overdue with no first
     * response, or resolution overdue and still unresolved. Closed/cancelled
     * tickets are history, not breaches.
     */
    public function slaBreaches(): Builder
    {
        $now = now();

        return Ticket::query()
            ->whereNotIn('status', [TicketStatus::Closed->value, TicketStatus::Cancelled->value])
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $query) use ($now): void {
                    $query->whereNull('first_response_at')
                        ->whereNotNull('response_due_at')
                        ->where('response_due_at', '<', $now);
                })->orWhere(function (Builder $query) use ($now): void {
                    $query->whereNull('resolved_at')
                        ->whereNotNull('resolution_due_at')
                        ->where('resolution_due_at', '<', $now);
                });
            });
    }

    private function applySlaDueDates(Ticket $ticket): void
    {
        $dueDates = $this->slaService->computeDueDates($ticket);

        $ticket->response_due_at = $dueDates['response_due_at'];
        $ticket->resolution_due_at = $dueDates['resolution_due_at'];
    }

    private function assertSiteBelongsToContract(Ticket $ticket): void
    {
        if (! $ticket->site_id) {
            return;
        }

        $site = ContractSite::query()->find($ticket->site_id);

        if (! $site) {
            throw new LogicException('The selected site does not exist.');
        }

        if ($ticket->service_contract_id
            && (int) $site->service_contract_id !== (int) $ticket->service_contract_id) {
            throw new LogicException('The selected site does not belong to the selected service contract.');
        }
    }

    private function recordAssignmentActivity(Ticket $ticket, int $employeeId, ?int $userId): void
    {
        $employeeName = Employee::query()->whereKey($employeeId)->value('name');

        $ticket->activities()->create([
            'user_id' => $userId,
            'activity_type' => TicketActivity::TYPE_ASSIGNMENT,
            'body' => 'Tiket ditugaskan kepada '.($employeeName ?? "karyawan #{$employeeId}").'.',
        ]);
    }

    private function assertTransition(Ticket $ticket, TicketStatus $to): void
    {
        if (! $ticket->status->canTransitionTo($to)) {
            throw new LogicException(
                "Ticket {$ticket->code} cannot move from {$ticket->status->value} to {$to->value}."
            );
        }
    }
}
