<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Support\Erp;
use Modules\Projects\Enums\IncidentSeverity;
use Modules\Projects\Enums\IncidentStatus;
use Modules\Projects\Models\SafetyIncident;

/**
 * Register kecelakaan kerja (SMK3), and the monthly K3 report drawn from it.
 *
 * Before this the whole safety surface was prj_daily_reports.safety_notes, one
 * free-text column. The seeded near-miss — "material jatuh dari lantai 5" — is
 * exactly the event PP 50/2012 and Permen PUPR 10/2021 require a contractor to
 * record, investigate and follow up, and it sat in prose with no severity, no
 * cause, no corrective action, nobody accountable and no closing date.
 *
 * Two things here are worth reading before changing anything:
 *
 * CLOSING IS GUARDED. An incident cannot be closed without a root cause and a
 * corrective action. A register that can be emptied by marking things done is a
 * register that reports zero open items and teaches nothing — which is precisely
 * the failure mode a safety audit looks for.
 *
 * THE RATES ARE THE POINT. Frequency and severity rates are what a client's HSE
 * officer asks for monthly and what a prequalification (CSMS) is scored on:
 *
 *     FR = jumlah kecelakaan tercatat × 1.000.000 / jam kerja orang
 *     SR = hari kerja hilang         × 1.000.000 / jam kerja orang
 *
 * Man-hours come from the daily reports' manpower_count — the only headcount the
 * system actually observes — times the configured working hours per day. That is
 * an approximation and is labelled as one in the payload: a site that files its
 * daily reports late reports a worse rate than it earned, and a site that files
 * none reports no rate at all rather than a flattering infinity.
 */
class SafetyIncidentService
{
    /** The conventional base for both rates: per million man-hours. */
    private const RATE_BASE = 1_000_000;

    public function create(array $data, ?User $by = null): SafetyIncident
    {
        $incident = new SafetyIncident($data);
        $incident->status = IncidentStatus::Open;
        $incident->created_by = $by?->id;
        $incident->save();

        return $incident->refresh();
    }

    public function update(SafetyIncident $incident, array $data): SafetyIncident
    {
        if ($incident->status->isClosed()) {
            throw new LogicException('Insiden yang sudah ditutup tidak dapat diubah. Buka kembali lebih dulu bila ada koreksi.');
        }

        $incident->fill($data)->save();

        return $incident->refresh();
    }

    /**
     * Close out an incident.
     *
     * Refused without a root cause and a corrective action, and without somebody
     * accountable for it. Those three fields are the whole difference between a
     * register and a list of things that went wrong.
     */
    public function close(SafetyIncident $incident, ?User $by = null, ?string $closedOn = null): SafetyIncident
    {
        if ($incident->status->isClosed()) {
            throw new LogicException('Insiden ini sudah ditutup.');
        }

        $missing = array_keys(array_filter([
            'penyebab dasar (root cause)' => blank($incident->root_cause),
            'tindakan korektif' => blank($incident->corrective_action),
            'penanggung jawab' => $incident->responsible_employee_id === null,
        ]));

        if ($missing !== []) {
            throw new LogicException('Insiden belum dapat ditutup — lengkapi dulu: '.implode(', ', $missing).'.');
        }

        $incident->forceFill([
            'status' => IncidentStatus::Closed,
            'closed_at' => $closedOn ?? now()->toDateString(),
            'closed_by' => $by?->id,
        ])->save();

        return $incident->refresh();
    }

    /**
     * Reopen a closed incident.
     *
     * Deliberately available: a corrective action that turns out not to have
     * worked is the most important thing a register can tell you, and the only
     * alternative — a second incident row for the same event — would double-count
     * it in every rate.
     */
    public function reopen(SafetyIncident $incident): SafetyIncident
    {
        if (! $incident->status->isClosed()) {
            throw new LogicException('Insiden ini belum ditutup.');
        }

        $incident->forceFill([
            'status' => IncidentStatus::Investigating,
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        return $incident->refresh();
    }

    /**
     * Laporan K3 bulanan — the report a project owes its client every month.
     *
     * @param  int|null  $projectId  null for the whole company
     */
    public function statistics(?int $projectId, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        $incidents = SafetyIncident::query()
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->whereBetween('occurred_at', [$start, $end])
            ->get();

        $manHours = $this->manHours($projectId, $start, $end);
        $recordable = $incidents->filter(fn ($incident) => $incident->severity->isRecordable());
        $lostDays = (int) $incidents->sum('lost_days');

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'project_id' => $projectId,
            'incident_count' => $incidents->count(),
            'recordable_count' => $recordable->count(),
            'lost_days' => $lostDays,
            'people_involved' => (int) $incidents->sum('people_involved'),
            'fatalities' => $incidents->where('severity', IncidentSeverity::Fatality)->count(),
            'by_severity' => $this->tally($incidents, 'severity'),
            'by_category' => $this->tally($incidents, 'category'),
            'open_count' => $incidents->filter(fn ($incident) => ! $incident->status->isClosed())->count(),
            'overdue_count' => $incidents->filter(fn ($incident) => $incident->isOverdue())->count(),
            'man_hours' => $manHours['hours'],
            'man_hours_basis' => $manHours['basis'],
            // Null rather than zero when there are no man-hours: a site with no
            // daily reports has an UNKNOWN frequency rate, not a perfect one, and
            // printing 0,00 on a client report would be a lie with a decimal point.
            'frequency_rate' => $manHours['hours'] > 0
                ? round($recordable->count() * self::RATE_BASE / $manHours['hours'], 2)
                : null,
            'severity_rate' => $manHours['hours'] > 0
                ? round($lostDays * self::RATE_BASE / $manHours['hours'], 2)
                : null,
            // Days since the last recordable incident — the number that goes on
            // the board at the site gate.
            'days_since_last_recordable' => $this->daysSinceLastRecordable($projectId, $end),
        ];
    }

    /**
     * Man-hours observed in the period.
     *
     * prj_daily_reports.manpower_count is the only headcount the system actually
     * records, so man-hours are that count times the configured hours in a working
     * day. The basis is returned alongside the number because the reader needs to
     * know the rate rests on daily reports having been filed.
     */
    private function manHours(?int $projectId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $manDays = (int) DB::table('prj_daily_reports')
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->whereNull('deleted_at')
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->sum('manpower_count');

        $hoursPerDay = Erp::float('projects.working_hours_per_day', 7.0);

        return [
            'hours' => round($manDays * $hoursPerDay, 2),
            'basis' => [
                'man_days' => $manDays,
                'hours_per_day' => $hoursPerDay,
                'source' => 'prj_daily_reports.manpower_count',
            ],
        ];
    }

    private function daysSinceLastRecordable(?int $projectId, CarbonImmutable $end): ?int
    {
        $last = SafetyIncident::query()
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->whereIn('severity', array_map(
                fn (IncidentSeverity $severity) => $severity->value,
                array_filter(IncidentSeverity::cases(), fn ($severity) => $severity->isRecordable()),
            ))
            ->where('occurred_at', '<=', $end)
            ->max('occurred_at');

        return $last === null ? null : CarbonImmutable::parse($last)->startOfDay()->diffInDays($end->startOfDay());
    }

    /**
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function tally($incidents, string $field): array
    {
        return $incidents
            ->groupBy(fn ($incident) => $incident->{$field}->value)
            ->map(fn ($group, $value) => [
                'value' => $value,
                'label' => $group->first()->{$field}->label(),
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
