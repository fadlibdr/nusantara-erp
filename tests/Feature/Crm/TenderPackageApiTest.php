<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Modules\Core\Services\MethodLibraryService;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\TenderPackage;
use Modules\Crm\Services\QuotationService;
use Modules\Crm\Services\TenderPackageService;
use Modules\Crm\Services\TkdnService;
use Tests\ErpTestCase;

/**
 * P7 — the HTTP surface: a dossier created with its register in one save, the
 * addendum gap refused as a 422 in Indonesian, and the TKDN summary that never
 * answers without its coverage.
 */
class TenderPackageApiTest extends ErpTestCase
{
    /**
     * adminUser() creates a user row, so calling it twice inside one test
     * collides on users.email. One admin per test, memoised.
     */
    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= $this->adminUser();
    }

    private function lead(): Lead
    {
        return Lead::query()->create(['name' => 'Panitia Pengadaan', 'status' => 'new']);
    }

    public function test_a_package_is_created_with_its_document_register_in_one_save(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('api/crm/tender-packages', [
                'lead_id' => $this->lead()->id,
                'title' => 'Pembangunan Jembatan Kali Sunter',
                'owner_name' => 'Dinas Bina Marga DKI Jakarta',
                'submission_deadline' => '2026-09-20',
                'documents' => [
                    ['title' => 'Dokumen Pemilihan', 'chapter' => 'Bab I–IX', 'issued_date' => '2026-08-01'],
                    ['title' => 'Addendum I', 'issued_date' => '2026-08-10', 'addendum_no' => 1],
                ],
            ])
            ->assertCreated();

        $this->assertStringStartsWith('TND/', $response->json('data.code'));
        $this->assertCount(2, $response->json('data.documents'));
        $this->assertSame(1, $response->json('data.last_addendum_no'));
    }

    public function test_a_register_with_an_addendum_gap_is_refused_in_indonesian(): void
    {
        $package = app(TenderPackageService::class)->create([
            'lead_id' => $this->lead()->id,
            'title' => 'Paket uji',
        ]);

        $response = $this->actingAs($this->admin())
            ->putJson("api/crm/tender-packages/{$package->id}/documents", [
                'documents' => [
                    ['title' => 'Addendum I', 'issued_date' => '2026-08-10', 'addendum_no' => 1],
                    ['title' => 'Addendum III', 'issued_date' => '2026-08-20', 'addendum_no' => 3],
                ],
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('addendum ke-2', (string) $response->json('message'));
    }

    public function test_the_checklist_template_endpoint_serves_the_config_template(): void
    {
        $rows = $this->actingAs($this->admin())
            ->getJson('api/crm/tender-packages/checklist-template')
            ->assertOk()
            ->json('data');

        $this->assertSame(config('erp.tender.checklist_template'), $rows);
    }

    public function test_an_unknown_checklist_key_is_refused(): void
    {
        $package = app(TenderPackageService::class)->create([
            'lead_id' => $this->lead()->id,
            'title' => 'Paket uji checklist',
        ]);

        $this->actingAs($this->admin())
            ->putJson("api/crm/tender-packages/{$package->id}/checklist", [
                'checklist' => [['key' => 'butir_karangan', 'checked' => true]],
            ])
            ->assertStatus(422);
    }

    /**
     * Tidak ada endpoint yang menjawab persentase TKDN tanpa cakupannya —
     * klien yang bisa mengambilnya sendirian akan mencetaknya sendirian.
     */
    public function test_the_tkdn_summary_endpoint_always_carries_its_coverage(): void
    {
        $quotation = app(QuotationService::class)->create([
            'customer_id' => Customer::query()->create([
                'name' => 'PT Graha Sentosa Propertindo', 'is_pkp' => true,
                'payment_term_days' => 30, 'status' => 'active',
            ])->id,
            'title' => 'Penawaran uji TKDN',
            'scope_type' => 'construction',
            'items' => [
                ['description' => 'Struktur', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 100_000_000],
                ['description' => 'Arsitektur', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 100_000_000],
            ],
        ]);

        $worksheet = app(TkdnService::class)->createWorksheet(['quotation_id' => $quotation->id]);

        $this->actingAs($this->admin())
            ->putJson("api/crm/tkdn-worksheets/{$worksheet->id}/items", [
                'items' => [[
                    'quotation_item_id' => $quotation->items->first()->id,
                    'cost_group' => 'tenaga_kerja',
                    'description' => 'Upah pekerja Indonesia',
                    'amount' => 70_000_000,
                    'nationality' => 'wni',
                ]],
            ])
            ->assertOk();

        $summary = $this->actingAs($this->admin())
            ->getJson("api/crm/tkdn-worksheets/{$worksheet->id}/summary")
            ->assertOk()
            ->json('data');

        // assertEquals, tidak assertSame: JSON menuliskan 100.0 sebagai 100,
        // dan yang diuji di sini adalah angkanya, bukan tipe PHP-nya.
        $this->assertEquals(100.0, $summary['tkdn_pct']);
        $this->assertEquals(50.0, $summary['coverage_pct']);
        $this->assertEquals(100000000.0, $summary['unassessed_value']);
        $this->assertFalse($summary['fully_assessed']);
        $this->assertStringContainsString('Permenperin 35/2025', $summary['basis']);
    }

    public function test_the_method_library_list_hides_superseded_versions_by_default(): void
    {
        $library = app(MethodLibraryService::class);
        $first = $library->create([
            'category' => 'struktur',
            'work_package' => 'Pekerjaan pondasi bore pile',
            'title' => 'Metode bore pile Ø600',
        ]);
        $second = $library->publishRevision($first, ['title' => 'Metode bore pile Ø600 — revisi casing']);

        $codes = array_column(
            $this->actingAs($this->admin())->getJson('api/core/method-library')->assertOk()->json('data'),
            'code',
        );

        $this->assertSame([$second->code], $codes);

        $all = array_column(
            $this->actingAs($this->admin())
                ->getJson('api/core/method-library?with_superseded=1')->assertOk()->json('data'),
            'code',
        );

        $this->assertCount(2, $all);
        $this->assertContains($first->code, $all);
    }

    /**
     * Ditemukan lewat smoke curl, bukan lewat uji: aturan "hanya versi berlaku"
     * hidup di QuotationService, tetapi `method_library_id` tidak ada di
     * QuotationStoreRequest — jadi validated() MEMBUANG kuncinya tanpa suara,
     * penjaganya tidak pernah dipanggil, dan penawaran tersimpan tanpa metode
     * sambil menjawab 201. Uji ini menembus seluruh jalur HTTP karena di
     * situlah lubangnya, bukan di service.
     */
    public function test_the_quotation_endpoint_stores_the_method_and_refuses_a_superseded_one(): void
    {
        $library = app(MethodLibraryService::class);
        $first = $library->create([
            'category' => 'struktur',
            'work_package' => 'Pekerjaan pondasi bore pile',
            'title' => 'Metode bore pile Ø600',
        ]);
        $second = $library->publishRevision($first, ['title' => 'Metode bore pile Ø600 — revisi']);

        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo', 'is_pkp' => true,
            'payment_term_days' => 30, 'status' => 'active',
        ]);

        $payload = [
            'customer_id' => $customer->id,
            'title' => 'Penawaran dengan metode',
            'scope_type' => 'construction',
            'items' => [['description' => 'Pondasi', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1_000_000]],
        ];

        // Versi berlaku: tersimpan, dan benar-benar terbaca kembali.
        $created = $this->actingAs($this->admin())
            ->postJson('api/crm/quotations', $payload + ['method_library_id' => $second->id])
            ->assertCreated();

        $this->assertDatabaseHas('crm_quotations', [
            'id' => $created->json('data.id'),
            'method_library_id' => $second->id,
        ]);

        // Versi yang sudah digantikan: 422 yang menyebut penggantinya.
        $refused = $this->actingAs($this->admin())
            ->postJson('api/crm/quotations', $payload + ['method_library_id' => $first->id])
            ->assertStatus(422);

        $this->assertStringContainsString($second->code, (string) $refused->json('message'));
    }

    /**
     * CERMIN BACA dari uji di atas, dan lubangnya lebih berbahaya daripada
     * lubang tulisnya: QuotationResource tidak memulangkan `method_library_id`,
     * jadi rujukan metode WRITE-ONLY. SPA menyemai form edit dari endpoint show
     * (views/form.js) dan mengirim SETIAP field yang terlihat saat menyimpan —
     * termasuk yang null. Maka membuka penawaran yang sudah punya metode,
     * menekan Simpan tanpa menyentuh apa pun, MENGHAPUS rujukan itu tanpa suara.
     *
     * Uji ini menempuh persis jalan itu: POST dengan metode, GET, lalu PUT yang
     * disusun DARI hasil GET seperti yang dilakukan form — tanpa menyentuh
     * fieldnya. Kunci yang hilang dari Resource menjadi null di payload, dan
     * kolomnya kosong.
     */
    public function test_a_quotation_returns_its_method_and_an_untouched_save_preserves_it(): void
    {
        $entry = app(MethodLibraryService::class)->create([
            'category' => 'struktur',
            'work_package' => 'Pekerjaan bekisting kolom',
            'title' => 'Metode bekisting kolom sistem knock-down',
        ]);

        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo', 'is_pkp' => true,
            'payment_term_days' => 30, 'status' => 'active',
        ]);

        $id = $this->actingAs($this->admin())
            ->postJson('api/crm/quotations', [
                'customer_id' => $customer->id,
                'title' => 'Penawaran bekisting',
                'scope_type' => 'construction',
                'method_library_id' => $entry->id,
                'items' => [['description' => 'Bekisting kolom', 'qty' => 2, 'unit' => 'ls', 'unit_price' => 5_000_000]],
            ])
            ->assertCreated()
            ->json('data.id');

        $shown = $this->actingAs($this->admin())
            ->getJson("api/crm/quotations/{$id}")
            ->assertOk()
            ->json('data');

        // Terbaca kembali — dan berlabel, supaya pemilih di layar tidak perlu
        // menembak pustaka metode sekali lagi hanya untuk tahu namanya.
        $this->assertSame($entry->id, $shown['method_library_id'] ?? null);
        $this->assertSame($entry->code, $shown['method_library_code'] ?? null);
        $this->assertSame($entry->title, $shown['method_library_title'] ?? null);

        // Persis payload yang disusun views/form.js pada mode edit: setiap
        // field yang terlihat, disemai dari record hasil show, apa adanya.
        $this->actingAs($this->admin())
            ->putJson("api/crm/quotations/{$id}", [
                'customer_id' => $shown['customer_id'],
                'lead_id' => $shown['lead_id'],
                'title' => $shown['title'],
                'scope_type' => $shown['scope_type'],
                'method_library_id' => $shown['method_library_id'] ?? null,
                'valid_until' => $shown['valid_until'],
                'discount_amount' => $shown['discount_amount'],
                'ppn_rate' => $shown['ppn_rate'],
                'notes' => $shown['notes'],
                'items' => [['description' => 'Bekisting kolom', 'qty' => 2, 'unit' => 'ls', 'unit_price' => 5_000_000]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('crm_quotations', [
            'id' => $id,
            'method_library_id' => $entry->id,
        ]);
    }

    public function test_the_qualification_endpoints_are_read_only_and_gated_by_crm_view(): void
    {
        $this->actingAs($this->admin())
            ->getJson('api/crm/tender-qualification/personnel')->assertOk();
        $this->actingAs($this->admin())
            ->getJson('api/crm/tender-qualification/equipment')->assertOk();
        $this->actingAs($this->admin())
            ->getJson('api/crm/tender-qualification/subcontractors')->assertOk();

        // Baca saja: tidak ada rute tulis pada penyusun kualifikasi.
        $this->actingAs($this->admin())
            ->postJson('api/crm/tender-qualification/personnel', [])->assertStatus(405);
    }

    public function test_a_deleted_package_leaves_no_orphan_register_rows(): void
    {
        $package = app(TenderPackageService::class)->create([
            'lead_id' => $this->lead()->id,
            'title' => 'Paket yang dihapus',
        ]);

        app(TenderPackageService::class)->replaceDocuments($package, [
            ['title' => 'Dokumen Pemilihan', 'issued_date' => '2026-08-01'],
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("api/crm/tender-packages/{$package->id}")
            ->assertOk();

        // Kepalanya soft-delete (berkas lelang adalah catatan), barisnya ikut
        // lewat cascade hanya bila kepalanya benar-benar dihapus — jadi baris
        // register masih ada dan masih menunjuk kepalanya.
        $this->assertSoftDeleted('crm_tender_packages', ['id' => $package->id]);
        $this->assertNull(TenderPackage::query()->find($package->id));
        $this->assertDatabaseHas('crm_tender_documents', ['tender_package_id' => $package->id]);
    }
}
