<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Laporan baris PO terbuka — pemantauan pengiriman.
 *
 * Sebelum laporan ini, expected_date PO tidak dipakai di mana pun selain
 * tampilan dan qty vs qty_received hanya terlihat per dokumen: kiriman
 * terlambat baru ketahuan saat site kehabisan material, dan ekspeditor harus
 * membuka PO satu per satu untuk tahu apa yang belum datang.
 */
class PoOutstandingReportTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function vendor(string $name = 'PT Semen Distribusi Utama'): Vendor
    {
        return Vendor::query()->create([
            'name' => $name,
            'classification' => 'material',
            'is_pkp' => true,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<int, array{qty: float, received: float, price?: float}>  $lines
     */
    private function po(Vendor $vendor, ?string $expectedDate, array $lines, string $status = 'approved'): PurchaseOrder
    {
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-03-01',
            'expected_date' => $expectedDate,
            'payment_term_days' => 30,
            'subtotal' => 0,
            'discount_amount' => 0,
            'dpp' => 0,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'total' => 0,
            'status' => DocumentStatus::from($status),
        ]);

        $lineNo = 0;

        foreach ($lines as $line) {
            $price = $line['price'] ?? 15_000;
            $po->items()->create([
                'line_no' => ++$lineNo,
                'description' => 'Semen Portland 40 kg',
                'qty' => $line['qty'],
                'unit' => 'zak',
                'unit_price' => $price,
                'amount' => round($line['qty'] * $price, 2),
                'qty_received' => $line['received'],
            ]);
        }

        return $po;
    }

    private function report(array $query = []): array
    {
        return $this->getJson('/api/procurement/reports/outstanding?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_hanya_baris_kurang_terima_pada_po_approved_yang_muncul(): void
    {
        Sanctum::actingAs($this->adminUser());
        $vendor = $this->vendor();

        $approved = $this->po($vendor, '2026-03-20', [
            ['qty' => 10, 'received' => 4],   // terbuka — satu-satunya yang tampil
            ['qty' => 10, 'received' => 10],  // sudah penuh
        ]);
        $this->po($vendor, '2026-03-20', [['qty' => 5, 'received' => 0]], 'draft');   // belum komitmen
        $this->po($vendor, '2026-03-20', [['qty' => 5, 'received' => 2]], 'closed');  // sisanya diampuni saat tutup manual

        $report = $this->report();

        $this->assertSame(1, $report['summary']['total_lines']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame($approved->code, $report['rows'][0]['po_code']);
        $this->assertSame('PT Semen Distribusi Utama', $report['rows'][0]['vendor_name']);
        $this->assertEqualsWithDelta(6.0, $report['rows'][0]['outstanding_qty'], 0.001);
    }

    public function test_baris_telat_ditandai_dan_diurutkan_paling_atas(): void
    {
        Sanctum::actingAs($this->adminUser());
        $vendor = $this->vendor();

        $onTime = $this->po($vendor, now()->addDays(30)->toDateString(), [['qty' => 10, 'received' => 0]]);
        $dateless = $this->po($vendor, null, [['qty' => 10, 'received' => 0]]);
        $late = $this->po($vendor, now()->subDays(10)->toDateString(), [['qty' => 10, 'received' => 3]]);

        $report = $this->report();
        $rows = $report['rows'];

        // Telat duluan, janji-tanpa-tanggal paling akhir — janji tanpa tanggal
        // tidak bisa "lewat jadwal", hanya bisa dikejar.
        $this->assertSame([$late->code, $onTime->code, $dateless->code], array_column($rows, 'po_code'));

        $this->assertTrue($rows[0]['is_overdue']);
        $this->assertSame(10, $rows[0]['overdue_days']);
        $this->assertFalse($rows[1]['is_overdue']);
        $this->assertFalse($rows[2]['is_overdue']);
        $this->assertSame(1, $report['summary']['overdue_lines']);
    }

    public function test_nilai_sisa_adalah_qty_sisa_kali_harga_baris(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->po($this->vendor(), '2026-03-20', [['qty' => 10, 'received' => 4, 'price' => 200_000]]);

        $report = $this->report();

        $this->assertEqualsWithDelta(1_200_000, $report['rows'][0]['outstanding_value'], 0.01);
        $this->assertEqualsWithDelta(1_200_000, $report['summary']['total_outstanding_value'], 0.01);
    }

    public function test_saring_per_vendor(): void
    {
        Sanctum::actingAs($this->adminUser());

        $semen = $this->vendor();
        $besi = $this->vendor('PT Baja Nusantara');
        $this->po($semen, '2026-03-20', [['qty' => 10, 'received' => 0]]);
        $this->po($besi, '2026-03-20', [['qty' => 10, 'received' => 0]]);

        $report = $this->report(['vendor_id' => $besi->id]);

        $this->assertSame(1, $report['summary']['total_lines']);
        $this->assertSame('PT Baja Nusantara', $report['rows'][0]['vendor_name']);
    }

    public function test_laporan_dijaga_prc_view(): void
    {
        // Teknisi layanan tidak berurusan dengan komitmen pembelian lintas
        // vendor — harga seluruh PO terbuka bukan konsumsinya.
        $teknisi = Role::findOrCreate('teknisi-uji', 'web');
        $teknisi->syncPermissions(['svc.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Teknisi',
            'email' => 'teknisi@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($teknisi);

        Sanctum::actingAs($user);

        $this->getJson('/api/procurement/reports/outstanding')->assertForbidden();
    }
}
