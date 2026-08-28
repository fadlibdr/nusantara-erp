<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProjectService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P0-A — Laporan Harian penuh: FM-10-12 berhenti mencetak garis kosong karena
 * datanya kini ADA.
 *
 * Empat tabel baris baru (manpower per jabatan, alat, material masuk,
 * uraian/progress/target/hambatan) plus jam kerja dan kunci BAST. Aturan
 * kejujuran yang dijaga di sini:
 *
 *  - manpower_count DITURUNKAN dari rincian per jabatan begitu rinciannya ada;
 *    angka manual yang berbeda ditolak 422 yang MENYEBUT kedua angka dan
 *    selisihnya — bukan ditimpa diam-diam ke salah satu arah.
 *  - Tanpa rincian, angka manual tetap berlaku: laporan lama (pra-P0-A) tidak
 *    pernah dipaksa mundur (forward-only, tanpa backfill).
 *  - qty_rejected ≤ qty_received: barang yang ditolak lebih banyak dari yang
 *    datang adalah kebohongan aritmetika.
 *  - BAST I yang disetujui MEMBEKUKAN laporan bertanggal ≤ tanggal serah
 *    terima: itulah pekerjaan yang diserahterimakan dan ditandatangani tiga
 *    pihak. Laporan setelah tanggal itu bukan bagian dari yang diserahkan dan
 *    tidak dikunci oleh BAST I.
 *  - Kandidat GRN adalah bacaan, bukan impor otomatis: pengawas memilih.
 */
class DailyReportFullSheetTest extends ErpTestCase
{
    private ?User $admin = null;

    private ?User $approver = null;

    // -------------------------------------------------------------- fixtures

    private function project(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-071',
            'name' => 'Gedung Serbaguna Karawang',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'warranty_months' => 12,
        ], $attributes));
    }

    private function admin(): User
    {
        return $this->admin ??= $this->adminUser();
    }

    /** Maker-checker: penyetuju BAST bukan pembuatnya. */
    private function approver(): User
    {
        if ($this->approver !== null) {
            return $this->approver;
        }

        $role = Role::findOrCreate('direktur', 'web');
        $role->syncPermissions(['prj.view', 'prj.update', 'prj.approve']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Ir. Bambang Sutrisno',
            'email' => 'direktur@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $this->approver = $user;
    }

    /** @return array<string, mixed> payload POST minimal yang sah */
    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'report_date' => '2026-03-25',
            'manpower_count' => 10,
            'activities' => 'Pengecoran kolom lantai 2 zona A',
        ], $overrides);
    }

    /** @return list<array{role_key: string, headcount: int}> */
    private function manpowerRows(): array
    {
        return [
            ['role_key' => 'project_manager', 'headcount' => 1],
            ['role_key' => 'safety_officer', 'headcount' => 2],
            ['role_key' => 'mandor_sipil', 'headcount' => 60],
            ['role_key' => 'subkont', 'headcount' => 18],
        ]; // total 81
    }

    private function approvedBastOne(Project $project, string $handover = '2026-03-26'): Bast
    {
        $service = app(ProjectService::class);

        $bast = $service->createBast([
            'project_id' => $project->id,
            'bast_type' => 'bast1',
            'handover_date' => $handover,
            'customer_representative' => 'Ir. Bambang (Owner Rep.)',
        ]);
        $bast->submit();
        $service->approveBast($bast, $this->approver());

        return $bast->refresh();
    }

    // ------------------------------------------------- derivasi manpower_count

    public function test_manpower_rows_derive_the_headcount_total(): void
    {
        $project = $this->project();

        $response = $this->actingAs($this->admin())->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'manpower_count' => null,
                'manpower' => $this->manpowerRows(),
            ]));

        $response->assertCreated();
        $this->assertSame(81, $response->json('data.manpower_count'));
        $this->assertCount(4, $response->json('data.manpower'));
        $this->assertSame(81, (int) DailyReport::query()->first()->manpower_count);
    }

    public function test_a_matching_manual_count_is_accepted(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'manpower_count' => 81,
                'manpower' => $this->manpowerRows(),
            ]))->assertCreated();
    }

    public function test_a_conflicting_manual_count_is_refused_naming_both_numbers(): void
    {
        $project = $this->project();

        $response = $this->actingAs($this->admin())->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'manpower_count' => 100,
                'manpower' => $this->manpowerRows(),
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['manpower_count']);

        $message = $response->json('errors.manpower_count.0');
        $this->assertStringContainsString('(100)', $message);
        $this->assertStringContainsString('(81)', $message);
        $this->assertStringContainsString('selisih 19', $message);

        $this->assertSame(0, DailyReport::query()->count());
    }

    /** Kompat data lama: tanpa rincian, angka manual dipercaya apa adanya. */
    public function test_without_rows_the_manual_count_stands(): void
    {
        $project = $this->project();

        $response = $this->actingAs($this->admin())->postJson('api/projects/daily-reports',
            $this->payload($project, ['manpower_count' => 148]));

        $response->assertCreated();
        $this->assertSame(148, $response->json('data.manpower_count'));
    }

    public function test_update_rederives_when_rows_are_replaced(): void
    {
        $project = $this->project();
        $report = $this->storeWithRows($project);

        $response = $this->actingAs($this->admin())
            ->putJson("api/projects/daily-reports/{$report->id}", [
                'manpower' => [
                    ['role_key' => 'mandor_mep', 'headcount' => 24],
                    ['role_key' => 'produksi', 'headcount' => 4],
                ],
            ]);

        $response->assertOk();
        $this->assertSame(28, $response->json('data.manpower_count'));
        $this->assertCount(2, $response->json('data.manpower'));
    }

    public function test_update_conflict_between_manual_and_replaced_rows_is_refused(): void
    {
        $project = $this->project();
        $report = $this->storeWithRows($project);

        $response = $this->actingAs($this->admin())
            ->putJson("api/projects/daily-reports/{$report->id}", [
                'manpower_count' => 5,
                'manpower' => [['role_key' => 'produksi', 'headcount' => 4]],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['manpower_count']);
        $this->assertStringContainsString('(5)', $response->json('errors.manpower_count.0'));
        $this->assertStringContainsString('(4)', $response->json('errors.manpower_count.0'));
    }

    /**
     * Rincian sudah tersimpan; update yang TIDAK menyentuh rincian tetapi
     * membawa angka manual yang berbeda tetap ditolak — sumbernya rincian.
     */
    public function test_update_conflict_with_stored_rows_is_refused_even_without_touching_them(): void
    {
        $project = $this->project();
        $report = $this->storeWithRows($project); // stored rows sum 81

        $this->actingAs($this->admin())
            ->putJson("api/projects/daily-reports/{$report->id}", ['manpower_count' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manpower_count']);
    }

    /** Laporan lama (tanpa rincian) tetap bisa mengoreksi angka manualnya. */
    public function test_an_old_report_updates_its_manual_count_without_rows(): void
    {
        $project = $this->project();

        $report = DailyReport::query()->create([
            'project_id' => $project->id,
            'report_date' => '2026-03-20',
            'manpower_count' => 120,
            'activities' => 'Laporan era pra-P0-A',
        ]);

        $response = $this->actingAs($this->admin())
            ->putJson("api/projects/daily-reports/{$report->id}", ['manpower_count' => 125]);

        $response->assertOk();
        $this->assertSame(125, $response->json('data.manpower_count'));
        $this->assertSame([], $response->json('data.manpower'));
    }

    public function test_clearing_the_rows_lets_the_manual_count_stand_again(): void
    {
        $project = $this->project();
        $report = $this->storeWithRows($project);

        $response = $this->actingAs($this->admin())
            ->putJson("api/projects/daily-reports/{$report->id}", [
                'manpower' => [],
                'manpower_count' => 90,
            ]);

        $response->assertOk();
        $this->assertSame(90, $response->json('data.manpower_count'));
        $this->assertSame([], $response->json('data.manpower'));
    }

    public function test_duplicate_role_keys_are_refused(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'manpower_count' => null,
                'manpower' => [
                    ['role_key' => 'produksi', 'headcount' => 2],
                    ['role_key' => 'produksi', 'headcount' => 3],
                ],
            ]))->assertStatus(422);
    }

    public function test_an_unknown_role_key_is_refused(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'manpower_count' => null,
                'manpower' => [['role_key' => 'mandor_listrik', 'headcount' => 2]],
            ]))->assertStatus(422);
    }

    // ------------------------------------------------------------- jam kerja

    public function test_work_end_must_be_after_work_start(): void
    {
        $project = $this->project();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('api/projects/daily-reports',
            $this->payload($project, ['work_start' => '17:00', 'work_end' => '08:00']));

        $response->assertStatus(422)->assertJsonValidationErrors(['work_end']);
        $this->assertStringContainsString('08:00', $response->json('errors.work_end.0'));
        $this->assertStringContainsString('17:00', $response->json('errors.work_end.0'));

        $ok = $this->actingAs($admin)->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'work_start' => '08:00',
                'work_end' => '17:00',
                'lost_hours_reason' => 'Hujan deras 2 jam, area terbuka berhenti.',
            ]));

        $ok->assertCreated();
        $this->assertSame('08:00', $ok->json('data.work_start'));
        $this->assertSame('17:00', $ok->json('data.work_end'));
    }

    /** Update parsial: work_end baru dibandingkan dengan work_start TERSIMPAN. */
    public function test_a_partial_update_compares_against_the_stored_start(): void
    {
        $project = $this->project();

        $report = $this->actingAsCreated($this->payload($project, [
            'work_start' => '08:00', 'work_end' => '17:00',
        ]));

        $this->actingAs($this->admin())
            ->putJson("api/projects/daily-reports/{$report->id}", ['work_end' => '07:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['work_end']);
    }

    // ------------------------------------------------------- material masuk

    public function test_qty_rejected_cannot_exceed_qty_received(): void
    {
        $project = $this->project();

        $response = $this->actingAs($this->admin())->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'receipts' => [[
                    'description' => 'Semen Portland 50kg',
                    'qty_received' => 20,
                    'qty_rejected' => 30,
                    'unit' => 'zak',
                ]],
            ]));

        $response->assertStatus(422);
        $message = $response->json('errors')['receipts.0.qty_rejected'][0];
        $this->assertStringContainsString('30', $message);
        $this->assertStringContainsString('20', $message);
    }

    // ----------------------------------------------- empat tabel bolak-balik

    public function test_the_four_line_tables_round_trip_through_store_show_and_update(): void
    {
        $project = $this->project();
        $admin = $this->admin();

        $store = $this->actingAs($admin)->postJson('api/projects/daily-reports',
            $this->payload($project, [
                'manpower_count' => null,
                'manpower' => $this->manpowerRows(),
                'equipment' => [
                    ['description' => 'Tower crane', 'qty' => 1, 'hours' => 8],
                    ['description' => 'Concrete pump', 'qty' => 2],
                ],
                'receipts' => [[
                    'description' => 'Ready Mix K-300',
                    'qty_received' => 86,
                    'qty_rejected' => 6,
                    'unit' => 'm3',
                    'rejection_reason' => 'Slump di luar toleransi',
                ]],
                'activity_lines' => [
                    [
                        'description' => 'Pengecoran plat lantai 5 zona A',
                        'progress_note' => '86 m3 tercor',
                        'target_note' => 'Zona B besok',
                        'sort_order' => 1,
                    ],
                    [
                        'description' => 'Pembesian kolom lantai 6',
                        'obstacle' => 'Antrian truk mixer',
                        'sort_order' => 2,
                    ],
                ],
            ]));

        $store->assertCreated();
        $id = $store->json('data.id');

        $show = $this->actingAs($admin)->getJson("api/projects/daily-reports/{$id}");
        $show->assertOk();

        $this->assertCount(4, $show->json('data.manpower'));
        $this->assertSame('Project Manager', $show->json('data.manpower.0.role_label'));
        $this->assertCount(2, $show->json('data.equipment'));
        $this->assertSame('Tower crane', $show->json('data.equipment.0.description'));
        $this->assertCount(1, $show->json('data.receipts'));
        $this->assertSame('Slump di luar toleransi', $show->json('data.receipts.0.rejection_reason'));
        $this->assertCount(2, $show->json('data.activity_lines'));
        $this->assertSame('Antrian truk mixer', $show->json('data.activity_lines.1.obstacle'));

        // Update menggantikan tabel secara utuh — pola materials[] yang ada.
        $update = $this->actingAs($admin)->putJson("api/projects/daily-reports/{$id}", [
            'equipment' => [['description' => 'Excavator PC200', 'qty' => 1, 'hours' => 6]],
        ]);

        $update->assertOk();
        $this->assertCount(1, $update->json('data.equipment'));
        $this->assertSame('Excavator PC200', $update->json('data.equipment.0.description'));
        // Tabel lain tidak tersentuh oleh kunci yang tidak dikirim.
        $this->assertCount(1, $update->json('data.receipts'));
        $this->assertCount(4, $update->json('data.manpower'));
    }

    // ------------------------------------------------------------ kunci BAST

    public function test_bast_one_approval_locks_reports_up_to_the_handover_date(): void
    {
        $project = $this->project();
        $admin = $this->admin();

        $before = $this->actingAsCreated($this->payload($project, ['report_date' => '2026-03-25']));
        $onDate = $this->actingAsCreated($this->payload($project, ['report_date' => '2026-03-26']));
        $after = $this->actingAsCreated($this->payload($project, ['report_date' => '2026-03-27']));

        $this->approvedBastOne($project, '2026-03-26');

        $this->assertNotNull($before->fresh()->locked_at);
        $this->assertNotNull($onDate->fresh()->locked_at);
        $this->assertNull($after->fresh()->locked_at);
    }

    public function test_a_locked_report_refuses_update_and_delete_naming_the_bast(): void
    {
        $project = $this->project();
        $report = $this->actingAsCreated($this->payload($project, ['report_date' => '2026-03-25']));

        $bast = $this->approvedBastOne($project, '2026-03-26');

        $update = $this->actingAs($this->admin())
            ->putJson("api/projects/daily-reports/{$report->id}", ['manpower_count' => 11]);

        $update->assertStatus(422);
        $this->assertStringContainsString($bast->code, $update->json('message'));
        $this->assertStringContainsString('2026-03-26', $update->json('message'));

        $delete = $this->actingAs($this->admin())
            ->deleteJson("api/projects/daily-reports/{$report->id}");

        $delete->assertStatus(422);
        $this->assertStringContainsString($bast->code, $delete->json('message'));
        $this->assertNotNull($report->fresh());
    }

    // -------------------------------------------------------- kandidat GRN

    public function test_receipts_candidates_lists_only_posted_grns_of_the_same_site_and_date(): void
    {
        $project = $this->project();
        $admin = $this->admin();

        $report = $this->actingAsCreated($this->payload($project, ['report_date' => '2026-03-25']));

        $category = ItemCategory::query()->create(['code' => 'CAT-T', 'name' => 'Material Uji']);
        $semen = Item::query()->create([
            'code' => 'ITM-T1', 'name' => 'Semen Portland 50kg',
            'category_id' => $category->id, 'unit' => 'zak',
        ]);
        $besi = Item::query()->create([
            'code' => 'ITM-T2', 'name' => 'Besi Beton D16',
            'category_id' => $category->id, 'unit' => 'btg',
        ]);

        $site = Warehouse::query()->create([
            'code' => 'WH-PRJ-T', 'name' => 'Gudang Site Karawang', 'project_id' => $project->id,
        ]);
        $central = Warehouse::query()->create(['code' => 'WH-PUSAT-T', 'name' => 'Gudang Pusat']);

        $grn = GoodsReceipt::query()->create([
            'code' => 'GRN/2026/03/0101', 'warehouse_id' => $site->id,
            'receipt_date' => '2026-03-25', 'status' => 'posted',
        ]);
        $grn->items()->create(['item_id' => $semen->id, 'qty' => 150, 'unit_cost' => 0, 'amount' => 0]);
        $grn->items()->create(['item_id' => $besi->id, 'qty' => 420, 'unit_cost' => 0, 'amount' => 0]);

        /*
         * Setiap pengecoh MEMBAWA BARIS ITEM. Versi pertama uji ini membuat
         * pengecoh tanpa baris, dan endpoint mem-flatMap baris item — GRN tanpa
         * baris menyumbang nol kandidat apa pun status/gudang/tanggalnya, jadi
         * ketiga filter yang diuji tidak pernah benar-benar teruji: menghapus
         * filter status dari controller membiarkan uji tetap hijau. Verifikasi
         * membuktikannya; baris item inilah yang membuat uji ini bisa merah.
         */
        $decoys = [
            // Draf di site yang sama, hari yang sama.
            ['code' => 'GRN/2026/03/0102', 'warehouse_id' => $site->id,
                'receipt_date' => '2026-03-25', 'status' => 'draft'],
            // Terposting tapi gudang pusat (bukan site proyek ini).
            ['code' => 'GRN/2026/03/0103', 'warehouse_id' => $central->id,
                'receipt_date' => '2026-03-25', 'status' => 'posted'],
            // Site yang sama, hari lain.
            ['code' => 'GRN/2026/03/0104', 'warehouse_id' => $site->id,
                'receipt_date' => '2026-03-24', 'status' => 'posted'],
        ];
        foreach ($decoys as $attributes) {
            GoodsReceipt::query()->create($attributes)
                ->items()->create(['item_id' => $semen->id, 'qty' => 99, 'unit_cost' => 0, 'amount' => 0]);
        }

        $response = $this->actingAs($admin)
            ->getJson("api/projects/daily-reports/{$report->id}/receipts-candidates");

        $response->assertOk();
        $rows = $response->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame(['GRN/2026/03/0101'], array_values(array_unique(array_column($rows, 'grn_code'))));
        $this->assertSame($semen->id, $rows[0]['item_id']);
        $this->assertSame('Semen Portland 50kg', $rows[0]['description']);
        $this->assertSame(150.0, (float) $rows[0]['qty_received']);
        $this->assertSame('zak', $rows[0]['unit']);
        $this->assertSame(0.0, (float) $rows[0]['qty_rejected']);
        $this->assertFalse($rows[0]['already_imported']);

        // Penanda per (GRN, item): impor SATU baris dari GRN dua-baris —
        // baris satunya TIDAK boleh ikut mengaku sudah diambil.
        $this->actingAs($admin)->putJson("api/projects/daily-reports/{$report->id}", [
            'receipts' => [[
                'goods_receipt_id' => $grn->id, 'item_id' => $semen->id,
                'description' => 'Semen Portland 50kg',
                'qty_received' => 150, 'qty_rejected' => 0, 'unit' => 'zak',
            ]],
        ])->assertOk();

        $after = collect($this->actingAs($admin)
            ->getJson("api/projects/daily-reports/{$report->id}/receipts-candidates")
            ->json('data'))->keyBy('item_id');

        $this->assertTrue($after[$semen->id]['already_imported']);
        $this->assertFalse($after[$besi->id]['already_imported'], 'baris besi belum diimpor dan tidak boleh mengaku sudah');

        // Sesudah baris GRN itu diimpor pengawas, kandidatnya MENGAKU sudah
        // dipakai — tetap tampil (bukan disembunyikan) agar tidak diimpor dua kali.
        $this->actingAs($admin)->putJson("api/projects/daily-reports/{$report->id}", [
            'receipts' => [[
                'goods_receipt_id' => $grn->id,
                'item_id' => $semen->id,
                'description' => 'Semen Portland 50kg',
                'qty_received' => 150,
                'unit' => 'zak',
            ]],
        ])->assertOk();

        $again = $this->actingAs($admin)
            ->getJson("api/projects/daily-reports/{$report->id}/receipts-candidates");
        $this->assertTrue($again->json('data.0.already_imported'));
    }

    public function test_receipts_candidates_requires_prj_view(): void
    {
        $project = $this->project();
        $report = $this->actingAsCreated($this->payload($project));

        /** @var User $outsider */
        $outsider = User::query()->create([
            'name' => 'Tamu Tanpa Izin',
            'email' => 'tamu@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->getJson("api/projects/daily-reports/{$report->id}/receipts-candidates")
            ->assertForbidden();
    }

    // --------------------------------------------------------------- helpers

    private function storeWithRows(Project $project): DailyReport
    {
        return $this->actingAsCreated($this->payload($project, [
            'manpower_count' => null,
            'manpower' => $this->manpowerRows(),
        ]));
    }

    private function actingAsCreated(array $payload): DailyReport
    {
        $response = $this->actingAs($this->admin())
            ->postJson('api/projects/daily-reports', $payload);

        $response->assertCreated();

        return DailyReport::query()->findOrFail($response->json('data.id'));
    }
}
