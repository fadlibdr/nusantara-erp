<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Services\DeploymentService;
use Modules\Assets\Services\EquipmentLogService;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * The kartu aset carries the equipment log register as its THIRD history
 * table. Hour-meter history is exactly what a mechanic reads off a kartu alat
 * — when deciding whether the 2.000-hour service is due, the meter trail is
 * the card's whole point — so the register prints beside the mobilisation and
 * maintenance histories rather than in a report nobody files with the machine.
 *
 * Same honesty rule as the two tables above it: rows from the database or a
 * sentence saying the register is empty — never ruled rows, which would
 * invite a reading to be written onto the card by hand, outside the register
 * whose monotonic guard exists to catch typos.
 */
class EquipmentLogPrintTest extends ErpTestCase
{
    private FormPrintService $forms;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    private function asset(): Asset
    {
        $category = AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 96],
        );

        return Asset::query()->create([
            'code' => 'AST-0001',
            'name' => 'Excavator Komatsu PC200-8',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => 960_000_000,
            'salvage_value' => 0,
            'useful_life_months' => 96,
            'accumulated_depreciation' => 0,
            'book_value' => 960_000_000,
            'status' => 'available',
        ]);
    }

    private function deployment(Asset $asset): Deployment
    {
        return app(DeploymentService::class)->deploy($asset, [
            'project_id' => (int) $this->project->id,
            'deployed_from' => '2026-03-02',
        ]);
    }

    private function recorder(): User
    {
        return User::query()->create([
            'name' => 'Agus Prasetyo',
            'email' => 'agus@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    public function test_the_asset_card_carries_the_equipment_log_register(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);

        app(EquipmentLogService::class)->record($deployment, [
            'log_date' => '2026-07-01',
            'hour_meter' => 1200.5,
            'fuel_liters' => 120,
            'notes' => 'Isi solar pagi.',
        ], $this->recorder());

        $html = $this->forms->html('kartu-aset', ['id' => $asset->refresh()->id]);

        $this->assertStringContainsString('LOG BBM &amp; JAM ALAT', $html);
        $this->assertStringContainsString($deployment->code, $html);
        $this->assertStringContainsString('1 Juli 2026', $html);
        // qty cast: a reading, trimmed the way the gauge shows it.
        $this->assertStringContainsString('1.200,5', $html);
        $this->assertStringContainsString('120', $html);
        $this->assertStringContainsString('Agus Prasetyo', $html);
    }

    /**
     * An empty register says so in a sentence. Ruled rows here would invite a
     * hand-written reading the monotonic guard never saw.
     */
    public function test_an_asset_with_no_logs_says_so_rather_than_ruling_rows(): void
    {
        $html = $this->forms->html('kartu-aset', ['id' => $this->asset()->id]);

        $this->assertStringContainsString('Belum ada log BBM atau jam alat tercatat', $html);
        $this->assertSame(0, substr_count($html, '<div class="fill"></div>'));
    }
}
