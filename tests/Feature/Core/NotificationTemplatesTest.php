<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Services\HealthService;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\NotificationTemplates;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Support\UsesCapturingMailer;

/**
 * Template per peristiwa untuk kanal luar (P-3a, T3a.1).
 *
 * Yang dipaku:
 *  - registri memuat PERSIS lima kunci ROADMAP P-3a, dalam urutan itu
 *    (literal, bukan dibaca dari kelasnya — pelajaran F-6);
 *  - setiap perintah pengawas menyebut kuncinya: watchdog → scheduler.down,
 *    backup-watch → backup.stale, approval-watch → approval.escalated HANYA
 *    saat eskalasi, deadline-watch → ar.dunning untuk invoice pelanggan lewat
 *    jatuh tempo dan deadline.due untuk tanggal lain — dibuktikan dengan
 *    MENJALANKAN perintahnya, bukan membaca sumbernya;
 *  - peristiwa tanpa entri memakai template UMUM dan itu terlihat dari
 *    subjek suratnya (tanpa awalan); kunci yang salah eja ditolak, bukan
 *    disimpan sebagai "umum" diam-diam;
 *  - kontrak tiga placeholder WhatsApp: judul, isi (diratakan, dipotong),
 *    tautan atau "-".
 */
class NotificationTemplatesTest extends ErpTestCase
{
    use UsesCapturingMailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(string ...$permissions): User
    {
        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);
        $user = User::query()->create([
            'name' => 'Pengguna '.substr(md5(implode('|', $permissions)), 0, 4),
            'email' => substr(md5(implode('|', $permissions).microtime()), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<int, string|null> user_id → template */
    private function templatesByUser(?string $title = null): array
    {
        return Notification::query()
            ->where('event', Notification::SYSTEM)
            ->when($title !== null, fn ($q) => $q->where('title', $title))
            ->get()
            ->mapWithKeys(fn (Notification $n) => [$n->user_id => $n->template])
            ->all();
    }

    // ------------------------------------------------------------- registri

    public function test_the_registry_holds_exactly_the_five_roadmap_events_in_order(): void
    {
        $this->assertSame(
            ['deadline.due', 'approval.escalated', 'ar.dunning', 'backup.stale', 'scheduler.down'],
            NotificationTemplates::KEYS,
        );
        $this->assertSame(NotificationTemplates::KEYS, array_keys(NotificationTemplates::keys()));

        foreach (NotificationTemplates::keys() as $key => $entry) {
            $this->assertMatchesRegularExpression('/^\[[A-Za-z ]+\]$/', $entry['mail']['subject_prefix'], $key);
            $this->assertNotSame('', $entry['mail']['intro']);
            $this->assertNotSame('', $entry['mail']['cta']);
            $this->assertStringStartsWith('WHATSAPP_TEMPLATE_', $entry['whatsapp']['env'], $key);
        }

        // Nama variabel .env unik per template — dua peristiwa yang berbagi
        // satu nama berarti satu template Meta untuk dua kalimat berbeda.
        $envs = array_map(fn (array $e) => $e['whatsapp']['env'], NotificationTemplates::keys());
        $this->assertSame(count($envs), count(array_unique($envs)));
    }

    public function test_events_without_an_entry_resolve_to_the_generic_template_and_unknown_keys_are_refused(): void
    {
        $user = $this->userWith('core.update');

        $submitted = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SUBMITTED, 'title' => 'PO menunggu', 'body' => 'x']);
        $plainAlarm = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SYSTEM, 'title' => 'Tutup buku terlambat', 'body' => 'x']);
        $backup = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SYSTEM, 'template' => 'backup.stale', 'title' => 'Cadangan macet', 'body' => 'x']);

        $this->assertNull(NotificationTemplates::forNotification($submitted));
        $this->assertNull(NotificationTemplates::forNotification($plainAlarm));
        $this->assertSame('backup.stale', NotificationTemplates::forNotification($backup)['key']);
        $this->assertSame('[Cadangan]', NotificationTemplates::forNotification($backup)['mail']['subject_prefix']);

        $this->assertFalse(NotificationTemplates::has(null));
        $this->assertFalse(NotificationTemplates::has('backup_stale'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Template notifikasi "backup_stale" tidak terdaftar');
        app(NotificationService::class)->system('core.update', 'Judul', 'Isi.', null, null, null, 'backup_stale');
    }

    public function test_system_stores_the_template_on_the_in_app_row(): void
    {
        $user = $this->userWith('core.update');

        app(NotificationService::class)->system('core.update', 'Judul', 'Isi.', null, null, null, NotificationTemplates::SCHEDULER_DOWN);
        app(NotificationService::class)->system('core.update', 'Judul umum', 'Isi.');

        $this->assertSame('scheduler.down', Notification::query()->where('title', 'Judul')->sole()->template);
        $this->assertNull(Notification::query()->where('title', 'Judul umum')->sole()->template);
        $this->assertSame($user->id, Notification::query()->where('title', 'Judul')->sole()->user_id);
    }

    // ------------------------------------------------------------- perintah

    public function test_the_watchdog_alarm_carries_scheduler_down(): void
    {
        $admin = $this->adminUser();
        app(SettingService::class)->set(HealthService::HEARTBEAT_KEY, CarbonImmutable::now()->subMinutes(45)->toIso8601String());

        $this->artisan('erp:watchdog-alarm')->assertExitCode(1);

        $this->assertSame([$admin->id => 'scheduler.down'], $this->templatesByUser());
    }

    public function test_every_backup_alarm_carries_backup_stale(): void
    {
        $admin = $this->adminUser();
        $file = tempnam(sys_get_temp_dir(), 'p3a-status');
        config(['erp.backup.status_file' => $file]);

        try {
            // Dua judul berbeda, satu template: cadangan macet, lalu uji pemulihan gagal.
            file_put_contents($file, json_encode(['configured' => true, 'destination' => 'rsync:x', 'last_success' => now()->subDays(5)->toIso8601String()]));
            $this->artisan('erp:backup-watch')->assertExitCode(1);

            file_put_contents($file, json_encode([
                'configured' => true, 'destination' => 'rsync:x', 'last_success' => now()->toIso8601String(),
                'remote_count' => 3, 'newest_artifact' => now()->format('Ymd-His'), 'last_drill_result' => 'failed',
            ]));
            $this->artisan('erp:backup-watch')->assertExitCode(1);
        } finally {
            @unlink($file);
        }

        $rows = Notification::query()->where('event', Notification::SYSTEM)->where('user_id', $admin->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['Salinan cadangan offsite macet', 'Uji pemulihan cadangan gagal'], $rows->pluck('title')->all());
        $this->assertSame(['backup.stale', 'backup.stale'], $rows->pluck('template')->all());
    }

    public function test_approval_watch_marks_only_the_escalation_not_the_reminder(): void
    {
        Carbon::setTestNow('2026-09-04 09:00:00');
        $maker = $this->userWith('prc.create');
        $checker = $this->userWith('prc.approve');
        $director = $this->userWith('fin.approve');

        $submit = function (int $daysAgo) use ($maker): void {
            $pr = PurchaseRequisition::query()->create([
                'needed_date' => '2026-12-31', 'status' => 'draft', 'purpose' => 'Uji template', 'requested_by' => $maker->id,
            ]);
            Carbon::setTestNow(Carbon::parse('2026-09-04')->subDays($daysAgo)->setTime(8, 0));
            $pr->submit($maker);
            Carbon::setTestNow('2026-09-04 09:00:00');
        };

        // Pengingat (5 hari = ambang): template umum.
        $submit(5);
        $this->artisan('erp:approval-watch')->assertExitCode(0);
        $reminder = Notification::query()->where('event', Notification::SYSTEM)->where('user_id', $checker->id)->sole();
        $this->assertStringContainsString('menunggu persetujuan 5 hari', $reminder->title);
        $this->assertNull($reminder->template, 'Pengingat bukan eskalasi.');

        // Eskalasi (33 hari ≥ 2×ambang): approval.escalated untuk penyetuju DAN direktur.
        Notification::query()->delete();
        $submit(33);
        $this->artisan('erp:approval-watch')->assertExitCode(0);

        // PR 5 hari masih menunggu, jadi pengingatnya ditulis lagi SESUDAH
        // baris eskalasi — dipilih menurut judul, bukan "baris terakhir per orang".
        $escalations = Notification::query()->where('event', Notification::SYSTEM)->where('title', 'like', 'Eskalasi:%')->get();
        $this->assertSame([$checker->id, $director->id], $escalations->pluck('user_id')->sort()->values()->all());
        $this->assertSame(['approval.escalated', 'approval.escalated'], $escalations->pluck('template')->all());

        $reminders = Notification::query()->where('event', Notification::SYSTEM)->where('title', 'like', '%menunggu persetujuan 5 hari')->get();
        $this->assertCount(1, $reminders);
        $this->assertNull($reminders[0]->template, 'Pengingat yang ditulis ulang tetap umum.');
    }

    public function test_deadline_watch_uses_ar_dunning_for_overdue_customer_invoices_and_deadline_due_elsewhere(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $finance = $this->userWith('fin.create');

        $customer = Customer::query()->create(['name' => 'PT Graha Sentosa Propertindo', 'is_pkp' => true, 'status' => 'active']);
        $contract = Contract::query()->create([
            'customer_id' => $customer->id, 'title' => 'Gedung Kantor', 'scope_type' => 'construction', 'value' => 48_500_000_000, 'status' => 'approved',
        ]);
        ArInvoice::query()->create([
            'code' => 'INV/2026/VII/0004', 'customer_id' => $customer->id, 'contract_id' => $contract->id,
            'invoice_date' => '2026-06-22', 'due_date' => '2026-07-22', 'description' => 'Termin 2',
            'dpp' => 15_420_000_000, 'total' => 15_420_000_000, 'amount_paid' => 0, 'terbilang' => 'x', 'status' => 'approved',
        ]);
        $vendor = Vendor::query()->create(['name' => 'PT Sumber Makmur Elektrindo', 'is_subcontractor' => false, 'classification' => 'material', 'status' => 'active']);
        ApBill::query()->create([
            'vendor_id' => $vendor->id, 'bill_date' => '2026-05-28', 'due_date' => '2026-06-27', 'description' => 'Kamera',
            'vendor_invoice_no' => 'INV-VND-0002', 'dpp' => 48_500_000, 'total_payable' => 48_500_000, 'amount_paid' => 0, 'status' => 'approved',
        ]);

        $this->artisan('erp:deadline-watch')->assertExitCode(0);

        $rows = Notification::query()->where('event', Notification::SYSTEM)->where('user_id', $finance->id)->get()->keyBy('title');
        $this->assertSame('ar.dunning', $rows['Invoice pelanggan lewat jatuh tempo']->template);
        $this->assertSame('deadline.due', $rows['Tagihan vendor lewat jatuh tempo']->template);
    }

    public function test_the_deadline_mapping_is_by_watcher_key_and_tier(): void
    {
        $this->assertSame('ar.dunning', NotificationTemplates::forDeadlineFinding('ar_invoice_due', WatchedDeadlines::LEWAT));
        $this->assertSame('deadline.due', NotificationTemplates::forDeadlineFinding('ar_invoice_due', WatchedDeadlines::MENIPIS));
        $this->assertSame('deadline.due', NotificationTemplates::forDeadlineFinding('ap_due', WatchedDeadlines::LEWAT));
        $this->assertSame('deadline.due', NotificationTemplates::forDeadlineFinding('certificate_expiry', WatchedDeadlines::MENIPIS));
        $this->assertNull(NotificationTemplates::forDeadlineFinding('pkwt_end', WatchedDeadlines::TANPA_TANGGAL), 'Data hilang bukan tenggat.');
    }

    // ----------------------------------------------------------------- surel

    public function test_the_mail_shape_follows_the_template_and_the_generic_one_has_no_prefix(): void
    {
        $transport = $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $this->userWith('core.update');
        $service = app(NotificationService::class);

        $service->system('core.update', 'Salinan cadangan offsite macet', 'Sinkronisasi 5 hari lalu.', null, null, null, NotificationTemplates::BACKUP_STALE);
        $service->system('core.update', 'Tutup buku terlambat', 'Periode Juli masih terbuka.', 'r/finance/periods');

        $this->assertCount(2, $transport->messages);
        [$backup, $generic] = array_map(fn ($m) => $m->getOriginalMessage(), $transport->messages);

        $this->assertSame('[Cadangan] Salinan cadangan offsite macet', $backup->getSubject());
        $html = (string) $backup->getHtmlBody();
        $this->assertStringContainsString('Pengawas cadangan melaporkan keadaan yang perlu tindakan di server.', $html);
        $this->assertStringContainsString('Cadangan basi / gagal', $html);
        $this->assertStringNotContainsString('Buka aplikasi', $html, 'Tanpa tautan, tanpa tombol.');

        $this->assertSame('Tutup buku terlambat', $generic->getSubject(), 'Template umum: subjek apa adanya, tanpa awalan.');
        $this->assertStringContainsString('Buka dokumen', (string) $generic->getHtmlBody());
        $this->assertStringContainsString('/app/r/finance/periods', (string) $generic->getHtmlBody());

        $this->assertSame(
            ['backup.stale', null],
            NotificationDelivery::query()->orderBy('id')->get()->map(fn ($d) => $d->notification->template)->all(),
        );
        $this->assertSame([NotificationDelivery::SENT, NotificationDelivery::SENT], NotificationDelivery::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_the_delivery_resource_carries_the_template(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        app(NotificationService::class)->system('core.update', 'Penjadwal tidak berjalan', 'Isi.', null, null, null, NotificationTemplates::SCHEDULER_DOWN);

        $this->actingAs($admin, 'sanctum');
        $row = $this->getJson('/api/core/notification-deliveries')->assertOk()->json('data.0');

        $this->assertSame('scheduler.down', $row['template']);
        $this->assertSame('Penjadwal tidak berjalan', $row['title']);
    }

    // -------------------------------------------------------------- whatsapp

    public function test_whatsapp_parameters_are_title_body_link_flattened_and_capped(): void
    {
        $user = $this->userWith('core.update');
        $notification = Notification::query()->create([
            'user_id' => $user->id, 'event' => Notification::SYSTEM, 'template' => 'deadline.due',
            'title' => "Tenggat  \n mendekat", 'body' => str_repeat('kata ', 200)."\n\tbaris baru    empat spasi",
        ]);

        $params = NotificationTemplates::whatsappParameters($notification, 'https://erp1.pi2.co.id/app/tenggat');

        $this->assertCount(3, $params);
        $this->assertSame('Tenggat mendekat', $params[0]);
        $this->assertLessThanOrEqual(NotificationTemplates::WHATSAPP_PARAM_MAX + 1, mb_strlen($params[1]));
        $this->assertStringNotContainsString("\n", $params[1]);
        $this->assertStringNotContainsString("\t", $params[1]);
        $this->assertDoesNotMatchRegularExpression('/ {4,}/', $params[1], 'Meta menolak 4+ spasi berurutan.');
        $this->assertStringEndsWith('…', $params[1]);
        $this->assertSame('https://erp1.pi2.co.id/app/tenggat', $params[2]);

        $this->assertSame('-', NotificationTemplates::whatsappParameters($notification, null)[2]);
        $this->assertSame(400, NotificationTemplates::WHATSAPP_PARAM_MAX);
    }

    public function test_the_job_still_delivers_a_templated_row_through_the_mail_channel(): void
    {
        $transport = $this->useCapturingMailer();
        app(SettingService::class)->set('notifications.email_enabled', true);
        $user = $this->userWith('core.update');
        $notification = Notification::query()->create([
            'user_id' => $user->id, 'event' => Notification::SYSTEM, 'template' => 'ar.dunning',
            'title' => 'Invoice pelanggan lewat jatuh tempo', 'body' => 'INV/2026/VII/0004 …', 'link' => 'r/finance/ar-invoices',
        ]);
        $row = NotificationDelivery::query()->create([
            'notification_id' => $notification->id, 'channel' => 'email', 'recipient' => $user->email, 'status' => 'queued', 'attempts' => 0,
        ]);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(NotificationDelivery::SENT, $row->refresh()->status);
        $this->assertSame('[Penagihan] Invoice pelanggan lewat jatuh tempo', $transport->messages[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('Buka invoice pelanggan', (string) $transport->messages[0]->getOriginalMessage()->getHtmlBody());
    }
}
