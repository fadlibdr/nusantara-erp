<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Attachment;
use Modules\Core\Services\AttachmentService;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\SafetyIncident;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET core/projects/{id}/photos — galeri foto progres per proyek (Temuan 16).
 *
 * The photos exist, geotagged and validated, but scattered one document at a
 * time: assembling the lampiran for one termin meant opening every laporan
 * harian and BAST by hand. The gallery walks core_attachments for image-mime
 * files across every attachable type that REALLY belongs to a project — a
 * project_id column (or one hop through PO / subcontract), never a guess —
 * newest first, with the stored geotag riding along.
 *
 * Per-source permission follows the calendar rule: a caller sees a source's
 * photos only while holding that module's .view — a scanned nota on a vendor
 * bill is finance's to show, not the gallery's to leak.
 */
class ProjectPhotoGalleryTest extends ErpTestCase
{
    private AttachmentService $attachments;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->attachments = app(AttachmentService::class);

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
            'contract_value' => 48_500_000_000,
            // Kantor pusat BCA Thamrin-ish — the site the distance badge
            // measures against.
            'latitude' => -6.2000000,
            'longitude' => 106.8166666,
        ]);
    }

    // -------------------------------------------------------------- fixtures

    private function actAsHolderOf(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang Izin',
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** A one-pixel PNG — real bytes, so finfo agrees with the extension. */
    private function png(): string
    {
        return base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function pdf(): string
    {
        return base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function dailyReport(Project $project, string $date = '2026-08-03'): DailyReport
    {
        return DailyReport::query()->create([
            'code' => 'DR-'.$project->id.'-'.$date,
            'project_id' => $project->id,
            'report_date' => $date,
            'manpower_count' => 24,
            'activities' => 'Pengecoran lantai 3',
        ]);
    }

    private function bast(): Bast
    {
        return Bast::query()->create([
            'code' => 'BAST-2026-001',
            'project_id' => $this->project->id,
            'bast_type' => 'bast1',
            'handover_date' => '2026-08-05',
            'status' => 'draft',
        ]);
    }

    private function goodsReceipt(): GoodsReceipt
    {
        $vendor = Vendor::query()->create([
            'code' => 'VND-0001',
            'name' => 'PT Semen Distribusi Utama',
            'is_pkp' => true,
            'is_subcontractor' => false,
            'classification' => 'material',
            'status' => 'active',
        ]);
        $po = PurchaseOrder::query()->create([
            'code' => 'PO/2026/VIII/0001',
            'vendor_id' => $vendor->id,
            'project_id' => $this->project->id,
            'order_date' => '2026-08-01',
            'status' => 'approved',
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WH-PRJ-2026-001',
            'name' => 'Gudang Site Graha Sentosa',
        ]);

        return GoodsReceipt::query()->create([
            'code' => 'GRN/2026/VIII/0001',
            'warehouse_id' => $warehouse->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $vendor->id,
            'receipt_date' => '2026-08-04',
            'status' => 'posted',
        ]);
    }

    private function photoOn(object $document, string $name, array $position = []): Attachment
    {
        return $this->attachments->store($document, $name, $this->png(), null, null, $position);
    }

    // ---------------------------------------------------------------- listing

    public function test_lists_image_attachments_across_the_projects_documents_newest_first(): void
    {
        $this->actAsHolderOf('prj.view');

        $report = $this->dailyReport($this->project);
        $siteDaily = $this->photoOn($report, 'cor-lantai-3.png', [
            // ±150 m from the site coordinates above — inside the green badge.
            'latitude' => -6.2010000, 'longitude' => 106.8170000, 'accuracy_m' => 12,
        ]);
        $siteBast = $this->photoOn($this->bast(), 'serah-terima.png');
        $onProject = $this->photoOn($this->project, 'papan-proyek.png');

        // A PDF on the same report is a document, not a progress photo.
        $this->attachments->store($report, 'izin-cor.pdf', $this->pdf());

        // Another project's photo must never bleed into this gallery.
        $other = Project::query()->create([
            'code' => 'PRJ-2026-002', 'name' => 'Proyek Lain',
            'type' => 'construction', 'status' => 'active',
        ]);
        $this->photoOn($this->dailyReport($other), 'proyek-lain.png');

        // Stamp distinct capture dates so the order under test is the date
        // order, not the insertion order.
        Attachment::query()->whereKey($siteDaily->id)->update(['taken_at' => '2026-08-03 10:00:00']);
        Attachment::query()->whereKey($siteBast->id)->update(['taken_at' => '2026-08-05 09:00:00']);
        Attachment::query()->whereKey($onProject->id)->update(['taken_at' => '2026-08-01 08:00:00']);

        $response = $this->getJson("api/core/projects/{$this->project->id}/photos")->assertOk();
        $rows = $response->json('data');

        $this->assertSame(
            ['serah-terima.png', 'cor-lantai-3.png', 'papan-proyek.png'],
            array_column($rows, 'original_name'),
        );

        // Document context: the gallery names the document each photo hangs
        // off, so one click leads back to the laporan/BAST itself.
        $daily = collect($rows)->firstWhere('original_name', 'cor-lantai-3.png');
        $this->assertSame('projects/daily-reports', $daily['document']['slug']);
        $this->assertSame($report->code, $daily['document']['code']);

        // The stored geotag rides along, with the distance to the site.
        $this->assertSame('device', $daily['geo_source']);
        $this->assertNotNull($daily['latitude']);
        $this->assertEqualsWithDelta(150, $daily['distance_from_site_m'], 60);
        $this->assertNull(collect($rows)->firstWhere('original_name', 'serah-terima.png')['geo_source']);

        // meta.sources: honest per-source counts for the filter chips.
        $sources = collect($response->json('meta.sources'))->pluck('count', 'slug');
        $this->assertSame(1, $sources['projects/daily-reports']);
        $this->assertSame(1, $sources['projects/bast']);
        $this->assertSame(1, $sources['projects/projects']);
    }

    public function test_each_source_appears_only_for_its_view_permission(): void
    {
        $this->photoOn($this->dailyReport($this->project), 'harian.png');
        $this->photoOn($this->goodsReceipt(), 'material-tiba.png');

        // prj.view alone: the GRN photo belongs to inventory's readers.
        $this->actAsHolderOf('prj.view');
        $names = array_column($this->getJson("api/core/projects/{$this->project->id}/photos")->assertOk()->json('data'), 'original_name');
        $this->assertSame(['harian.png'], $names);

        // With inv.view as well, the GRN photo joins — one hop through the PO
        // is a real project linkage, not a guess.
        $this->actAsHolderOf('prj.view', 'inv.view');
        $names = array_column($this->getJson("api/core/projects/{$this->project->id}/photos")->assertOk()->json('data'), 'original_name');
        sort($names);
        $this->assertSame(['harian.png', 'material-tiba.png'], $names);
    }

    /**
     * est_boqs dan est_cost_budgets membawa project_id sendiri — tautan
     * selangsung laporan harian, bukan tebakan — dan keduanya attachable:
     * foto survei lokasi di BOQ dan lampiran RAP adalah bukti proyek yang
     * dulu tak pernah muncul di galeri. Izinnya est.view, aturan kalender
     * yang sama dengan sumber lain.
     */
    public function test_estimation_documents_are_gallery_sources_behind_est_view(): void
    {
        $boq = Boq::query()->create([
            'project_id' => $this->project->id,
            'title' => 'RAB Gedung Kantor Graha Sentosa',
        ]);
        $rap = CostBudget::query()->create([
            'boq_id' => $boq->id,
            'project_id' => $this->project->id,
            'target_margin_pct' => 12.5,
        ]);
        $this->photoOn($boq, 'survei-lokasi.png');
        $this->photoOn($rap, 'lampiran-rap.png');

        // prj.view saja: foto estimasi milik pembaca est.view.
        $this->actAsHolderOf('prj.view');
        $names = array_column($this->getJson("api/core/projects/{$this->project->id}/photos")->assertOk()->json('data'), 'original_name');
        $this->assertSame([], $names);

        $this->actAsHolderOf('prj.view', 'est.view');
        $response = $this->getJson("api/core/projects/{$this->project->id}/photos")->assertOk();
        $names = array_column($response->json('data'), 'original_name');
        sort($names);
        $this->assertSame(['lampiran-rap.png', 'survei-lokasi.png'], $names);

        // Chip per sumber ikut hadir, dengan hitungan yang jujur.
        $sources = collect($response->json('meta.sources'))->pluck('count', 'slug');
        $this->assertSame(1, $sources['estimation/boqs']);
        $this->assertSame(1, $sources['estimation/cost-budgets']);
    }

    /**
     * Paruh kedua penutupan temuan §7.7 (P6): laporan K3 bukan cuma bisa
     * MENERIMA foto (SafetyIncidentAttachmentTest), fotonya juga MUNCUL di
     * Galeri Proyek. Tanpa paku ini baris sumber di sources() bisa dihapus dan
     * tak satu uji pun merah — persis celah yang panduan pra-P6 keluhkan:
     * "fotonya tidak akan pernah muncul di Galeri Proyek".
     */
    public function test_safety_incident_photos_appear_in_the_gallery(): void
    {
        $incident = SafetyIncident::query()->create([
            'code' => 'K3/2026/VIII/0001',
            'project_id' => $this->project->id,
            'occurred_at' => '2026-08-04 09:30:00',
            'severity' => 'near_miss',
            'category' => 'struck_by_object',
            'description' => 'Material lepas dari sling saat pengangkatan; tidak ada korban.',
        ]);
        $this->photoOn($incident, 'kondisi-sling.png');

        $this->actAsHolderOf('prj.view');
        $response = $this->getJson("api/core/projects/{$this->project->id}/photos")->assertOk();

        $this->assertContains(
            'kondisi-sling.png',
            array_column($response->json('data'), 'original_name')
        );
        $sources = collect($response->json('meta.sources'))->pluck('count', 'slug');
        $this->assertSame(1, $sources['projects/safety-incidents']);
    }

    /**
     * Deviasi P6 #1: slug attachable projects/progress-measurements (P3) tidak
     * termuat di sources() DAN tidak disebut "Deliberately absent" — foto
     * bekisting/hasil cor/meteran pada opname owner diam-diam tak pernah
     * sampai ke galeri. Paku ini menahan barisnya agar tak terhapus lagi.
     */
    public function test_progress_measurement_photos_appear_in_the_gallery(): void
    {
        $opname = ProgressMeasurement::query()->create([
            'code' => 'OPN/2026/VIII/0001',
            'project_id' => $this->project->id,
            'contract_id' => 1,
            'measurement_no' => 1,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);
        $this->photoOn($opname, 'hasil-cor-lantai-3.png');

        // Opname proyek lain: fotonya bukan bukti progres proyek INI.
        $other = Project::query()->create([
            'code' => 'PRJ-2026-002', 'name' => 'Proyek Lain',
            'type' => 'construction', 'status' => 'active',
        ]);
        $this->photoOn(ProgressMeasurement::query()->create([
            'code' => 'OPN/2026/VIII/0002',
            'project_id' => $other->id,
            'contract_id' => 2,
            'measurement_no' => 1,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]), 'opname-proyek-lain.png');

        $this->actAsHolderOf('prj.view');
        $response = $this->getJson("api/core/projects/{$this->project->id}/photos")->assertOk();
        $names = array_column($response->json('data'), 'original_name');

        $this->assertContains('hasil-cor-lantai-3.png', $names);
        $this->assertNotContains('opname-proyek-lain.png', $names);

        $sources = collect($response->json('meta.sources'))->pluck('count', 'slug');
        $this->assertSame(1, $sources['projects/progress-measurements']);
    }

    public function test_refuses_a_caller_without_prj_view(): void
    {
        $this->actAsHolderOf('fin.view');

        $this->getJson("api/core/projects/{$this->project->id}/photos")->assertStatus(403);
    }

    public function test_date_window_bounds_the_gallery(): void
    {
        $this->actAsHolderOf('prj.view');

        $report = $this->dailyReport($this->project);
        $early = $this->photoOn($report, 'awal.png');
        $late = $this->photoOn($report, 'akhir.png');
        Attachment::query()->whereKey($early->id)->update(['taken_at' => '2026-08-01 08:00:00']);
        Attachment::query()->whereKey($late->id)->update(['taken_at' => '2026-08-06 08:00:00']);

        $names = array_column(
            $this->getJson("api/core/projects/{$this->project->id}/photos?date_from=2026-08-02&date_to=2026-08-06")
                ->assertOk()->json('data'),
            'original_name',
        );

        $this->assertSame(['akhir.png'], $names);
    }
}
