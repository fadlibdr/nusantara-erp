<?php

namespace Tests\Unit\Projects;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Enums\ProjectType;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Project creation (standalone or bootstrapped from a signed contract), the
 * retention arithmetic that follows the contract, and the delete guard.
 */
class ProjectLifecycleTest extends ErpTestCase
{
    use ProjectsFixtures;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->makeCustomer();
    }

    private function makeContract(array $data = []): Contract
    {
        return Contract::query()->create(array_merge([
            'customer_id' => $this->customer->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'scope_type' => 'construction',
            'value' => 48500000000,
            'ppn_rate' => 11,
            'ppn_amount' => 5335000000,
            'total_with_ppn' => 53835000000,
            'sign_date' => '2026-01-15',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'retention_pct' => 5,
            'warranty_months' => 12,
            'status' => DocumentStatus::Approved,
        ], $data));
    }

    // ------------------------------------------------------------------ create

    public function test_a_new_project_starts_in_preparation_with_a_numbered_code(): void
    {
        $project = $this->projects()->create([
            'name' => 'Instalasi ELV Bank Artha',
            'type' => 'system_integration',
            'contract_value' => 9800000000,
        ]);

        $this->assertSame(ProjectStatus::Preparation, $project->status);
        $this->assertStringStartsWith('PRJ-', $project->code);
        $this->assertSame(0.0, (float) $project->actual_progress_pct);
        $this->assertSame(0.0, (float) $project->planned_progress_pct);
    }

    public function test_retention_defaults_from_the_settings_layer(): void
    {
        $project = $this->projects()->create([
            'name' => 'Proyek tanpa retensi eksplisit',
            'type' => 'construction',
            'contract_value' => 1000000000,
        ]);

        $this->assertSame(5.0, (float) $project->retention_pct);
        // 1.000.000.000 * 5 / 100 = 50.000.000
        $this->assertSame(50000000.0, $project->retentionAmount());
    }

    public function test_an_overridden_retention_setting_is_picked_up(): void
    {
        $this->setSetting('projects.default_retention_pct', 7.5);

        $project = $this->projects()->create([
            'name' => 'Proyek retensi 7,5%',
            'type' => 'construction',
            'contract_value' => 1000000000,
        ]);

        $this->assertSame(7.5, (float) $project->retention_pct);
        // 1.000.000.000 * 7,5 / 100 = 75.000.000
        $this->assertSame(75000000.0, $project->retentionAmount());
    }

    // ---------------------------------------------------------- from a contract

    public function test_a_project_bootstrapped_from_a_contract_carries_the_commercial_terms(): void
    {
        $contract = $this->makeContract();

        $project = $this->projects()->create(['contract_id' => $contract->id]);

        $this->assertSame($contract->id, (int) $project->contract_id);
        $this->assertSame($this->customer->id, (int) $project->customer_id);
        $this->assertSame('Pembangunan Gedung Kantor Graha Sentosa', $project->name);
        // nilai proyek = nilai kontrak (DPP, tanpa PPN)
        $this->assertSame(48500000000.0, (float) $project->contract_value);
        $this->assertSame(5.0, (float) $project->retention_pct);
        $this->assertSame(12, (int) $project->warranty_months);
        $this->assertSame('2026-02-01', $project->start_date->toDateString());
        $this->assertSame('2026-12-31', $project->end_date->toDateString());
        $this->assertSame(ProjectStatus::Preparation, $project->status);
    }

    public function test_the_retention_amount_follows_the_contract_percent(): void
    {
        $contract = $this->makeContract(['retention_pct' => 5]);

        $project = $this->projects()->create(['contract_id' => $contract->id]);

        // 48.500.000.000 * 5 / 100 = 2.425.000.000
        $this->assertSame(2425000000.0, $project->retentionAmount());
    }

    public function test_the_contract_scope_type_maps_onto_the_project_type(): void
    {
        $construction = $this->projects()->create(['contract_id' => $this->makeContract()->id]);
        $integration = $this->projects()->create([
            'contract_id' => $this->makeContract(['scope_type' => 'system_integration'])->id,
        ]);
        $maintenance = $this->projects()->create([
            'contract_id' => $this->makeContract(['scope_type' => 'maintenance'])->id,
        ]);

        $this->assertSame(ProjectType::Construction, $construction->type);
        $this->assertSame(ProjectType::SystemIntegration, $integration->type);
        $this->assertSame(ProjectType::Maintenance, $maintenance->type);
    }

    public function test_explicit_overrides_beat_the_contract_defaults(): void
    {
        $contract = $this->makeContract();

        $project = $this->projects()->create([
            'contract_id' => $contract->id,
            'name' => 'Paket 2 - Fit Out',
            'contract_value' => 12000000000,
            'retention_pct' => 3,
        ]);

        $this->assertSame('Paket 2 - Fit Out', $project->name);
        $this->assertSame(12000000000.0, (float) $project->contract_value);
        // 12.000.000.000 * 3 / 100 = 360.000.000
        $this->assertSame(360000000.0, $project->retentionAmount());
    }

    public function test_an_unknown_contract_id_falls_back_to_a_standalone_project(): void
    {
        $project = $this->projects()->create([
            'contract_id' => 9999,
            'name' => 'Proyek tanpa kontrak terdaftar',
            'type' => 'construction',
            'contract_value' => 500000000,
        ]);

        $this->assertSame(ProjectStatus::Preparation, $project->status);
        $this->assertSame(500000000.0, (float) $project->contract_value);
        $this->assertSame(5.0, (float) $project->retention_pct);
    }

    // ------------------------------------------------------------ delete guard

    public function test_deleting_an_active_project_throws_and_keeps_the_row(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Active]);

        try {
            $this->projects()->delete($project);
            $this->fail('Expected LogicException when deleting an active project.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('is active', $e->getMessage());
        }

        $this->assertNotNull(Project::query()->find($project->id));
        $this->assertSame(ProjectStatus::Active, Project::query()->findOrFail($project->id)->status);
    }

    public function test_a_project_still_in_preparation_can_be_deleted(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Preparation]);

        $this->projects()->delete($project);

        $this->assertNull(Project::query()->find($project->id));
        $this->assertNotNull(Project::withTrashed()->find($project->id));
    }

    public function test_an_on_hold_project_can_be_deleted(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::OnHold]);

        $this->projects()->delete($project);

        $this->assertNull(Project::query()->find($project->id));
    }

    public function test_the_header_progress_column_cannot_be_written_through_update(): void
    {
        $project = $this->makeProject(['actual_progress_pct' => 12.5]);

        $this->projects()->update($project, [
            'name' => 'Nama baru',
            'actual_progress_pct' => 99,
        ]);

        $fresh = Project::query()->findOrFail($project->id);

        $this->assertSame('Nama baru', $fresh->name);
        // Realisasi hanya boleh berubah lewat roll-up WBS.
        $this->assertSame(12.5, (float) $fresh->actual_progress_pct);
    }

    // --------------------------------------------------------------- dashboard

    public function test_the_dashboard_reports_the_leaf_weight_total_and_the_deviation(): void
    {
        $project = $this->makeProject(['planned_progress_pct' => 30]);
        $this->makeWbsTree($project, ['A' => ['A.1' => 60.0, 'A.2' => 40.0]]);

        $this->progress()->updateTaskProgress(
            $project->wbsTasks()->where('wbs_code', 'A.1')->firstOrFail(),
            50,
        );

        $dashboard = $this->projects()->dashboard($project->refresh());

        // realisasi = 50 x 60 / 100 = 30 ; deviasi = 30 - 30 = 0
        $this->assertSame(30.0, $dashboard['progress']['actual_pct']);
        $this->assertSame(30.0, $dashboard['progress']['planned_pct']);
        $this->assertSame(0.0, $dashboard['progress']['deviation_pct']);
        $this->assertSame(100.0, $dashboard['wbs']['leaf_weight_total']);
        $this->assertSame(3, $dashboard['wbs']['task_count']);
    }
}
