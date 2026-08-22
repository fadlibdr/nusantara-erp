<?php

namespace Tests\Feature\Finance;

use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Adopsi listing() di Modules/Finance: kontrak sort/jendela tanggal/meta yang
 * sama dengan ListingConcernTest, ditegakkan pada endpoint Finance sendiri —
 * daftar berisi uang tunduk pada mekanisme yang sama persis dengan daftar
 * lain, bukan varian lokal.
 *
 * Dua penjaga khas Finance ikut diuji: whitelist yang menolak kolom NYATA yang
 * sengaja tidak diiklankan (amount_paid — bukti whitelist yang memutuskan,
 * bukan keberadaan kolom), dan COA yang menolak SEMUA sort karena urutan kode
 * adalah hierarki bagan akun.
 */
class FinanceListingTest extends ErpTestCase
{
    use FinanceFixtures;

    /** Tiga invoice bertanggal beda supaya sort dan jendela tanggal terbedakan. */
    private function threeInvoices(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer);

        foreach ([
            ['INV-001', '2026-07-01', '2026-07-31', 100000],
            ['INV-002', '2026-07-15', '2026-08-14', 300000],
            ['INV-003', '2026-08-01', '2026-08-31', 200000],
        ] as [$code, $invoiceDate, $dueDate, $total]) {
            ArInvoice::query()->create([
                'code' => $code,
                'customer_id' => $customer->id,
                'contract_id' => $contract->id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'description' => "Penagihan {$code}",
                'dpp' => $total,
                'ppn_rate' => 0,
                'ppn_amount' => 0,
                'retention_withheld' => 0,
                'total' => $total,
                'amount_paid' => 0,
                'terbilang' => 'Terbilang uji',
                'status' => DocumentStatus::Approved,
            ]);
        }
    }

    // ---------------------------------------------------------------- sorting

    public function test_ar_invoices_sort_by_due_date_holds_across_a_page_boundary(): void
    {
        $this->threeInvoices();
        $admin = $this->adminUser();

        $pageOne = $this->actingAs($admin)
            ->getJson('/api/finance/ar-invoices?sort=due_date&dir=asc&per_page=2')
            ->assertOk();
        $this->assertSame(['INV-001', 'INV-002'], array_column($pageOne->json('data'), 'code'));

        $pageTwo = $this->actingAs($admin)
            ->getJson('/api/finance/ar-invoices?sort=due_date&dir=asc&per_page=2&page=2')
            ->assertOk();
        $this->assertSame(['INV-003'], array_column($pageTwo->json('data'), 'code'));

        $descending = $this->actingAs($admin)
            ->getJson('/api/finance/ar-invoices?sort=total&dir=desc')
            ->assertOk();
        $this->assertSame(['INV-002', 'INV-003', 'INV-001'], array_column($descending->json('data'), 'code'));
    }

    public function test_a_real_column_kept_off_the_whitelist_is_refused(): void
    {
        $this->threeInvoices();

        // amount_paid ADALAH kolom fin_ar_invoices — penolakan membuktikan
        // whitelist yang memutuskan. Ia sengaja tidak diiklankan karena tidak
        // ada kolom daftar yang membawanya (kunci tanpa kolom = tombol mati).
        $this->actingAs($this->adminUser())
            ->getJson('/api/finance/ar-invoices?sort=amount_paid')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_the_chart_of_accounts_refuses_every_sort_to_protect_coa_order(): void
    {
        // Urutan kode COA adalah hierarkinya — layar mengindentasi nama dari
        // kode, jadi 'code' pun ditolak, bukan hanya kolom asing.
        $this->actingAs($this->adminUser())
            ->getJson('/api/finance/accounts?sort=code')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    /**
     * The petty-cash drawer picker must offer only what
     * PettyCashFundService will accept. Without the code family filter the
     * combobox listed 1-1400 Persediaan, the operator picked it, and the
     * refusal arrived only after Simpan.
     */
    public function test_the_chart_of_accounts_can_be_narrowed_to_a_code_family(): void
    {
        $this->seedLedger(2026);
        // adminUser() INSERTs; calling it twice in one test violates the email
        // unique index. Capture once.
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->getJson('/api/finance/accounts?is_postable=1&code_prefix=1-12')
            ->assertOk();

        $codes = array_column($response->json('data'), 'code');

        $this->assertNotEmpty($codes, 'the demo chart has postable 1-12xx accounts');
        foreach ($codes as $code) {
            $this->assertStringStartsWith('1-12', $code);
        }

        // Unfiltered still returns the whole chart — the filter is opt-in.
        $all = $this->actingAs($admin)
            ->getJson('/api/finance/accounts?is_postable=1&per_page=500')
            ->assertOk()->json('data');

        $this->assertGreaterThan(count($codes), count($all));
    }

    // ------------------------------------------------------------ date window

    public function test_the_invoice_date_window_is_inclusive_on_both_bounds(): void
    {
        $this->threeInvoices();

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/finance/ar-invoices?date_from=2026-07-01&date_to=2026-07-15')
            ->assertOk();

        // Kedua batas ikut; urutan default orderByDesc(id) tetap berlaku.
        $this->assertSame(['INV-002', 'INV-001'], array_column($response->json('data'), 'code'));
    }

    /**
     * JournalController kehilangan dua ->when() tanggal lokalnya saat adopsi —
     * jendelanya kini milik listing(), dan hasilnya harus tetap sama.
     */
    public function test_journals_keep_their_date_window_after_the_local_whens_moved(): void
    {
        foreach ([['JV-001', '2026-07-01'], ['JV-002', '2026-07-15'], ['JV-003', '2026-08-01']] as [$code, $date]) {
            Journal::query()->create([
                'code' => $code,
                'journal_date' => $date,
                'description' => "Jurnal uji {$code}",
                'status' => 'draft',
            ]);
        }

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/finance/journals?date_from=2026-07-02&date_to=2026-08-01')
            ->assertOk();

        // Urutan default journal_date desc dipertahankan saat tidak ada sort.
        $this->assertSame(['JV-003', 'JV-002'], array_column($response->json('data'), 'code'));
    }

    // ------------------------------------------------------------ the contract

    public function test_ar_invoice_meta_advertises_whitelist_and_date_column_alongside_pagination(): void
    {
        $this->threeInvoices();

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/finance/ar-invoices')
            ->assertOk();

        $this->assertSame(['code', 'invoice_date', 'due_date', 'total', 'status'], $response->json('meta.sortable'));
        $this->assertSame('invoice_date', $response->json('meta.date_column'));
        $this->assertNull($response->json('meta.sort'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.current_page'));
    }

    /**
     * Meta ekstra ProjectCostController dulu MENGGANTIKAN meta paginasi (ok()
     * memakai meta kiriman apa adanya); lewat listing() keduanya digabung —
     * totals_by_category tidak boleh hilang, paginasi tidak boleh tergusur.
     */
    public function test_project_cost_totals_by_category_survive_next_to_pagination_meta(): void
    {
        $project = $this->makeProject();

        foreach ([['2026-07-01', 'material', 150000], ['2026-07-20', 'labor', 50000]] as [$date, $category, $amount]) {
            ProjectCost::query()->create([
                'project_id' => $project->id,
                'cost_date' => $date,
                'cost_category' => $category,
                'reference_type' => 'manual',
                'description' => 'Biaya uji',
                'amount' => $amount,
            ]);
        }

        $response = $this->actingAs($this->adminUser())
            ->getJson("/api/finance/project-costs?project_id={$project->id}")
            ->assertOk();

        $this->assertEqualsWithDelta(150000.0, $response->json('meta.totals_by_category.material'), 0.01);
        $this->assertEqualsWithDelta(50000.0, $response->json('meta.totals_by_category.labor'), 0.01);
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame('cost_date', $response->json('meta.date_column'));
    }

    /**
     * Tipuan whitelist khas: kolom yang diiklankan tapi salah ketik baru
     * meledak ketika diurutkan. Setiap endpoint Finance yang diadopsi ditanya
     * whitelist-nya sendiri lalu diurutkan per entri — typo gagal di sini,
     * bukan di bawah klik kasir. Daftarnya dari meta, tidak pernah duplikat.
     */
    public function test_every_finance_endpoint_answers_a_sort_on_each_advertised_column(): void
    {
        $admin = $this->adminUser();

        $endpoints = [
            '/api/finance/ar-invoices', '/api/finance/ap-bills', '/api/finance/payments',
            '/api/finance/journals', '/api/finance/project-costs', '/api/finance/accounts',
            '/api/finance/taxes', '/api/finance/bank-accounts', '/api/finance/bank-statements',
            '/api/finance/kasbon', '/api/finance/petty-cash-vouchers',
        ];

        foreach ($endpoints as $endpoint) {
            $meta = $this->actingAs($admin)->getJson($endpoint)->assertOk()->json('meta');

            $this->assertIsArray($meta['sortable'] ?? null, "{$endpoint} tidak mengiklankan meta.sortable");
            $this->assertArrayHasKey('date_column', $meta, "{$endpoint} tidak mengiklankan meta.date_column");

            foreach ($meta['sortable'] as $column) {
                $this->actingAs($admin)
                    ->getJson($endpoint.'?'.http_build_query(['sort' => $column, 'dir' => 'desc']))
                    ->assertOk();
            }
        }
    }
}
