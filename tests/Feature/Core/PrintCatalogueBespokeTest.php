<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\ErpTestCase;

/**
 * Katalog cetak menjawab untuk SEMUA yang bisa dicetak endpoint-nya —
 * jumlahnya dipaku di ujinya dan naik seiring formulir baru.
 *
 * Temuan T3 laporan v2: tujuh formulir rumah proyek hidup di
 * FormPrintService::FORMS dan dilayani print/forms/{slug}/{id}, tetapi tidak
 * pernah muncul di GET api/core/print/forms — dapat dicetak, tak dapat
 * ditemukan oleh klien mana pun yang memercayai katalognya. SPA tidak
 * menggambar tombol ganda karena printButtonsFor mendedup per slug, dan uji
 * ini memaku kedua sisi kontrak itu.
 */
class PrintCatalogueBespokeTest extends ErpTestCase
{
    private const BESPOKE = [
        'data-proyek', 'laporan-harian', 'laporan-mingguan', 'daftar-temuan',
        'izin-kerja', 'izin-lembur', 'izin-material',
    ];

    public function test_the_admin_catalogue_lists_every_form_the_endpoint_serves(): void
    {
        $rows = $this->actingAs($this->adminUser())
            ->getJson('api/core/print/forms')
            ->assertOk()
            ->json('data');

        $this->assertCount(50, $rows, 'katalog = 43 registri + 7 formulir rumah proyek');

        $slugs = array_column($rows, 'slug');
        foreach (self::BESPOKE as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }

    public function test_the_bespoke_rows_carry_the_params_their_screens_need(): void
    {
        $rows = collect($this->actingAs($this->adminUser())
            ->getJson('api/core/print/forms')->json('data'))->keyBy('slug');

        // Laporan harian dicetak per BARIS laporan dengan ?tanggal= darinya.
        $this->assertSame('projects/daily-reports', $rows['laporan-harian']['resource']);
        $this->assertSame(['tanggal' => 'report_date'], $rows['laporan-harian']['params']);

        // Detail schedule berjangkar pada project_id baris progres.
        $this->assertSame('project_id', $rows['laporan-mingguan']['idField']);
        $this->assertSame(['minggu' => 'week_no'], $rows['laporan-mingguan']['params']);

        // P0-C: ketiga izin berjangkar pada BARIS izinnya sendiri, bukan lagi
        // pada proyek — resource-nya kunci RESOURCES layar daftar izin.
        $this->assertSame('projects/work-permits', $rows['izin-kerja']['resource']);
        $this->assertSame('projects/overtime-permits', $rows['izin-lembur']['resource']);
        $this->assertSame('projects/gate-passes', $rows['izin-material']['resource']);
        $this->assertSame('id', $rows['izin-kerja']['idField']);
    }

    public function test_a_caller_without_prj_view_gets_none_of_the_seven(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('inv.view', 'web'));

        $slugs = array_column(
            $this->actingAs($user->refresh())->getJson('api/core/print/forms')->json('data'),
            'slug',
        );

        foreach (self::BESPOKE as $slug) {
            $this->assertNotContains($slug, $slugs);
        }
    }
}
