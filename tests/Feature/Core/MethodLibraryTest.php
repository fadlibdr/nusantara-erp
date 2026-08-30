<?php

namespace Tests\Feature\Core;

use Illuminate\Validation\ValidationException;
use Modules\Core\Models\MethodLibraryEntry;
use Modules\Core\Services\MethodLibraryService;
use Modules\Core\Support\AttachableDocuments;
use Modules\Crm\Models\Customer;
use Modules\Crm\Services\QuotationService;
use Tests\ErpTestCase;

/**
 * P7 — pustaka metode kerja (core_method_library), dirujuk dari penawaran
 * sebagai "Metode Pelaksanaan".
 */
class MethodLibraryTest extends ErpTestCase
{
    private function library(): MethodLibraryService
    {
        return app(MethodLibraryService::class);
    }

    private function makeEntry(array $data = []): MethodLibraryEntry
    {
        return $this->library()->create(array_merge([
            'category' => 'struktur',
            'work_package' => 'Pekerjaan pondasi bore pile',
            'title' => 'Metode pelaksanaan bore pile Ø600',
            'summary' => 'Urutan kerja, alat utama, dan pengendalian mutu.',
            'effective_date' => '2026-01-15',
        ], $data));
    }

    public function test_a_new_method_starts_at_version_one_and_is_the_current_one(): void
    {
        $entry = $this->makeEntry();

        $this->assertStringStartsWith('MTD/', $entry->code);
        $this->assertSame(1, $entry->version);
        $this->assertTrue($entry->isCurrent());
        $this->assertTrue($entry->is($this->library()->current('struktur', 'Pekerjaan pondasi bore pile')));
    }

    public function test_a_revision_becomes_version_two_and_supersedes_its_predecessor(): void
    {
        $first = $this->makeEntry();

        $second = $this->library()->publishRevision($first, [
            'title' => 'Metode pelaksanaan bore pile Ø600 — revisi casing',
        ]);

        $this->assertSame(2, $second->version);
        $this->assertTrue($second->isCurrent());

        $first->refresh();
        $this->assertFalse($first->isCurrent());
        $this->assertSame($second->id, $first->superseded_by_id);

        // Versi baru mewarisi kategori & paket kerjanya: sebuah revisi yang
        // bisa pindah paket bukan revisi.
        $this->assertSame($first->category, $second->category);
        $this->assertSame($first->work_package, $second->work_package);
    }

    public function test_the_library_is_attachable_so_a_method_can_carry_its_pptx(): void
    {
        // P0-D sudah mengizinkan pptx/docx; yang kurang hanyalah dokumen yang
        // boleh membawanya.
        $this->assertTrue(AttachableDocuments::has('core/method-library'));
        $this->assertSame(MethodLibraryEntry::class, AttachableDocuments::classFor('core/method-library'));
    }

    // ------------------------------------------- rujukan dari penawaran

    public function test_a_quotation_may_reference_a_current_method(): void
    {
        $entry = $this->makeEntry();
        $quotation = $this->makeQuotation(['method_library_id' => $entry->id]);

        $this->assertSame($entry->id, $quotation->method_library_id);
        $this->assertTrue($entry->is($quotation->methodLibraryEntry));
    }

    /**
     * Sebuah penawaran yang mengutip metode yang sudah digantikan mengutip
     * dokumen yang sudah ditarik. 422-nya menyebut versi yang berlaku, supaya
     * pembuatnya tahu harus menunjuk ke mana.
     */
    public function test_a_quotation_may_not_reference_a_superseded_method(): void
    {
        $first = $this->makeEntry();
        $second = $this->library()->publishRevision($first, ['title' => 'Revisi']);

        try {
            $this->makeQuotation(['method_library_id' => $first->id]);
            $this->fail('Penawaran seharusnya menolak metode yang sudah digantikan.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($second->code, $e->getMessage());
        }
    }

    private function makeQuotation(array $data = [])
    {
        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'legal_name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        return app(QuotationService::class)->create(array_merge([
            'customer_id' => $customer->id,
            'title' => 'Pembangunan gedung kantor',
            'scope_type' => 'construction',
            'valid_until' => '2026-12-31',
            'items' => [
                ['description' => 'Pekerjaan pondasi', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 500_000_000],
            ],
        ], $data));
    }
}
