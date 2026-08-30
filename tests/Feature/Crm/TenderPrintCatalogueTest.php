<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\TenderPackage;
use Modules\Crm\Services\TenderPackageService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P7 — the three tender sheets as the BROWSER meets them: which screen offers
 * the button, who is allowed to see it, and what the sheet says on the day
 * there is nothing to put on it.
 *
 * The rendering itself is pinned by TenderFormPrintTest. What is pinned here is
 * the half that lives between the catalogue and the screen — and the empty
 * case, which is the case the owner meets FIRST: the demo database ships eight
 * employees and zero certificates, so F/SBD's first ever printing has an empty
 * table. A sheet that answers that by printing a bare grid teaches its reader
 * that the company has no certified staff. It has to say which question it
 * asked and that the answer was empty.
 */
class TenderPrintCatalogueTest extends ErpTestCase
{
    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-01 09:00:00');

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The catalogue hands each sheet to the screen that can actually draw it.
     *
     * F/SBD and F/DA hang off the TENDER PACKAGE, not off a certificate or an
     * asset: the package is the only record that knows the paket title, the
     * pemberi tugas and the tender number, and a sheet that rules all three is
     * not a sheet anybody can put in a bid envelope. If the catalogue ever
     * re-pointed them at `hr/employees` or `assets/assets`, the buttons would
     * still render — on a screen whose record cannot fill the header.
     */
    public function test_the_three_sheets_are_offered_on_the_screens_that_can_fill_their_headers(): void
    {
        $rows = collect(
            $this->actingAs($this->userWith(['crm.view']))
                ->getJson('api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        foreach ([
            'rkk' => 'crm/rkk-documents',
            'daftar-personil' => 'crm/tender-packages',
            'dukungan-alat' => 'crm/tender-packages',
        ] as $slug => $resource) {
            $this->assertArrayHasKey($slug, $rows, "Formulir [{$slug}] tidak ada di katalog cetak.");
            $this->assertSame($resource, $rows[$slug]['resource'], "Formulir [{$slug}] berjangkar di layar yang salah.");
            // idField 'id': tombolnya digambar dari rekaman layar itu sendiri.
            // Sebuah idField lain akan membuat formButtons() diam-diam tidak
            // menggambar tombolnya sama sekali (ia menyaring rekaman yang tidak
            // membawa field itu) — bug yang tidak berbunyi.
            $this->assertSame('id', $rows[$slug]['idField'] ?? 'id');
            $this->assertSame([], $rows[$slug]['params'] ?? []);
        }
    }

    /** Tanpa crm.view, ketiganya tidak ditawarkan sama sekali. */
    public function test_a_caller_without_crm_view_is_offered_none_of_them(): void
    {
        $slugs = array_column(
            $this->actingAs($this->userWith(['prj.view']))
                ->getJson('api/core/print/forms')
                ->assertOk()
                ->json('data'),
            'slug',
        );

        foreach (['rkk', 'daftar-personil', 'dukungan-alat'] as $slug) {
            $this->assertNotContains($slug, $slugs);
        }
    }

    /**
     * F/SBD pada basis data tanpa satu pun sertifikat — kasus DEMO hari ini.
     *
     * Yang tercetak adalah kalimatnya, bukan grid kosong: "belum ada personil
     * bersertifikat yang masih berlaku PADA TANGGAL INI" menjawab pertanyaan
     * yang benar-benar diajukan, dan tanggalnya ikut tercetak supaya pembacanya
     * tahu jawaban itu bertanggal.
     */
    public function test_the_personnel_sheet_with_no_certificates_says_so_and_names_its_date(): void
    {
        $html = $this->forms->html('daftar-personil', ['id' => $this->package()->id]);

        $this->assertStringContainsString('Belum ada personil bersertifikat yang masih berlaku', $html);
        $this->assertStringContainsString('PERSONIL PER TANGGAL', $html);
        $this->assertStringContainsString('01 September 2026', $html);
        // Judul paket dan pemberi tugas TETAP terisi: lembar kosong isinya,
        // bukan kosong kepalanya.
        $this->assertStringContainsString('Pembangunan Gedung Kantor Graha Sentosa', $html);
        $this->assertStringContainsString('PT Graha Sentosa Propertindo', $html);
    }

    /** F/DA pada register aset kosong — kalimatnya, bukan tabel bergaris tanpa sebab. */
    public function test_the_equipment_sheet_with_no_assets_says_so(): void
    {
        $html = $this->forms->html('dukungan-alat', ['id' => $this->package()->id]);

        $this->assertStringContainsString('Belum ada peralatan tercatat pada register aset', $html);
        $this->assertStringContainsString('027/PPBJ/GSP/2026', $html);
    }

    private function package(): TenderPackage
    {
        $lead = Lead::query()->create(['name' => 'Panitia Pengadaan', 'status' => 'new']);

        return app(TenderPackageService::class)->create([
            'lead_id' => $lead->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'owner_name' => 'PT Graha Sentosa Propertindo',
            'tender_number' => '027/PPBJ/GSP/2026',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Tim Tender',
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
