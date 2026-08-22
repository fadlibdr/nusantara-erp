<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Projects\Enums\IncidentCategory;
use Modules\Projects\Enums\IncidentSeverity;
use Modules\Projects\Enums\IncidentStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\SafetyIncident;
use Modules\Projects\Services\SafetyIncidentService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Feature\HrPayroll\PayrollFixtures;

/**
 * Register kecelakaan kerja (SMK3).
 *
 * The whole safety surface was one free-text column, prj_daily_reports.safety_notes,
 * and the seed data shows the cost: "Satu near-miss material jatuh dari lantai 5"
 * — an event PP 50/2012 and Permen PUPR 10/2021 require a contractor to record,
 * investigate and follow up — sitting in prose with no severity, no cause, no
 * corrective action, nobody accountable and no closing date. Neither question the
 * assessment named could be asked: "every incident this quarter", and "the monthly
 * K3 report".
 */
class SafetyIncidentTest extends ErpTestCase
{
    use PayrollFixtures;

    private SafetyIncidentService $service;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SafetyIncidentService::class);
        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    private function incident(array $attributes = []): SafetyIncident
    {
        return $this->service->create(array_merge([
            'project_id' => $this->project->id,
            'occurred_at' => '2026-06-10 16:40:00',
            'location' => 'Lantai 5, zona B',
            'severity' => IncidentSeverity::NearMiss,
            'category' => IncidentCategory::StruckByObject,
            'description' => 'Material jatuh dari lantai 5, tidak mengenai pekerja.',
            'people_involved' => 0,
        ], $attributes));
    }

    /** Man-days for the frequency rate come from the daily reports. */
    private function seedManpower(int $days, int $perDay, string $from = '2026-06-01'): void
    {
        for ($i = 0; $i < $days; $i++) {
            DB::table('prj_daily_reports')->insert([
                'code' => 'DRP/2026/06/'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'project_id' => $this->project->id,
                'report_date' => date('Y-m-d', strtotime("{$from} +{$i} days")),
                'manpower_count' => $perDay,
                'activities' => 'Pekerjaan struktur lantai 5',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ------------------------------------------------------------- recording

    public function test_an_incident_gets_a_number_and_opens(): void
    {
        $incident = $this->incident();

        $this->assertMatchesRegularExpression('#^K3/2026/[IVX]+/\d{3}$#', $incident->code);
        $this->assertSame(IncidentStatus::Open, $incident->status);
    }

    /**
     * The shift is half of what a safety review looks for. "16:40, end of the
     * late shift" is the finding; a bare date throws it away.
     */
    public function test_the_time_of_day_survives(): void
    {
        $this->assertSame('16:40', $this->incident()->occurred_at->format('H:i'));
    }

    public function test_the_endpoint_refuses_an_incident_dated_in_the_future(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/projects/safety-incidents', [
                'project_id' => $this->project->id,
                'occurred_at' => now()->addDay()->toDateTimeString(),
                'severity' => 'near_miss',
                'category' => 'struck_by_object',
                'description' => 'Belum terjadi',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurred_at');
    }

    // ------------------------------------------------------------ closing out

    /**
     * THE GUARD THAT MATTERS. A register that can be emptied by ticking things
     * done reports zero open items and teaches nothing, which is exactly what a
     * safety audit is looking for.
     */
    public function test_an_incident_cannot_be_closed_without_a_cause_an_action_and_an_owner(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/penyebab dasar.*tindakan korektif.*penanggung jawab/s');

        $this->service->close($this->incident());
    }

    public function test_an_incident_closes_once_the_follow_up_is_complete(): void
    {
        $employee = $this->makeEmployee(['name' => 'Slamet Riyadi']);
        $incident = $this->service->update($this->incident(), [
            'root_cause' => 'Toe board pada perancah lantai 5 tidak terpasang.',
            'corrective_action' => 'Pemasangan toe board di seluruh perancah dan inspeksi harian.',
            'responsible_employee_id' => $employee->id,
        ]);

        $closed = $this->service->close($incident, $this->adminUser(), '2026-06-20');

        $this->assertSame(IncidentStatus::Closed, $closed->status);
        $this->assertSame('2026-06-20', $closed->closed_at->toDateString());
    }

    /** A closed incident is a record. Correcting one means reopening it first. */
    public function test_a_closed_incident_cannot_be_edited(): void
    {
        $closed = $this->closedIncident();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah ditutup tidak dapat diubah/');

        $this->service->update($closed, ['description' => 'Diubah diam-diam']);
    }

    /**
     * A corrective action that turns out not to have worked is the most useful
     * thing a register can tell you. The alternative — a second row for the same
     * event — would double-count it in every rate.
     */
    public function test_a_closed_incident_can_be_reopened(): void
    {
        $reopened = $this->service->reopen($this->closedIncident());

        $this->assertSame(IncidentStatus::Investigating, $reopened->status);
        $this->assertNull($reopened->closed_at);
    }

    private function closedIncident(): SafetyIncident
    {
        $incident = $this->service->update($this->incident(), [
            'root_cause' => 'Toe board tidak terpasang.',
            'corrective_action' => 'Pemasangan toe board.',
            'responsible_employee_id' => $this->makeEmployee()->id,
        ]);

        return $this->service->close($incident, $this->adminUser(), '2026-06-20');
    }

    // ------------------------------------------------------------- the report

    public function test_the_frequency_rate_counts_recordable_incidents_per_million_man_hours(): void
    {
        $this->seedManpower(days: 20, perDay: 100); // 2.000 man-days × 7 jam = 14.000 jam
        $this->incident(['severity' => IncidentSeverity::LostTime, 'lost_days' => 5]);

        $report = $this->service->statistics($this->project->id, '2026-06-01', '2026-06-30');

        $this->assertSame(14000.0, $report['man_hours']);
        $this->assertSame(1, $report['recordable_count']);
        // 1 × 1.000.000 / 14.000
        $this->assertEqualsWithDelta(71.43, $report['frequency_rate'], 0.01);
        // 5 × 1.000.000 / 14.000
        $this->assertEqualsWithDelta(357.14, $report['severity_rate'], 0.01);
    }

    /**
     * A near miss is the same event with better luck and belongs in the register,
     * but counting it in the frequency rate would make a site that reports
     * honestly look worse than one that reports nothing.
     */
    public function test_a_near_miss_is_recorded_but_does_not_raise_the_frequency_rate(): void
    {
        $this->seedManpower(days: 20, perDay: 100);
        $this->incident(); // near miss
        $this->incident(['severity' => IncidentSeverity::FirstAid, 'occurred_at' => '2026-06-12 09:00:00']);

        $report = $this->service->statistics($this->project->id, '2026-06-01', '2026-06-30');

        $this->assertSame(2, $report['incident_count']);
        $this->assertSame(0, $report['recordable_count']);
        $this->assertSame(0.0, $report['frequency_rate']);
    }

    /**
     * A site that has filed no daily reports has an UNKNOWN frequency rate, not
     * a perfect one. Printing 0,00 on a client's report would be a lie with a
     * decimal point in it.
     */
    public function test_a_site_with_no_recorded_man_hours_reports_no_rate_rather_than_a_perfect_one(): void
    {
        $this->incident(['severity' => IncidentSeverity::LostTime, 'lost_days' => 3]);

        $report = $this->service->statistics($this->project->id, '2026-06-01', '2026-06-30');

        $this->assertNull($report['frequency_rate']);
        $this->assertNull($report['severity_rate']);
        $this->assertSame(1, $report['recordable_count'], 'the incident is still counted');
    }

    public function test_the_report_breaks_the_period_down_by_severity_and_by_category(): void
    {
        $this->incident();
        $this->incident(['category' => IncidentCategory::FallFromHeight, 'occurred_at' => '2026-06-12 08:00:00']);
        $this->incident(['category' => IncidentCategory::FallFromHeight, 'occurred_at' => '2026-06-14 08:00:00']);

        $report = $this->service->statistics($this->project->id, '2026-06-01', '2026-06-30');

        $this->assertSame('fall_from_height', $report['by_category'][0]['value'], 'the commonest category leads');
        $this->assertSame(2, $report['by_category'][0]['count']);
        $this->assertSame('Jatuh dari ketinggian', $report['by_category'][0]['label']);
        $this->assertSame(3, $report['by_severity'][0]['count']);
    }

    /** Incidents outside the period belong to another month's report. */
    public function test_the_report_covers_only_its_own_period(): void
    {
        $this->incident(['occurred_at' => '2026-05-30 10:00:00']);
        $this->incident(['occurred_at' => '2026-06-10 10:00:00']);
        $this->incident(['occurred_at' => '2026-07-02 10:00:00']);

        $this->assertSame(1, $this->service->statistics($this->project->id, '2026-06-01', '2026-06-30')['incident_count']);
    }

    /** The number on the board at the site gate. */
    public function test_the_report_says_how_long_since_the_last_recordable_incident(): void
    {
        $this->incident(['severity' => IncidentSeverity::LostTime, 'occurred_at' => '2026-06-10 08:00:00']);

        $report = $this->service->statistics($this->project->id, '2026-06-01', '2026-06-30');

        $this->assertSame(20, $report['days_since_last_recordable']);
    }

    public function test_a_period_with_no_recordable_incident_has_no_such_day_count(): void
    {
        $this->incident(); // near miss only

        $this->assertNull($this->service->statistics($this->project->id, '2026-06-01', '2026-06-30')['days_since_last_recordable']);
    }

    // ---------------------------------------------------------- the questions

    /** "Semua insiden kuartal ini" — the first question the register exists for. */
    public function test_the_register_answers_every_incident_in_a_quarter(): void
    {
        $this->incident(['occurred_at' => '2026-04-05 08:00:00']);
        $this->incident(['occurred_at' => '2026-06-10 08:00:00']);
        $this->incident(['occurred_at' => '2026-07-20 08:00:00']);

        $this->actingAs($this->adminUser())
            ->getJson('/api/projects/safety-incidents?from=2026-04-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** The one thing a site manager is asked about on a safety walk. */
    public function test_the_register_answers_which_corrective_actions_are_overdue(): void
    {
        $this->incident(['due_date' => '2026-06-20']);            // long past
        $this->incident(['due_date' => now()->addMonth()->toDateString(), 'occurred_at' => '2026-06-11 08:00:00']);

        $this->actingAs($this->adminUser())
            ->getJson('/api/projects/safety-incidents?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_a_closed_incident_is_never_overdue(): void
    {
        $incident = $this->service->update($this->incident(['due_date' => '2026-06-20']), [
            'root_cause' => 'Toe board tidak terpasang.',
            'corrective_action' => 'Pemasangan toe board.',
            'responsible_employee_id' => $this->makeEmployee()->id,
        ]);
        $closed = $this->service->close($incident, $this->adminUser(), '2026-07-01');

        $this->assertFalse($closed->isOverdue());
    }

    public function test_the_statistics_endpoint_serves_the_monthly_report(): void
    {
        $this->seedManpower(days: 20, perDay: 100);
        $this->incident(['severity' => IncidentSeverity::LostTime, 'lost_days' => 5]);

        $this->actingAs($this->adminUser())
            ->getJson("/api/projects/safety-incidents/statistics?project_id={$this->project->id}&from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonPath('data.recordable_count', 1)
            ->assertJsonPath('data.lost_days', 5)
            ->assertJsonPath('data.man_hours_basis.source', 'prj_daily_reports.manpower_count');
    }

    /**
     * The refusal has to reach the user as a refusal, not a 500. Its message
     * names exactly which of the three fields is still missing, which is the
     * only thing that tells a safety officer what to do next.
     */
    public function test_the_endpoint_refuses_an_incomplete_close_and_says_what_is_missing(): void
    {
        $incident = $this->incident();

        $this->actingAs($this->adminUser())
            ->postJson("/api/projects/safety-incidents/{$incident->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'penyebab dasar')
                && str_contains($message, 'tindakan korektif')
                && str_contains($message, 'penanggung jawab'));

        $this->assertSame(IncidentStatus::Open, $incident->refresh()->status);
    }

    /**
     * An unassigned incident must not carry an empty relation object. The detail
     * screen renders one as a second, blank "Penanggung jawab" row beside the
     * real field, which reads as two different unanswered questions.
     */
    public function test_an_unassigned_incident_carries_no_empty_relation(): void
    {
        $incident = $this->incident();

        $this->actingAs($this->adminUser())
            ->getJson("/api/projects/safety-incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.responsible_employee')
            ->assertJsonPath('data.responsible_employee_id', null)
            ->assertJsonPath('data.project.code', 'PRJ-2026-001');
    }

    /** Closing one out asserts the corrective action was done. */
    public function test_closing_an_incident_needs_the_approve_permission(): void
    {
        $incident = $this->incident();
        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo('prj.approve');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->refresh())
            ->postJson("/api/projects/safety-incidents/{$incident->id}/close")
            ->assertForbidden();
    }
}
