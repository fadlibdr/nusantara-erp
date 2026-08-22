<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Database\Seeders\RoleSeeder;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\ServiceDesk\Enums\FieldReportStatus;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\Ticket;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * T13, diputus pemilik 22 Agustus 2026: teknisi menerima inv.post.
 *
 * Temuan yang ditutup: mengakui (acknowledge) laporan lapangan yang memuat
 * suku cadang menjalankan posting persediaan penuh — stok keluar gudang pada
 * moving average, jurnal Dr 6-4100 / Cr 1-1400 — sehingga endpoint-nya
 * meminta inv.post. Izin itu hanya dipegang admin, jadi teknisi boleh menulis
 * laporan kunjungannya sendiri tetapi hanya admin yang bisa mengakui laporan
 * yang memakai suku cadang. Kunjungan servis pun menggantung di Submitted dan
 * memblokir tutup buku bulannya.
 *
 * Harga keputusan ini disebut jujur di migrasi 000242 dan diagendakan di sini
 * lewat pin lima izin: inv.post menjaga delapan rute Inventory, bukan hanya
 * acknowledge, jadi teknisi kini bisa mem-posting/membatalkan dokumen stok
 * draft mana pun yang bisa ia jangkau dengan inv.view. Pemilik menerimanya;
 * satu revokePermissionTo membalikkannya.
 */
class TeknisiInventoryPostingPermissionTest extends ErpTestCase
{
    use InventoryFixtures;

    /** Cross-module id: HrPayroll owns hr_employees, there is no FK to satisfy. */
    private const TECHNICIAN_ID = 7;

    private const MIGRATION = 'Modules/Iam/Database/Migrations/2026_08_22_000242_give_the_teknisi_role_inv_post.php';

    private Warehouse $gudang;

    private Item $kamera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->gudang = $this->makeWarehouse('WH-PUSAT');
        $this->kamera = $this->makeItem('CCTV Dome 4MP', [
            'unit' => 'unit',
            'item_type' => ItemType::Sparepart,
        ]);

        // 30 unit @ Rp 1.850.000 — posisi WH-PUSAT pada audit T13.
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');
    }

    private function role(): Role
    {
        return Role::query()->where('name', 'teknisi')->where('guard_name', 'web')->firstOrFail();
    }

    private function teknisiUser(): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Joko Susilo',
            'email' => 'teknisi@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole('teknisi');

        return $user;
    }

    public function test_role_teknisi_memegang_inv_post(): void
    {
        $this->assertTrue(
            $this->role()->hasPermissionTo('inv.post'),
            'Tanpa inv.post hanya admin yang bisa mengakui kunjungan bersuku-cadang, '
            .'dan laporan Submitted menggantung sampai memblokir tutup buku bulannya.',
        );
    }

    /**
     * PAGAR REGRESI atas pelebaran yang diterima pemilik: tepat LIMA izin,
     * disebut satu per satu. inv.post sudah lebih dari yang dibutuhkan
     * acknowledge sendirian (delapan rute Inventory ikut terbuka), jadi izin
     * berikutnya tidak boleh menyusup diam-diam lewat seeder — ia harus datang
     * dengan keputusan pemilik dan mengubah daftar ini dengan sadar.
     */
    public function test_teknisi_memegang_tepat_lima_izin_yang_disebut(): void
    {
        $this->assertEqualsCanonicalizing(
            ['svc.view', 'svc.create', 'svc.update', 'inv.view', 'inv.post'],
            $this->role()->permissions->pluck('name')->all(),
            'Bentuk role teknisi berubah tanpa keputusan yang tercatat — '
            .'cocokkan dengan RoleSeeder dan migrasi 2026_08_22_000242.',
        );
    }

    /**
     * RoleSeeder hanya membentuk instalasi baru; role erp1 di-seed jauh
     * sebelum keputusan ini, jadi migrasinya yang harus melakukan operasi
     * yang sama di sana. Ini menciptakan ulang keadaan pra-22-Agustus lalu
     * menjalankannya — dan menjalankan down() karena komentar migrasinya
     * menjanjikan satu revokePermissionTo membalikkan keputusan.
     */
    public function test_migrasi_memberi_grant_pada_role_teknisi_yang_sudah_ada_dan_down_mencabutnya(): void
    {
        $this->role()->revokePermissionTo('inv.post');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->role()->hasPermissionTo('inv.post'));

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $this->assertTrue($this->role()->hasPermissionTo('inv.post'));

        $migration->down();

        $this->assertFalse($this->role()->hasPermissionTo('inv.post'));
        // Dicabut dari role-nya saja — izinnya sendiri kanon dan admin tetap
        // memegangnya, jadi jalur darurat lama (admin yang mengakui) kembali.
        $this->assertTrue(
            Role::query()->where('name', 'admin')->where('guard_name', 'web')
                ->firstOrFail()->hasPermissionTo('inv.post'),
        );
    }

    /**
     * Repro T13 dari ujung ke ujung, kedua sisinya dalam satu alur: role
     * pra-grant ditolak pada panggilan yang PERSIS sama yang lolos setelah
     * migrasi memberi grant — bukti bahwa yang berubah adalah izinnya, bukan
     * endpoint-nya.
     */
    public function test_teknisi_mengakui_kunjungan_bersuku_cadang_dan_stok_keluar_atas_namanya(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        Sanctum::actingAs($this->teknisiUser());

        // Keadaan pra-22-Agustus: tanpa inv.post panggilan itu ditolak dan
        // tidak satu unit pun bergerak.
        $this->role()->revokePermissionTo('inv.post');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson("/api/servicedesk/field-reports/{$report->id}/acknowledge", [
            'customer_sign_name' => 'Darto Prasetyo',
        ])->assertForbidden();

        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(FieldReportStatus::Submitted, $report->fresh()->status);

        // Operasi erp1: migrasi memberi grant pada role yang sudah ada.
        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $this->postJson("/api/servicedesk/field-reports/{$report->id}/acknowledge", [
            'customer_sign_name' => 'Darto Prasetyo',
        ])->assertOk()->assertJsonPath('data.status', 'acknowledged');

        // 30 - 3 = 27 unit; satu bon posted, tertanggal pada kunjungannya —
        // stok keluar di bawah nama teknisi yang melakukan kunjungan itu.
        $this->assertSame(27.0, $this->balanceQty($this->gudang, $this->kamera));
        $issue = Issue::query()->where('field_report_id', $report->id)->sole();
        $this->assertSame('2026-06-10', $issue->issue_date->toDateString());
    }

    /**
     * @param  array<int, array{0: Item, 1: float}>  $parts
     */
    private function submittedReport(array $parts = []): FieldReport
    {
        $ticket = Ticket::create([
            'customer_id' => 1, // crm_customers.id (cross-module, no FK)
            'title' => 'Kamera lobi mati total',
            'priority' => 'high',
            'reported_at' => '2026-06-09 08:00:00',
        ]);

        $report = FieldReport::create([
            'ticket_id' => $ticket->id,
            'report_date' => '2026-06-10',
            'technician_employee_id' => self::TECHNICIAN_ID,
            'warehouse_id' => $this->gudang->id,
            'findings' => '3 unit kamera dome lobi mati total.',
            'actions_taken' => 'Penggantian 3 unit CCTV Dome 4MP.',
            'status' => FieldReportStatus::Submitted,
        ]);

        foreach ($parts as [$item, $qty]) {
            $report->parts()->create(['item_id' => $item->id, 'qty' => $qty]);
        }

        return $report->refresh();
    }
}
