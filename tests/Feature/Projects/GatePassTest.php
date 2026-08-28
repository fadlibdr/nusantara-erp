<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Services\FormPrintService;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P0-C — Izin Masuk/Keluar Material & Peralatan (IMK) menjadi transaksi.
 *
 * Dua tahap dengan URUTAN yang ditegakkan: manajemen menyetujui izinnya
 * (Approvable, prj.approve), baru gerbang MEMERIKSA muatan yang lewat —
 * aksi 'periksa' mengecap checked_by/checked_at dan menolak izin yang belum
 * approved. Pemeriksaan kedua juga ditolak: cap gerbang adalah bukti satu
 * kejadian, bukan kolom yang boleh ditimpa.
 *
 * Lembar F/IM kini mencetak muatannya sendiri, dan kotak arah MASUK/KELUAR
 * dicentang dari baris — arah kini fakta tercatat, bukan tebakan komputer.
 */
class GatePassTest extends ErpTestCase
{
    private ?User $admin = null;

    private ?User $approver = null;

    // -------------------------------------------------------------- fixtures

    private function project(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-084',
            'name' => 'Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-01-05',
            'end_date' => '2026-12-20',
            'warranty_months' => 12,
        ], $attributes));
    }

    private function admin(): User
    {
        if ($this->admin === null) {
            $this->admin = $this->adminUser();
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    private function approver(): User
    {
        if ($this->approver === null) {
            $role = Role::findOrCreate('project-manager-imk', 'web');
            $role->syncPermissions(['prj.view', 'prj.update', 'prj.approve']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var User $user */
            $user = User::query()->create([
                'name' => 'Rina Wijaya',
                'email' => 'pm-imk@test.local',
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->assignRole('project-manager-imk');
            $this->approver = $user;
        }

        return $this->approver;
    }

    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'direction' => 'out',
            'pass_date' => '2026-07-03',
            'vehicle_no' => 'B 9412 KYU',
            'driver_name' => 'Slamet Riyadi',
            'counterparty' => 'Workshop CV Baja Mandiri, Cikarang',
            'items' => [
                ['description' => 'Genset 60 kVA', 'qty' => 1, 'unit' => 'unit', 'notes' => 'Perbaikan AVR'],
                ['description' => 'Scaffolding set', 'qty' => 20, 'unit' => 'set'],
            ],
        ], $overrides);
    }

    private function approvedPass(array $overrides = []): array
    {
        $this->admin();
        $project = $this->project();

        $created = $this->postJson('/api/projects/gate-passes', $this->payload($project, $overrides))
            ->assertCreated()->json('data');
        $this->postJson("/api/projects/gate-passes/{$created['id']}/submit")->assertOk();

        Sanctum::actingAs($this->approver());
        $this->postJson("/api/projects/gate-passes/{$created['id']}/approve")->assertOk();

        return $created;
    }

    // ------------------------------------------------------------ the cycle

    public function test_the_full_cycle_runs_and_carries_the_item_lines(): void
    {
        $created = $this->approvedPass();

        $this->assertStringStartsWith('IMK/', $created['code']);

        $pass = GatePass::query()->with('items')->findOrFail($created['id']);
        $this->assertSame('approved', $pass->status->value);
        $this->assertCount(2, $pass->items);
        $this->assertSame('Genset 60 kVA', $pass->items[0]->description);
    }

    // -------------------------------------------------- periksa after approve

    public function test_the_gate_cannot_check_a_pass_management_has_not_approved(): void
    {
        $this->admin();
        $project = $this->project();

        $created = $this->postJson('/api/projects/gate-passes', $this->payload($project))
            ->assertCreated()->json('data');

        $draft = $this->postJson("/api/projects/gate-passes/{$created['id']}/periksa");
        $draft->assertStatus(422);
        $this->assertStringContainsString('belum disetujui', (string) $draft->json('message'));

        $this->postJson("/api/projects/gate-passes/{$created['id']}/submit")->assertOk();

        $submitted = $this->postJson("/api/projects/gate-passes/{$created['id']}/periksa");
        $submitted->assertStatus(422);

        $pass = GatePass::query()->findOrFail($created['id']);
        $this->assertNull($pass->checked_by);
        $this->assertNull($pass->checked_at);
    }

    public function test_periksa_stamps_the_gate_check_after_approval(): void
    {
        $created = $this->approvedPass();

        // The guard at the gate is whoever performs the periksa act.
        $guard = $this->approver();
        $this->postJson("/api/projects/gate-passes/{$created['id']}/periksa")->assertOk();

        $pass = GatePass::query()->findOrFail($created['id']);
        $this->assertSame($guard->id, $pass->checked_by);
        $this->assertNotNull($pass->checked_at);
    }

    public function test_a_second_periksa_is_refused_and_keeps_the_first_stamp(): void
    {
        $created = $this->approvedPass();
        $this->postJson("/api/projects/gate-passes/{$created['id']}/periksa")->assertOk();

        $first = GatePass::query()->findOrFail($created['id']);

        $again = $this->postJson("/api/projects/gate-passes/{$created['id']}/periksa");
        $again->assertStatus(422);
        $this->assertStringContainsString('sudah diperiksa', (string) $again->json('message'));

        $pass = GatePass::query()->findOrFail($created['id']);
        $this->assertSame($first->checked_by, $pass->checked_by);
        $this->assertSame((string) $first->checked_at, (string) $pass->checked_at);
    }

    // ------------------------------------------------------------- the sheet

    public function test_the_sheet_prints_the_load_and_ticks_the_recorded_direction(): void
    {
        $created = $this->approvedPass();

        $html = app(FormPrintService::class)->html('izin-material', ['id' => $created['id']]);

        $this->assertStringContainsString('IZIN MASUK / KELUAR MATERIAL', $html);
        $this->assertStringContainsString($created['code'], $html);
        $this->assertStringContainsString('Genset 60 kVA', $html);
        $this->assertStringContainsString('Scaffolding set', $html);
        $this->assertStringContainsString('B 9412 KYU', $html);
        $this->assertStringContainsString('Slamet Riyadi', $html);
        $this->assertStringContainsString('Workshop CV Baja Mandiri', $html);
        // Exactly ONE box ticked — the recorded direction, KELUAR here. Before
        // P0-C both boxes printed empty because the computer never saw the load.
        $this->assertSame(1, substr_count($html, '<span class="kotak">&#10005;</span>'));
        $this->assertSame(1, substr_count($html, '<span class="kotak"></span>'));
        $this->assertStringNotContainsString('dicetak kosong', $html);
    }
}
