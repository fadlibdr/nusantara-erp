<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Notification;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\ActivityService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Pengawas aktivitas jatuh tempo (F-3 / T3.7) — satu entri di WatchedDeadlines,
 * bukan perintah baru dan bukan loop kedua.
 *
 * Ini tenggat pertama di registri itu yang tanggalnya DIJANJIKAN SEORANG SALES
 * KEPADA DIRINYA SENDIRI, dan justru karena itu ia yang paling mudah lewat
 * tanpa siapa pun tahu: tidak ada pelanggan yang menagih, tidak ada dokumen yang
 * macet, tidak ada angka yang berubah — yang hilang hanyalah prospeknya, enam
 * minggu kemudian.
 */
class ActivityDeadlineWatchTest extends ErpTestCase
{
    private const TODAY = '2026-09-08';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function watch(): void
    {
        $this->artisan('erp:deadline-watch')->assertExitCode(0);
    }

    /** @return Collection<int, Notification> */
    private function alarms(?string $title = null): Collection
    {
        return Notification::query()
            ->where('event', Notification::SYSTEM)
            ->when($title !== null, fn ($query) => $query->where('title', $title))
            ->get();
    }

    private function salesUser(): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('sales-f3', 'web');
        $role->syncPermissions(['crm.view', 'crm.update']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Sales F3', 'email' => 'sales-f3@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole('sales-f3');

        return $user;
    }

    private function activity(array $attributes = []): Activity
    {
        $lead = Lead::query()->create([
            'name' => 'Rudi Hartanto', 'company_name' => 'PT Bangun Sejahtera',
            'status' => LeadStatus::Contacted,
        ]);

        return app(ActivityService::class)->create(array_merge([
            'document_type' => 'lead',
            'document_id' => $lead->id,
            'type' => 'call',
            'subject' => 'Telepon konfirmasi kebutuhan',
        ], $attributes));
    }

    public function test_the_registry_carries_the_activity_entry(): void
    {
        $entry = collect(WatchedDeadlines::entries())->firstWhere('key', 'crm_activity_due');

        $this->assertNotNull($entry, 'entri pengawas aktivitas hilang dari registri');
        $this->assertSame('crm_activities', $entry['table']);
        $this->assertSame('due_at', $entry['date']);
        $this->assertSame('crm.update', $entry['permission'],
            'yang harus bertindak adalah orang yang bisa menandainya selesai');
        $this->assertSame('r/crm/activities?state=open', $entry['link']);
        $this->assertArrayNotHasKey('value', $entry, 'aktivitas tidak menyimpan rupiah — jangan mengutip angka yang tidak ada');
    }

    /**
     * TUJUAN PEMBERITAHUANNYA HARUS BISA MENJAWAB PERTANYAANNYA.
     *
     * `link` membawa saringan `state=open`, dan views/list.js hanya menerima
     * kunci query yang dideklarasikan `def.filters` (seedFromUrl → `declared`):
     * sebuah kunci yang tidak ada di schema.js dibuang DIAM-DIAM, dan layarnya
     * terbuka pada urutan bawaannya — due_at menaik lintas keadaan, yang
     * menaruh pekerjaan selesai 20 bulan lalu di baris pertama (diukur di
     * peramban 8 Sep 2026). Uji ini membaca kedua sisinya sekaligus, karena
     * penyimpangannya senyap: tidak ada galat, hanya jawaban yang salah.
     */
    public function test_the_notification_link_asks_a_question_the_screen_can_answer(): void
    {
        $entry = collect(WatchedDeadlines::entries())->firstWhere('key', 'crm_activity_due');
        [$path, $query] = array_pad(explode('?', (string) $entry['link'], 2), 2, '');

        $this->assertSame('r/crm/activities', $path);
        parse_str($query, $params);
        $this->assertNotEmpty($params, 'tautan tanpa saringan membuka arsip, bukan antrean');

        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($schema, "  'crm/activities': {");
        $this->assertNotFalse($start);
        $end = strpos($schema, "\n  '", $start + 5);
        $block = substr($schema, $start, $end === false ? null : $end - $start);

        foreach (array_keys($params) as $key) {
            $this->assertStringContainsString("{ key: '{$key}',", $block,
                "saringan [{$key}] tidak dideklarasikan di layar crm/activities — views/list.js membuangnya diam-diam, "
                .'dan pemberitahuan jatuh tempo membuka daftar yang tidak disaring apa pun');
        }
    }

    public function test_an_activity_nearing_its_due_date_notifies_sales(): void
    {
        $this->salesUser();
        $this->activity(['due_at' => '2026-09-10', 'subject' => 'Telepon Pak Rudi']); // 2 dari 3 hari lead

        $this->watch();

        $alarm = $this->alarms('Aktivitas CRM mendekati jatuh tempo')->sole();
        $this->assertStringContainsString('Telepon Pak Rudi', $alarm->body);
        $this->assertStringContainsString('jatuh tempo 10 Sep 2026', $alarm->body);
        $this->assertStringContainsString('2 hari lagi', $alarm->body);
        $this->assertSame('r/crm/activities?state=open', $alarm->link);
    }

    /** lead_days 3: sebuah telepon dijadwalkan dalam hitungan hari. */
    public function test_an_activity_outside_the_lead_window_stays_silent(): void
    {
        $this->salesUser();
        $this->activity(['due_at' => '2026-09-20']);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    public function test_an_overdue_activity_alarms_and_names_its_age(): void
    {
        $this->salesUser();
        $this->activity(['due_at' => '2026-09-01', 'subject' => 'Kunjungan ke lokasi']);

        $this->watch();

        $alarm = $this->alarms('Aktivitas CRM lewat jatuh tempo')->sole();
        $this->assertStringContainsString('Kunjungan ke lokasi', $alarm->body);
        $this->assertStringContainsString('7 hari lalu', $alarm->body);
    }

    /** "Selesai" MENDIAMKAN pengawasnya — itulah gunanya done_at. */
    public function test_a_done_activity_is_silent(): void
    {
        $sales = $this->salesUser();
        $activity = $this->activity(['due_at' => '2026-09-01']);
        app(ActivityService::class)->markDone($activity, $sales);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /** Aktivitas yang dihapus juga diam — tanpa membuat pengawasnya galat. */
    public function test_a_deleted_activity_is_silent(): void
    {
        $this->salesUser();
        $activity = $this->activity(['due_at' => '2026-09-01']);
        app(ActivityService::class)->delete($activity);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /** Aktivitas tanpa tanggal tidak pernah berbunyi — ia tidak dijanjikan. */
    public function test_an_undated_activity_never_alarms(): void
    {
        $this->salesUser();
        $this->activity(['subject' => 'Catatan hasil obrolan']);

        $this->watch();

        $this->assertCount(0, $this->alarms());
    }

    /**
     * Jatuh tempo HARI INI belum terlambat — dan JUDULNYA yang membuktikannya.
     *
     * Entri ini `valid_through_end`: pekerjaan yang dijanjikan hari ini masih
     * bisa dikerjakan hari ini, jadi harinya berbunyi MENIPIS ("hari ini") dan
     * LEWAT baru mulai besok. Judulnya ikut dipaku, bukan hanya keberadaan
     * alarmnya: sampai 8 Sep 2026 uji ini hanya menuntut SATU alarm, dan
     * karena itu pengawas bisa menyebut "lewat jatuh tempo" bertahun-tahun
     * sementara Activity::isOverdue, saringan state=overdue dan hitungan kartu
     * papan menyebut hal sebaliknya untuk baris yang sama persis.
     */
    public function test_today_is_never_lost_between_the_two_tiers(): void
    {
        $this->salesUser();
        $this->activity(['due_at' => self::TODAY, 'subject' => 'Jatuh tempo hari ini']);

        $this->watch();

        $alarm = $this->alarms()->sole();
        $this->assertSame('Aktivitas CRM mendekati jatuh tempo', $alarm->title,
            'hari jatuh tempo disebut LEWAT oleh pengawas, padahal tiga permukaan lain menyebutnya belum');
        $this->assertStringContainsString('Jatuh tempo hari ini', $alarm->body);
        $this->assertStringContainsString('hari ini', $alarm->body);
    }

    /**
     * DAN besoknya barulah LEWAT — batas bawah tingkat itu ikut dipaku, supaya
     * "belum terlambat hari ini" tidak diperbaiki dengan mendiamkan yang
     * sungguh-sungguh terlambat.
     */
    public function test_the_day_after_the_due_date_is_overdue(): void
    {
        $this->salesUser();
        $this->activity(['due_at' => '2026-09-07', 'subject' => 'Jatuh tempo kemarin']);

        $this->watch();

        $alarm = $this->alarms()->sole();
        $this->assertSame('Aktivitas CRM lewat jatuh tempo', $alarm->title);
        $this->assertStringContainsString('1 hari lalu', $alarm->body);
    }

    /**
     * Satu baris, satu jawaban: apa yang dikatakan pengawas pukul 08.30 harus
     * sama dengan apa yang dikatakan aplikasinya saat orangnya membukanya.
     * Diukur lewat KEDUA jalur atas baris yang sama.
     */
    public function test_the_watcher_and_the_app_agree_on_what_is_late(): void
    {
        $sales = $this->salesUser();
        $today = $this->activity(['due_at' => self::TODAY, 'subject' => 'Hari ini']);
        $yesterday = $this->activity(['due_at' => '2026-09-07', 'subject' => 'Kemarin']);

        $this->watch();

        $this->assertFalse($today->refresh()->isOverdue(), 'aplikasi: hari ini belum lewat');
        $this->assertTrue($yesterday->refresh()->isOverdue(), 'aplikasi: kemarin sudah lewat');

        $lewat = $this->alarms('Aktivitas CRM lewat jatuh tempo')->sole();
        $this->assertStringContainsString('Kemarin', $lewat->body);
        $this->assertStringNotContainsString('Hari ini', $lewat->body,
            'pengawas menyebut lewat jatuh tempo sesuatu yang layarnya sebut belum');

        $menipis = $this->alarms('Aktivitas CRM mendekati jatuh tempo')->sole();
        $this->assertStringContainsString('Hari ini', $menipis->body);
    }

    /** Yang tidak boleh menandainya selesai tidak diberi tahu. */
    public function test_only_people_who_can_act_are_notified(): void
    {
        $this->salesUser();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $viewerRole = Role::findOrCreate('penonton-f3', 'web');
        $viewerRole->syncPermissions(['crm.view']);
        /** @var User $viewer */
        $viewer = User::query()->create([
            'name' => 'Penonton F3', 'email' => 'penonton-f3@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $viewer->assignRole('penonton-f3');

        $this->activity(['due_at' => '2026-09-01']);
        $this->watch();

        $recipients = $this->alarms()->pluck('user_id')->unique()->all();
        $this->assertNotContains($viewer->id, $recipients,
            'yang hanya bisa melihat tidak bisa menandainya selesai — memberitahunya hanya melatih orang mengabaikan notifikasi');
    }
}
