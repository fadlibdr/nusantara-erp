<?php

namespace Tests\Feature\Projects;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\RiskLevel;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P6 — register IBPRP per proyek (cetak F/IBPRP): aktivitas, bahaya, risiko
 * awal L×S, pengendalian, risiko sisa L×S.
 *
 * Aturan kejujuran yang dipaku: NILAI RISIKO ADALAH ARITMETIKA. Skor dihitung
 * service dari likelihood × severity; skor yang diketik klien dibuang. Banding
 * tingkat risiko hidup di SATU tempat (RiskLevel::fromScore) dengan sumbernya.
 */
class RiskRegisterTest extends ErpTestCase
{
    use BaselineFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'activity' => 'Pengecoran plat lantai 5',
            'hazard' => 'Bekerja di ketinggian tanpa proteksi tepi',
            'impact' => 'Pekerja terjatuh — cedera berat/fatal',
            'likelihood' => 3,
            'severity' => 5,
            'controls' => 'Railing tepi, harness terkait, toolbox meeting harian',
            'residual_likelihood' => 1,
            'residual_severity' => 5,
        ], $overrides);
    }

    public function test_the_risk_score_is_computed_and_a_typed_score_is_ignored(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $response = $this->postJson('/api/projects/risk-register', $this->payload($project, [
            'initial_score' => 1,     // klaim palsu: 3×5 = 15
            'residual_score' => 25,   // klaim palsu: 1×5 = 5
            'initial_level' => 'kecil',
        ]))->assertCreated();

        $this->assertSame(15, (int) $response->json('data.initial_score'));
        $this->assertSame(5, (int) $response->json('data.residual_score'));
        $this->assertSame('besar', $response->json('data.initial_level'));
        $this->assertSame('sedang', $response->json('data.residual_level'));
    }

    /**
     * Banding Permen PUPR 10/2021 (matriks 5×5, keterangan lampirannya):
     * 1–4 kecil, 5–12 sedang, 15–25 besar — diuji tepat di batasnya.
     * 13–14 tidak pernah muncul sebagai hasil kali L×S (1–5 × 1–5).
     */
    public function test_the_risk_level_banding_at_its_boundaries(): void
    {
        $this->assertSame(RiskLevel::Kecil, RiskLevel::fromScore(1));
        $this->assertSame(RiskLevel::Kecil, RiskLevel::fromScore(4));
        $this->assertSame(RiskLevel::Sedang, RiskLevel::fromScore(5));
        $this->assertSame(RiskLevel::Sedang, RiskLevel::fromScore(12));
        $this->assertSame(RiskLevel::Besar, RiskLevel::fromScore(15));
        $this->assertSame(RiskLevel::Besar, RiskLevel::fromScore(25));
    }

    public function test_likelihood_and_severity_are_bounded_one_to_five(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $this->postJson('/api/projects/risk-register', $this->payload($project, ['likelihood' => 6]))
            ->assertStatus(422)->assertJsonValidationErrors(['likelihood']);
        $this->postJson('/api/projects/risk-register', $this->payload($project, ['severity' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['severity']);
    }

    /** Risiko sisa dinilai berpasangan atau tidak sama sekali — tidak setengah. */
    public function test_a_half_assessed_residual_risk_is_refused(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $this->postJson('/api/projects/risk-register', $this->payload($project, [
            'residual_likelihood' => 2,
            'residual_severity' => null,
        ]))->assertStatus(422)->assertJsonPath(
            'errors.residual_likelihood.0',
            'Risiko sisa dinilai lengkap: kemungkinan DAN keparahan, atau kosongkan keduanya.',
        );
    }

    public function test_an_unassessed_residual_risk_stores_null_scores_not_zero(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $response = $this->postJson('/api/projects/risk-register', $this->payload($project, [
            'residual_likelihood' => null,
            'residual_severity' => null,
        ]))->assertCreated();

        $this->assertNull($response->json('data.residual_score'));
        $this->assertNull($response->json('data.residual_level'));
    }

    public function test_the_register_is_isolated_per_project(): void
    {
        $graha = $this->grahaProject();
        $other = Project::query()->create([
            'code' => 'PRJ-2026-090',
            'name' => 'Instalasi ELV Bank Artha',
            'type' => 'system_integration',
            'status' => 'active',
            'contract_value' => 9_800_000_000,
        ]);

        $this->actingAs($this->userWith('prj.create'));
        $this->postJson('/api/projects/risk-register', $this->payload($graha))->assertCreated();
        $this->postJson('/api/projects/risk-register', $this->payload($other, [
            'activity' => 'Penarikan kabel tray shaft',
            'hazard' => 'Tersengat listrik panel eksisting',
        ]))->assertCreated();

        $rows = $this->actingAs($this->userWith('prj.view'))
            ->getJson('/api/projects/risk-register?project_id='.$graha->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Pengecoran plat lantai 5', $rows[0]['activity']);
    }

    /**
     * F/IBPRP mencetak SATU LEMBAR PER PROYEK: hanya baris proyek itu; skor
     * tercetak adalah kolom TERSIMPAN hasil hitung; risiko sisa yang belum
     * dinilai bergaris — bukan 0 (aturan kejujuran §13.5).
     */
    public function test_the_f_ibprp_sheet_prints_only_this_projects_rows(): void
    {
        Company::query()->create(['name' => 'PT Nusantara Karya Integrasi']);
        $graha = $this->grahaProject();
        $other = Project::query()->create([
            'code' => 'PRJ-2026-090',
            'name' => 'Instalasi ELV Bank Artha',
            'type' => 'system_integration',
            'status' => 'active',
            'contract_value' => 9_800_000_000,
        ]);

        $this->actingAs($this->userWith('prj.create'));
        $this->postJson('/api/projects/risk-register', $this->payload($graha, [
            'residual_likelihood' => null,
            'residual_severity' => null,
        ]))->assertCreated();
        $this->postJson('/api/projects/risk-register', $this->payload($other, [
            'activity' => 'Penarikan kabel tray shaft',
            'hazard' => 'Tersengat listrik panel eksisting',
        ]))->assertCreated();

        $html = app(FormPrintService::class)->html('ibprp', ['id' => $graha->id]);

        $this->assertStringContainsString('IDENTIFIKASI BAHAYA', $html);
        $this->assertStringContainsString('Form F/IBPRP', $html);
        $this->assertStringContainsString('Pengecoran plat lantai 5', $html);
        $this->assertStringContainsString('Risiko besar', $html); // 3×5=15, dari banding satu tempat
        $this->assertStringNotContainsString('Penarikan kabel tray shaft', $html);
    }
}
