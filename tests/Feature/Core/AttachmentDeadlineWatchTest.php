<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Core\Models\Attachment;
use Modules\Core\Models\Notification;
use Modules\Core\Services\AttachmentService;
use Modules\Core\Support\AttachableDocuments;
use Modules\Core\Support\CalendarEvents;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Finance\Models\ApBill;
use Modules\HrPayroll\Models\Certificate;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pengawas masa berlaku lampiran (F-8) — satu kolom, dua belas audiens.
 *
 * Empat hal yang membuat atau menggagalkan paket ini dipaku di sini:
 *
 *  A. TANPA MASA BERLAKU ADALAH KEADAAN NORMAL. Ribuan foto lapangan tanpa
 *     tanggal tidak melahirkan satu pun alarm DAN tidak satu pun baris BLIND.
 *  B. IZIN PER BARIS. Lampiran sertifikat karyawan berbunyi ke hr.update,
 *     lampiran tagihan vendor ke fin.update, dan tidak seorang pun membaca
 *     kelompok milik modul yang bukan haknya.
 *  C. INDUK YANG SUDAH TIDAK ADA TIDAK BERBUNYI — dihapus lunak, dihapus
 *     permanen, atau kelas yang tidak ada di registri.
 *  D. HARI TERAKHIR MASIH BERLAKU — kartu lampiran dan kotak masuk 08.30
 *     tidak boleh menyebut berkas yang sama dengan dua status berbeda.
 */
class AttachmentDeadlineWatchTest extends ErpTestCase
{
    use FinanceFixtures;

    private const TODAY = '2026-08-01';

    private AttachmentService $attachments;

    private ApBill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
        Storage::fake('local');
        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->attachments = app(AttachmentService::class);
        $this->bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'description' => 'Polis CAR',
            'dpp' => 10_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-F8W',
        ]);
    }

    // -------------------------------------------------------------- fixtures

    private function pdf(): string
    {
        return base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function attach(object $document, string $filename, ?string $validUntil): Attachment
    {
        return $this->attachments->store($document, $filename, $this->pdf(), null, null, [], $validUntil);
    }

    private function watch(): void
    {
        $this->artisan('erp:deadline-watch')->assertExitCode(0);
    }

    private function alarms(?string $title = null)
    {
        return Notification::query()
            ->where('event', Notification::SYSTEM)
            ->when($title !== null, fn ($query) => $query->where('title', $title))
            ->get();
    }

    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna',
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function certificate(): Certificate
    {
        $employee = Employee::query()->create([
            'code' => 'EMP-9001',
            'name' => 'Joko Susilo',
            'nik_ktp' => str_pad('1', 16, '3', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2025-01-01',
            'employment_type' => 'tetap',
            'position' => 'Pelaksana',
            'department' => 'proyek',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        return Certificate::query()->create([
            'employee_id' => $employee->id,
            'certificate_type' => 'skk',
            'name' => 'SKK Ahli Madya',
            'issuer' => 'LPJK',
            'issued_date' => '2023-09-01',
            // Jauh di luar jendela entri certificate_expiry (60 hari), supaya
            // alarm yang dihitung di bawah hanya bisa datang dari LAMPIRANNYA.
            'expiry_date' => '2029-09-01',
        ]);
    }

    private function asset(): Asset
    {
        $category = AssetCategory::query()->create(['code' => 'CAT-F8', 'name' => 'Alat Berat']);

        return Asset::query()->create([
            'code' => 'AST-F8001',
            'name' => 'Excavator Uji',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => 96_000_000,
            'useful_life_months' => 96,
            'book_value' => 96_000_000,
            'status' => 'available',
        ]);
    }

    // ---------------------------------------- A. tanpa tanggal = keadaan normal

    /**
     * Perangkap utama paket ini. Sebuah pengawas yang meneriaki foto lapangan
     * karena tak satu pun punya masa berlaku adalah pengawas yang dimatikan
     * orang pada hari pertama — dan baris "BLIND" milik scan() adalah cara
     * KEDUA melakukannya, dengan kalimat "silence here is missing data".
     */
    public function test_a_thousand_dateless_attachments_raise_nothing_at_all(): void
    {
        $this->userWith(['fin.view', 'fin.update']);

        // 60 baris, semuanya tanpa masa berlaku — tulis langsung, bukan lewat
        // service, supaya ujinya tidak menghabiskan waktu pada sniff berkas.
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = [
                'attachable_type' => ApBill::class,
                'attachable_id' => $this->bill->id,
                'disk' => 'local', 'path' => "attachments/x/{$i}.jpg", 'original_name' => "foto-{$i}.jpg",
                'mime' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1200, 'sha256' => str_repeat('a', 64),
                'valid_until' => null, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('core_attachments')->insert($rows);

        $scan = WatchedDeadlines::scan(Carbon::parse(self::TODAY)->toImmutable());

        $this->assertSame([], array_values(array_filter(
            $scan['findings'],
            static fn (array $finding): bool => str_starts_with($finding['key'], 'attachment_valid_until_'),
        )), 'Lampiran tanpa masa berlaku melahirkan temuan.');

        $this->assertSame([], array_values(array_filter(
            $scan['undated'],
            static fn (array $blind): bool => str_starts_with($blind['key'], 'attachment_valid_until_'),
        )), 'Lampiran tanpa masa berlaku melahirkan baris BLIND — 60 foto biasa dilaporkan sebagai data hilang.');

        $this->watch();
        $this->assertCount(0, $this->alarms());
    }

    /**
     * Kontradiksi yang tidak boleh bisa ditulis: satu bendera berkata tanggal
     * kosong ADALAH alarmnya, satu lagi berkata ia bukan berita sama sekali.
     */
    public function test_no_entry_claims_both_dateless_flags(): void
    {
        foreach (WatchedDeadlines::entries() as $entry) {
            $this->assertFalse(
                ($entry['alarm_when_date_missing'] ?? false) && ($entry['dateless_is_normal'] ?? false),
                "{$entry['key']} memakai alarm_when_date_missing DAN dateless_is_normal sekaligus.",
            );
        }
    }

    /** Dan tidak satu pun entri lampiran memakai pola PKWT. */
    public function test_no_attachment_entry_alarms_on_a_missing_date(): void
    {
        $attachmentEntries = array_filter(
            WatchedDeadlines::entries(),
            static fn (array $entry): bool => str_starts_with($entry['key'], 'attachment_valid_until_'),
        );

        $this->assertCount(12, $attachmentEntries, 'Satu entri per modul yang punya dokumen berlampiran.');

        foreach ($attachmentEntries as $entry) {
            $this->assertFalse($entry['alarm_when_date_missing'] ?? false, "{$entry['key']} meneriaki lampiran tanpa masa berlaku.");
            $this->assertTrue($entry['dateless_is_normal'] ?? false, "{$entry['key']} tidak menyatakan bahwa tanpa tanggal itu normal.");
        }
    }

    // ------------------------------------------------------- B. izin per baris

    public function test_an_expiring_attachment_alarms_the_update_holder_of_its_own_module(): void
    {
        $this->userWith(['fin.view', 'fin.update']);
        $this->attach($this->bill, 'polis-car.pdf', '2026-08-10');

        $this->watch();

        $alarm = $this->alarms('Lampiran Keuangan mendekati akhir masa berlaku')->sole();
        $this->assertStringContainsString('polis-car.pdf', $alarm->body);
        $this->assertStringContainsString('9 hari lagi', $alarm->body);
        $this->assertStringContainsString('menempel pada Tagihan vendor #'.$this->bill->id, $alarm->body);
    }

    /**
     * Dua lampiran, dua modul, dua kelompok alarm yang berbeda — dan pemegang
     * hr.update TIDAK boleh membaca nama berkas milik tagihan vendor, seperti
     * pemegang fin.update tidak boleh membaca nama berkas sertifikat karyawan.
     */
    public function test_two_modules_get_two_findings_and_neither_reads_the_others(): void
    {
        $hrOfficer = $this->userWith(['hr.view', 'hr.update']);
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $this->attach($this->bill, 'polis-car.pdf', '2026-08-10');
        $this->attach($this->certificate(), 'sertifikat-k3.pdf', '2026-08-12');

        $hrView = $this->actingAs($hrOfficer)->getJson('/api/core/deadlines')->assertOk()->json('data');
        $finView = $this->actingAs($clerk)->getJson('/api/core/deadlines')->assertOk()->json('data');

        $keys = static fn (array $findings): array => array_values(array_filter(
            array_column($findings, 'key'),
            static fn (string $key): bool => str_starts_with($key, 'attachment_valid_until_'),
        ));

        $this->assertSame(['attachment_valid_until_hr'], $keys($hrView));
        $this->assertSame(['attachment_valid_until_fin'], $keys($finView));

        $this->assertStringNotContainsString('polis-car.pdf', json_encode($hrView));
        $this->assertStringNotContainsString('sertifikat-k3.pdf', json_encode($finView));
    }

    /** Membaca lampiran butuh .view; MENGURUS perpanjangannya butuh .update. */
    public function test_a_module_viewer_is_not_the_audience_of_the_alarm(): void
    {
        $viewer = $this->userWith(['fin.view']);
        $this->attach($this->bill, 'polis-car.pdf', '2026-08-10');

        $findings = $this->actingAs($viewer)->getJson('/api/core/deadlines')->assertOk()->json('data');

        $this->assertSame([], array_values(array_filter(
            array_column($findings, 'key'),
            static fn (string $key): bool => str_starts_with($key, 'attachment_valid_until_'),
        )));
    }

    /**
     * Prefix izin setiap entri lampiran diturunkan dari AttachableDocuments,
     * bukan dari tabel core_attachments (yang akan memberi core.update kepada
     * semuanya — dipegang hanya admin dan direktur).
     */
    public function test_every_attachment_entry_asks_for_the_update_permission_of_its_own_module(): void
    {
        $prefixes = [];

        foreach (WatchedDeadlines::entries() as $entry) {
            if (! str_starts_with($entry['key'], 'attachment_valid_until_')) {
                continue;
            }

            $prefix = substr($entry['key'], strlen('attachment_valid_until_'));
            $prefixes[] = $prefix;

            $this->assertSame("{$prefix}.update", $entry['permission']);
            $this->assertSame("m/{$prefix}", $entry['link']);
            $this->assertArrayHasKey($prefix, AttachableDocuments::byPrefix());
        }

        sort($prefixes);
        $this->assertSame(
            ['ast', 'crm', 'eng', 'est', 'fin', 'hr', 'inv', 'prc', 'prj', 'qc', 'scm', 'svc'],
            $prefixes,
        );
    }

    /**
     * Judul entri masuk ke core_notifications dan dedupe bersandar padanya,
     * jadi nama modulnya literal — tetapi ia harus tetap NAMA YANG SAMA dengan
     * grup sidebar. Kalau grup di schema.js diganti nama, uji ini yang merah,
     * bukan pemakainya yang menemukan dua nama untuk satu modul.
     */
    public function test_the_module_names_in_the_titles_match_the_sidebar_groups(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        preg_match_all("/^    label: '([^']+)', perm: [^,]+, prefix: '([a-z]+)',$/m", $schema, $matches, PREG_SET_ORDER);

        $navLabels = [];
        foreach ($matches as $match) {
            $navLabels[$match[2]] = $match[1];
        }

        $this->assertNotEmpty($navLabels, 'Blok NAV schema.js tidak terbaca — polanya berubah.');

        foreach (WatchedDeadlines::entries() as $entry) {
            if (! str_starts_with($entry['key'], 'attachment_valid_until_')) {
                continue;
            }

            $prefix = substr($entry['key'], strlen('attachment_valid_until_'));
            $this->assertArrayHasKey($prefix, $navLabels);
            $this->assertSame(
                "Lampiran {$navLabels[$prefix]} mendekati akhir masa berlaku",
                $entry['title_upcoming'],
                "Judul entri {$entry['key']} memakai nama modul yang bukan label grup NAV-nya.",
            );
            $this->assertSame("Lampiran {$navLabels[$prefix]} lewat masa berlaku", $entry['title_overdue']);
        }
    }

    // --------------------------------------------------- C. induk yang hilang

    public function test_an_attachment_whose_document_was_soft_deleted_stops_alarming(): void
    {
        $this->userWith(['fin.view', 'fin.update']);
        $this->attach($this->bill, 'polis-car.pdf', '2026-08-10');

        $this->watch();
        $this->assertCount(1, $this->alarms('Lampiran Keuangan mendekati akhir masa berlaku'));

        Notification::query()->delete();
        $this->bill->delete(); // soft delete — dokumennya dibuang

        $this->watch();
        $this->assertCount(0, $this->alarms(), 'Lampiran dokumen yang sudah dibuang masih berbunyi.');
    }

    public function test_an_attachment_whose_document_is_permanently_gone_stops_alarming(): void
    {
        $this->userWith(['fin.view', 'fin.update']);
        $this->attach($this->bill, 'polis-car.pdf', '2026-08-10');
        $this->bill->forceDelete();

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /**
     * Kelas yang tidak ada di registri tidak pernah masuk cakupan — aturan yang
     * sama dengan AttachmentController::reachable(), yang menolak unduhannya
     * dengan 404. Sebuah baris warisan bertipe kelas yang sudah dihapus tidak
     * boleh melahirkan alarm yang tidak bisa dibuka siapa pun.
     */
    public function test_a_row_pointing_at_a_class_outside_the_registry_is_never_watched(): void
    {
        $this->userWith(['fin.view', 'fin.update', 'prj.view', 'prj.update']);

        DB::table('core_attachments')->insert([
            'attachable_type' => 'Modules\\Legacy\\Models\\Hilang',
            'attachable_id' => 1,
            'disk' => 'local', 'path' => 'attachments/x/legacy.pdf', 'original_name' => 'warisan.pdf',
            'mime' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 100, 'sha256' => str_repeat('b', 64),
            'valid_until' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /** Induk yang HIDUP tetap berbunyi — penjaga di atas tidak boleh membungkam semuanya. */
    public function test_an_attachment_on_a_live_document_of_another_module_still_alarms(): void
    {
        $this->userWith(['ast.view', 'ast.update']);
        $this->attach($this->asset(), 'stnk.pdf', '2026-07-20');

        $this->watch();

        $alarm = $this->alarms('Lampiran Aset lewat masa berlaku')->sole();
        $this->assertStringContainsString('stnk.pdf', $alarm->body);
        $this->assertStringContainsString('12 hari lalu', $alarm->body);
    }

    // ------------------------------------------------ D. hari terakhir & tier

    public function test_the_last_valid_day_is_menipis_hari_ini_not_lewat(): void
    {
        $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attach($this->bill, 'polis-car.pdf', self::TODAY);

        $this->watch();

        $alarm = $this->alarms('Lampiran Keuangan mendekati akhir masa berlaku')->sole();
        $this->assertStringContainsString('hari ini', $alarm->body);
        $this->assertCount(0, $this->alarms('Lampiran Keuangan lewat masa berlaku'));

        // Kartu lampiran membaca keadaan yang sama pada hari yang sama.
        $this->assertSame(Attachment::VALIDITY_NEAR, $attachment->fresh()->validityState());
    }

    public function test_the_day_after_is_lewat(): void
    {
        $this->userWith(['fin.view', 'fin.update']);
        $this->attach($this->bill, 'polis-car.pdf', '2026-07-31');

        $this->watch();

        $this->assertCount(1, $this->alarms('Lampiran Keuangan lewat masa berlaku'));
        $this->assertCount(0, $this->alarms('Lampiran Keuangan mendekati akhir masa berlaku'));
    }

    /**
     * 30 hari DIPAKU sebagai angka, tidak dibaca dari konstantanya: sebuah uji
     * yang menyusun harapannya dari VALID_UNTIL_LEAD_DAYS hijau untuk nilai apa
     * pun. 31 hari lagi masih diam; 30 hari lagi berbunyi.
     */
    public function test_the_warning_window_is_thirty_days_on_both_surfaces(): void
    {
        $this->userWith(['fin.view', 'fin.update']);
        $this->attach($this->bill, 'sunyi.pdf', '2026-09-01');  // 31 hari lagi
        $this->attach($this->bill, 'berbunyi.pdf', '2026-08-31'); // 30 hari lagi

        $this->watch();

        $alarm = $this->alarms('Lampiran Keuangan mendekati akhir masa berlaku')->sole();
        $this->assertStringContainsString('berbunyi.pdf berlaku s/d 31 Agu 2026 — 30 hari lagi', $alarm->body);
        // Badan pesan hanya menyebut "Total N" bila N > 1, jadi tidak adanya
        // 'sunyi.pdf' DAN tidak adanya baris kedua sama-sama diperiksa.
        $this->assertStringNotContainsString('sunyi.pdf', $alarm->body);
        $this->assertStringNotContainsString('Total', $alarm->body);

        // Permukaan kedua, angka yang sama: kartu lampiran.
        $this->assertSame(Attachment::VALIDITY_OK, Attachment::query()->where('original_name', 'sunyi.pdf')->sole()->validityState());
        $this->assertSame(Attachment::VALIDITY_NEAR, Attachment::query()->where('original_name', 'berbunyi.pdf')->sole()->validityState());
    }

    /**
     * Indeksnya harus dipakai oleh KUERI PENGAWAS YANG SEBENARNYA, bukan hanya
     * oleh kueri contoh di AttachmentValidUntilSchemaTest.
     *
     * Cakupan entri ini menyaring attachable_type DUA KALI: sekali sebagai
     * `IN (…)` di depan, sekali lagi di dalam rantai OR penjaga induk. Yang
     * kedua sendirian sudah benar — dan justru itu bahayanya: mencabut `IN`
     * tidak menjatuhkan satu pun uji perilaku, sementara perencana kehilangan
     * kolom pertama indeks (attachable_type, valid_until) dan jatuh ke
     * pemindaian. Baris inilah yang menahannya.
     */
    public function test_the_registry_scope_itself_is_planned_through_the_pair_index(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Rencana kueri ini spesifik SQLite; angka MySQL ada di CONVENTIONS §37.');
        }

        $entry = collect(WatchedDeadlines::entries())->firstWhere('key', 'attachment_valid_until_fin');

        $query = WatchedDeadlines::scoped($entry)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', '2026-08-02');

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))
            ->pluck('detail')
            ->implode(' | ');

        $this->assertStringContainsString('core_attachments_attachable_type_valid_until_index', $plan,
            "Kueri pengawas tidak lewat indeks pasangan: {$plan}");
    }

    // ------------------------------------------------------------- kalender

    /**
     * Keputusan yang ditulis, bukan kelalaian yang tertutup: entri lampiran
     * TIDAK menjadi sumber kalender. Tanpa saringan itu CalendarEvents
     * melempar InvalidArgumentException untuk prefix 'core' pada permintaan
     * kalender pertama — jadi baris ini juga jaring terhadap kambuhnya.
     */
    public function test_attachment_entries_are_not_calendar_sources(): void
    {
        $kinds = array_column(CalendarEvents::sources(), 'kind');

        foreach ($kinds as $kind) {
            $this->assertStringStartsNotWith('attachment_valid_until_', $kind);
        }

        $user = $this->userWith(['fin.view', 'fin.update']);
        $this->attach($this->bill, 'polis-car.pdf', '2026-08-10');

        $this->actingAs($user)->getJson('/api/core/calendar?month=2026-08')->assertOk();
    }
}
