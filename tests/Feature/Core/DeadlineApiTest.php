<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Attachment;
use Modules\Core\Support\WatchedDeadlines;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET core/deadlines — the tenggat screen's live truth.
 *
 * The endpoint re-runs the same scan the daily command runs, filtered to the
 * permissions the CALLER holds: a notification can be read and forgotten, but
 * an unresolved deadline stays on this screen. Like search, "nothing here"
 * and "nothing you may see" must be the same answer.
 */
class DeadlineApiTest extends ErpTestCase
{
    private const TODAY = '2026-08-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

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

    /** Two findings for two different permissions: prc.update and hr.update. */
    private function seedTwoModulesOfTrouble(): void
    {
        $vendor = Vendor::query()->create([
            'name' => 'PT Sumber Makmur Elektrindo',
            'is_subcontractor' => false,
            'classification' => 'material',
            'status' => 'active',
        ]);
        PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-02-10',
            'expected_date' => '2026-03-01',
            'total' => 232_500_000,
            'status' => 'approved',
        ]);

        Employee::query()->create([
            'code' => 'EMP-0007',
            'name' => 'Joko Susilo',
            'nik_ktp' => str_pad('7', 16, '3', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2025-01-01',
            'employment_type' => 'kontrak',
            'position' => 'Pelaksana',
            'department' => 'proyek',
            'base_salary' => 0,
            'status' => 'active',
        ]);
    }

    public function test_the_endpoint_returns_only_entries_whose_permission_the_caller_holds(): void
    {
        $this->seedTwoModulesOfTrouble();
        $this->actAsHolderOf('prc.update');

        $response = $this->getJson('/api/core/deadlines')->assertOk();

        $keys = array_column($response->json('data'), 'key');
        $this->assertContains('po_expected', $keys);
        $this->assertNotContains('pkwt_end', $keys);

        $po = collect($response->json('data'))->firstWhere('key', 'po_expected');
        $this->assertSame('lewat', $po['tier']);
        $this->assertSame(1, $po['count']);
        $this->assertSame('2026-03-01', $po['items'][0]['date']);
        $this->assertSame(-153, $po['items'][0]['days']);
        $this->assertSame(self::TODAY, $response->json('meta.today'));
    }

    public function test_an_hr_caller_sees_the_pkwt_alarm_and_not_procurement(): void
    {
        $this->seedTwoModulesOfTrouble();
        $this->actAsHolderOf('hr.update');

        $keys = array_column($this->getJson('/api/core/deadlines')->assertOk()->json('data'), 'key');

        $this->assertContains('pkwt_end', $keys);
        $this->assertNotContains('po_expected', $keys);
    }

    public function test_a_caller_with_no_relevant_permission_gets_an_empty_list(): void
    {
        $this->seedTwoModulesOfTrouble();
        $this->actAsHolderOf('inv.view');

        $this->getJson('/api/core/deadlines')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => [
                'today' => self::TODAY,
                // Adding a watcher is adding an array entry — this must not
                // be a second place to update.
                'checked' => count(WatchedDeadlines::entries()),
                'skipped' => 0,
            ]]);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/core/deadlines')->assertUnauthorized();
    }

    // ------------------------------------------------- payload ↔ layar

    /**
     * Klausa 'detail' dikirim PER BARIS, dan layar Tenggat harus merendernya.
     *
     * Ini pasangan yang putus di F-8: pemberitahuan 08.30 berbunyi lengkap
     * ("sertifikat-kalibrasi.pdf berlaku s/d 7 Sep 2026 — 3 hari lalu;
     * menempel pada Pesanan pembelian #1") sementara layar untuk temuan yang
     * SAMA hanya menyebut nama berkasnya — dan nama berkas bukan identitas
     * dokumen apa pun. Entri lampiran adalah yang pertama yang `display`-nya
     * bukan kode dokumen, jadi tanpa klausa ini barisnya tidak bisa dikenali;
     * entri lain (ar_invoice_due) kehilangan keterangannya diam-diam.
     *
     * Dua sisi dipaku sekaligus, karena satu sisi saja hijau untuk pasangan
     * yang putus: server benar-benar mengirimnya, dan tenggat.js benar-benar
     * membacanya.
     */
    public function test_the_row_clause_is_sent_by_the_server_and_read_by_the_screen(): void
    {
        $this->seedTwoModulesOfTrouble();
        $order = PurchaseOrder::query()->sole();

        Attachment::query()->create([
            'attachable_type' => PurchaseOrder::class,
            'attachable_id' => $order->id,
            'disk' => 'local',
            'path' => 'attachments/polis-car.pdf',
            'original_name' => 'polis-car.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 64,
            'sha256' => str_repeat('a', 64),
            'valid_until' => '2026-07-30',
        ]);

        $this->actAsHolderOf('prc.update');

        $finding = collect($this->getJson('/api/core/deadlines')->assertOk()->json('data'))
            ->firstWhere('key', 'attachment_valid_until_prc');

        $this->assertNotNull($finding, 'Lampiran kedaluwarsa pada PO tidak muncul di payload.');
        $this->assertSame('polis-car.pdf', $finding['items'][0]['code']);
        $this->assertSame(
            'menempel pada Pesanan pembelian #'.$order->id,
            $finding['items'][0]['detail'],
            'Server tidak lagi mengirim klausa dokumen induk per baris.',
        );

        $this->assertStringContainsString(
            'item.detail',
            $this->screenCode(),
            'Layar Tenggat tidak merender item.detail — barisnya menyebut nama berkas tanpa dokumennya, '
            .'sementara pemberitahuan 08.30 untuk temuan yang sama menyebut keduanya.',
        );
    }

    /**
     * "Hari ini" adalah kalimat yang SAMA di ketiga permukaan.
     *
     * WatchedDeadlines::sentence() menangani days === 0 di KEDUA tier — LEWAT
     * untuk tanggal yang telat pada hari kedatangannya, MENIPIS untuk entri
     * valid_through_end pada hari terakhirnya. Kartu lampiran menulis "hari
     * ini" juga. Layar Tenggat yang menuliskannya "0 hari lagi" membuat satu
     * berkas punya dua status pada hari yang sama.
     *
     * Yang dipaku: cabang days === 0 berdiri SEBELUM percabangan tier di
     * umur(), bukan di dalam salah satunya.
     */
    public function test_the_screen_writes_hari_ini_in_both_tiers_like_the_notification(): void
    {
        $screen = $this->screenCode();

        $this->assertSame(
            1,
            preg_match('/function umur\(item, tier\) \{(.*?)\n\}/s', $screen, $matches),
            'Fungsi umur() tidak ditemukan di tenggat.js; uji ini tidak lagi menjaga apa pun.',
        );

        $body = $matches[1];
        $hariIni = strpos($body, "if (days === 0) return 'hari ini';");

        $this->assertNotFalse($hariIni, 'umur() tidak punya cabang days === 0 yang berlaku untuk kedua tier.');
        $this->assertLessThan(
            strpos($body, 'hari lalu'),
            $hariIni,
            'Cabang "hari ini" berada di bawah percabangan tier — hari terakhir sebuah masa berlaku '
            .'akan terbaca "0 hari lagi" di layar sementara kotak masuk menulis "hari ini".',
        );
    }

    /**
     * tenggat.js TANPA komentarnya.
     *
     * Uji yang mencari sebuah pola di seluruh berkas hijau ketika polanya
     * hanya ada di dalam komentar yang menjanjikannya — cacat yang sudah
     * terjadi sekali di kampanye ini (AttachmentSpaPolicyTest menjaga sebuah
     * komentar, bukan cabang kodenya). Blok /* … *\/ dan baris yang seluruhnya
     * komentar dilucuti dulu, jadi yang tersisa hanya kode.
     */
    private function screenCode(): string
    {
        $source = (string) file_get_contents(public_path('app/js/views/tenggat.js'));

        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//[^\n]*$#m', '', $source);
    }
}
