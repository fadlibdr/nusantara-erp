<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Notification;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\TenderPackage;
use Modules\Crm\Services\TenderPackageService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P7 — berkas satu lelang: register dokumennya, BA aanwijzing, dan checklist
 * kelengkapan yang diisi dari template.
 */
class TenderPackageTest extends ErpTestCase
{
    private function service(): TenderPackageService
    {
        return app(TenderPackageService::class);
    }

    private function makePackage(): TenderPackage
    {
        $lead = Lead::query()->create([
            'name' => 'Panitia Pengadaan Dinas Bina Marga',
            'company_name' => 'Dinas Bina Marga DKI',
            'status' => 'new',
        ]);

        return $this->service()->create([
            'lead_id' => $lead->id,
            'title' => 'Pembangunan Jembatan Kali Sunter',
            'owner_name' => 'Dinas Bina Marga DKI Jakarta',
            'tender_number' => '027/PPBJ/BM/2026',
            'submission_deadline' => '2026-09-20',
        ]);
    }

    public function test_a_package_is_numbered_and_hangs_off_its_lead(): void
    {
        $package = $this->makePackage();

        $this->assertStringStartsWith('TND/', $package->code);
        $this->assertNotNull($package->lead_id);
    }

    // -------------------------------------------- register dokumen & addendum

    public function test_the_document_register_accepts_the_original_and_its_addenda_in_order(): void
    {
        $package = $this->makePackage();

        $this->service()->replaceDocuments($package, [
            ['title' => 'Dokumen Pemilihan', 'chapter' => 'Bab I–IX', 'issued_date' => '2026-08-01'],
            ['title' => 'Addendum I Dokumen Pemilihan', 'chapter' => 'Bab IV',
                'issued_date' => '2026-08-10', 'addendum_no' => 1],
            ['title' => 'Addendum II — perubahan BoQ', 'chapter' => 'Bab XII',
                'issued_date' => '2026-08-18', 'addendum_no' => 2],
        ]);

        $documents = $package->fresh()->documents;

        $this->assertCount(3, $documents);
        $this->assertNull($documents[0]->addendum_no, 'Terbitan asli tidak bernomor addendum.');
        $this->assertSame([1, 2], $documents->skip(1)->pluck('addendum_no')->all());
        $this->assertSame(2, $package->fresh()->lastAddendumNo());
    }

    /**
     * Register yang memuat Addendum III tanpa Addendum II berarti ada satu
     * dokumen yang tidak pernah kita terima — dan penawaran yang disusun di
     * atasnya disusun di atas informasi yang hilang.
     */
    public function test_a_gap_in_the_addendum_numbering_is_refused_and_names_the_missing_one(): void
    {
        $package = $this->makePackage();

        try {
            $this->service()->replaceDocuments($package, [
                ['title' => 'Dokumen Pemilihan', 'issued_date' => '2026-08-01'],
                ['title' => 'Addendum I', 'issued_date' => '2026-08-10', 'addendum_no' => 1],
                ['title' => 'Addendum III', 'issued_date' => '2026-08-20', 'addendum_no' => 3],
            ]);
            $this->fail('Register dengan lompatan nomor addendum seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('addendum ke-2', $e->getMessage());
        }

        $this->assertSame(0, $package->fresh()->documents()->count(), 'Register ditolak utuh, bukan separuh.');
    }

    public function test_two_rows_claiming_the_same_addendum_number_are_refused(): void
    {
        $package = $this->makePackage();

        $this->expectException(ValidationException::class);

        $this->service()->replaceDocuments($package, [
            ['title' => 'Addendum I', 'issued_date' => '2026-08-10', 'addendum_no' => 1],
            ['title' => 'Addendum I (revisi)', 'issued_date' => '2026-08-11', 'addendum_no' => 1],
        ]);
    }

    // ---------------------------------------------------------- checklist json

    public function test_the_checklist_round_trips_as_a_snapshot_of_the_template(): void
    {
        $package = $this->makePackage();

        $this->service()->setChecklist($package, [
            ['key' => 'surat_penawaran', 'checked' => true, 'notes' => 'meterai 10.000 terpasang'],
            ['key' => 'rkk', 'checked' => false],
        ]);

        $stored = $package->fresh()->checklist;

        $this->assertIsArray($stored);
        $this->assertCount(count(config('erp.tender.checklist_template')), $stored,
            'Checklist tersimpan utuh: butir yang tidak dikirim tetap ada, belum dicentang.');

        $byKey = collect($stored)->keyBy('key');

        $this->assertTrue($byKey['surat_penawaran']['checked']);
        $this->assertSame('meterai 10.000 terpasang', $byKey['surat_penawaran']['notes']);
        // Label dan grup ikut tersimpan — snapshot, bukan sekadar kunci.
        $this->assertSame('Surat penawaran bermeterai', $byKey['surat_penawaran']['label']);
        $this->assertSame('administrasi', $byKey['surat_penawaran']['group']);
        $this->assertFalse($byKey['rkk']['checked']);
        $this->assertFalse($byKey['jaminan_penawaran']['checked']);
    }

    /**
     * Menyunting template setelah checklist diisi tidak boleh menulis ulang
     * lembar yang sudah dijawab: yang tersimpan adalah snapshot-nya.
     */
    public function test_editing_the_template_afterwards_does_not_rewrite_a_stored_checklist(): void
    {
        $package = $this->makePackage();
        $this->service()->setChecklist($package, [['key' => 'sbu', 'checked' => true]]);

        config(['erp.tender.checklist_template' => [
            ['key' => 'sbu', 'group' => 'kualifikasi', 'label' => 'JUDUL BARU YANG LAIN'],
        ]]);

        $stored = collect($package->fresh()->checklist)->keyBy('key');

        $this->assertSame('Sertifikat Badan Usaha (SBU)', $stored['sbu']['label']);
        $this->assertTrue($stored['sbu']['checked']);
    }

    // ------------------------------------------------- tenggat pemasukan

    /**
     * Batas pemasukan lelang adalah satu-satunya tenggat pada daftar
     * WatchedDeadlines yang tidak bisa diperbaiki setelah lewat, dan hari
     * BATASNYA masih hari yang bisa diselamatkan — jadi hari itu berbunyi
     * MENIPIS, bukan LEWAT (valid_through_end).
     */
    public function test_the_submission_deadline_is_watched_and_its_last_day_still_counts(): void
    {
        Carbon::setTestNow('2026-09-04 08:00:00');

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('tender', 'web');
        $role->syncPermissions(['crm.create']);
        User::query()->create([
            'name' => 'Tim Tender', 'email' => 'tender@test.local',
            'password' => 'password', 'is_active' => true,
        ])->assignRole('tender');

        $package = $this->makePackage();
        $package->update(['submission_deadline' => '2026-09-04']); // hari ini

        $this->artisan('erp:deadline-watch')->assertExitCode(0);

        $titles = Notification::query()->where('event', Notification::SYSTEM)->pluck('title')->all();

        $this->assertContains('Paket tender mendekati batas pemasukan', $titles);
        $this->assertNotContains('Paket tender lewat batas pemasukan', $titles);

        Carbon::setTestNow();
    }

    public function test_a_checklist_key_outside_the_template_is_refused(): void
    {
        $package = $this->makePackage();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('kelengkapan_karangan');

        $this->service()->setChecklist($package, [
            ['key' => 'kelengkapan_karangan', 'checked' => true],
        ]);
    }
}
