<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ProjectCost;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * F-2 / T2.6 — peringatan `project_budget_pct` ≥ 90 % DI TEMPAT UANGNYA
 * DIBELANJAKAN.
 *
 * Sebuah peringatan yang hanya hidup di layar laporan adalah peringatan yang
 * dibaca sesudah uangnya keluar. Yang diuji di sini adalah bahwa angkanya bisa
 * dibaca oleh orang yang sedang MEMBUAT PO/SPK — bukan hanya oleh pemegang
 * fin.view — dan bahwa layar tempat ia dipasang benar-benar memintanya.
 */
class ProjectBudgetWarningTest extends ErpTestCase
{
    private function project(string $code): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => 'Proyek '.$code,
            'type' => 'construction',
            'status' => 'active',
            'contract_value' => 1_000_000_000,
        ]);
    }

    private function approvedRap(Project $project, float $amount): CostBudget
    {
        $boq = Boq::query()->create([
            'project_id' => $project->id,
            'title' => 'RAB '.$project->code,
            'status' => DocumentStatus::Approved,
        ]);
        $section = $boq->sections()->create(['section_no' => 'A', 'name' => 'Struktur']);
        $item = $boq->items()->create([
            'section_id' => $section->id,
            'wbs_code' => 'A.1',
            'description' => 'Paket',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $amount,
            'amount' => $amount,
        ]);

        /** @var CostBudget $rap */
        $rap = CostBudget::query()->create([
            'boq_id' => $boq->id,
            'project_id' => $project->id,
            'target_margin_pct' => 10,
            'status' => DocumentStatus::Approved,
        ]);

        $rap->items()->create([
            'boq_item_id' => $item->id,
            'cost_category' => 'material',
            'description' => 'Paket',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $amount,
            'amount' => $amount,
        ]);

        return $rap;
    }

    private function userHolding(string $email, string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_the_ninety_percent_warning_is_readable_by_whoever_creates_the_purchase_order(): void
    {
        $project = $this->project('PRJ-2026-961');
        $this->approvedRap($project, 100_000_000);
        ProjectCost::query()->create([
            'project_id' => $project->id,
            'cost_date' => '2026-07-01',
            'cost_category' => 'material',
            'description' => 'Realisasi',
            'amount' => 92_000_000,
        ]);

        // Pembeli: prc.create tanpa fin.view. Ia SUDAH akan diberi tahu angka
        // ini oleh kalimat penolakan gerbang; endpoint ini memberitahunya lebih
        // dulu, sebelum satu baris item pun diketik.
        Sanctum::actingAs($this->userHolding('pembeli@test.local', 'prc.create'));

        $payload = $this->getJson("/api/finance/budget/projects/{$project->id}")->assertOk()->json('data');

        // assertEquals: JSON tidak membedakan 92 dari 92.0.
        $this->assertEquals(92.0, $payload['pct']);
        $this->assertSame('mendekati', $payload['state']);
        $this->assertEquals(90.0, $payload['warn_pct']);
        $this->assertStringContainsString('92,0 %', $payload['sentence']);
        $this->assertStringContainsString('sisa Rp 8.000.000', $payload['sentence']);
    }

    public function test_a_role_that_may_neither_buy_nor_read_finance_is_refused(): void
    {
        $project = $this->project('PRJ-2026-962');

        Sanctum::actingAs($this->userHolding('helpdesk2@test.local', 'svc.view'));

        $this->getJson("/api/finance/budget/projects/{$project->id}")->assertForbidden();
    }

    public function test_a_project_without_an_approved_rap_says_so_instead_of_showing_zero_percent(): void
    {
        $project = $this->project('PRJ-2026-963');

        Sanctum::actingAs($this->userHolding('pembeli2@test.local', 'prc.create'));

        $payload = $this->getJson("/api/finance/budget/projects/{$project->id}")->assertOk()->json('data');

        $this->assertNull($payload['pct']);
        $this->assertNull($payload['budget']);
        $this->assertStringContainsString('belum punya RAP yang disetujui', $payload['sentence']);
        $this->assertStringContainsString('gerbang anggaran pada PO/SPK diam', $payload['sentence']);
    }

    /**
     * Layarnya benar-benar memintanya. Uji berkas, bukan peramban — harness
     * S29 yang menjalankannya di Chromium — tetapi sebuah `liveNote` yang
     * hilang dari formulir PO adalah cacat yang tidak boleh menunggu harness.
     */
    public function test_the_screens_where_money_is_spent_ask_for_this_number(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $form = (string) file_get_contents(public_path('app/js/views/form.js'));
        $project = (string) file_get_contents(public_path('app/js/views/project.js'));

        $this->assertSame(2, substr_count($schema, "liveNote: 'projectBudget'"),
            'Peringatan anggaran harus terpasang pada DUA formulir: PO dan SPK.');
        $this->assertStringContainsString('async projectBudget(value)', $form,
            'form.js kehilangan penyedia catatan hidup projectBudget.');
        $this->assertStringContainsString('finance/budget/projects/${value}', $form);
        $this->assertStringContainsString('finance/budget/projects/${id}', $project,
            'Layar proyek tidak lagi meminta anggaran terpakainya.');
    }
}
