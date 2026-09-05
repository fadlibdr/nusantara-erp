<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Mail\ApprovalNotificationMail;
use Modules\Core\Models\Notification;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Who gets told when a document moves through approval.
 *
 * The properties that matter are about the two ways this feature fails badly:
 * telling the wrong people (an inbox full of other departments' documents stops
 * being read, and an approval queue nobody reads is worse than none), and
 * breaking the thing it reports on — these calls run inside transactions that
 * are posting to the ledger.
 */
class ApprovalNotificationTest extends ErpTestCase
{
    use FinanceFixtures;

    private NotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->notifications = app(NotificationService::class);
    }

    private function userWith(string $permission, string $name = 'Approver'): User
    {
        $role = Role::findOrCreate('role-'.md5($permission.$name), 'web');
        $role->givePermissionTo($permission);

        $user = User::query()->create([
            'name' => $name,
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function bill(): ApBill
    {
        return $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'description' => 'Material',
            'dpp' => 50_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-'.str()->random(5),
        ]);
    }

    public function test_submitting_a_document_notifies_everyone_who_can_approve_it(): void
    {
        $approver = $this->userWith('fin.approve', 'Direktur Keuangan');
        $submitter = $this->userWith('fin.create', 'Staf AP');

        $this->bill()->submit($submitter);

        $inbox = Notification::query()->where('user_id', $approver->id)->get();

        $this->assertCount(1, $inbox);
        $this->assertSame(Notification::SUBMITTED, $inbox[0]->event);
        $this->assertStringContainsString('menunggu persetujuan', $inbox[0]->title);
        $this->assertStringContainsString('Staf AP', $inbox[0]->body);
        $this->assertSame($submitter->id, (int) $inbox[0]->actor_id);
    }

    /**
     * The approve permission is per module. A procurement approver who also sees
     * every finance document stops reading either queue.
     */
    public function test_only_approvers_of_that_module_are_notified(): void
    {
        $finance = $this->userWith('fin.approve', 'Keuangan');
        $procurement = $this->userWith('prc.approve', 'Pengadaan');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $this->assertSame(1, Notification::query()->where('user_id', $finance->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $procurement->id)->count());
    }

    /** A notification about your own click is noise. */
    public function test_the_submitter_is_not_told_about_their_own_submission(): void
    {
        $both = $this->userWith('fin.approve', 'Manajer');

        $this->bill()->submit($both);

        $this->assertSame(0, Notification::query()->where('user_id', $both->id)->count());
    }

    public function test_an_inactive_approver_is_not_notified(): void
    {
        $approver = $this->userWith('fin.approve', 'Sudah Resign');
        $approver->forceFill(['is_active' => false])->save();

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $this->assertSame(0, Notification::query()->where('user_id', $approver->id)->count());
    }

    public function test_approving_tells_the_person_who_submitted_it(): void
    {
        $approver = $this->userWith('fin.approve', 'Direktur');
        $submitter = $this->userWith('fin.create', 'Staf AP');

        $bill = $this->bill();
        $bill->submit($submitter);
        Notification::query()->delete();          // isolate the decision notification

        $bill->approve($approver, 'Sesuai kontrak');

        $inbox = Notification::query()->where('user_id', $submitter->id)->get();

        $this->assertCount(1, $inbox);
        $this->assertSame(Notification::APPROVED, $inbox[0]->event);
        $this->assertStringContainsString('disetujui', $inbox[0]->title);
        $this->assertStringContainsString('Sesuai kontrak', $inbox[0]->body);
    }

    public function test_rejecting_tells_the_submitter_and_carries_the_reason(): void
    {
        $approver = $this->userWith('fin.approve', 'Direktur');
        $submitter = $this->userWith('fin.create', 'Staf AP');

        $bill = $this->bill();
        $bill->submit($submitter);
        Notification::query()->delete();

        $bill->reject($approver, 'Faktur pajak belum dilampirkan');

        $inbox = Notification::query()->where('user_id', $submitter->id)->firstOrFail();

        $this->assertSame(Notification::REJECTED, $inbox->event);
        $this->assertStringContainsString('Faktur pajak belum dilampirkan', $inbox->body);
    }

    public function test_a_notification_links_to_the_document_it_is_about(): void
    {
        $this->userWith('fin.approve', 'Direktur');
        $bill = $this->bill();
        $bill->submit($this->userWith('fin.create', 'Staf'));

        $notification = Notification::query()->firstOrFail();

        // d/ is the detail route; r/ is the list. A link to the wrong one
        // renders "halaman tidak dikenal" and the notification is useless.
        $this->assertSame("#/d/finance/ap-bills/{$bill->id}", $notification->link);
        $this->assertSame($bill->code, $notification->document_code);
        $this->assertSame(ApBill::class, $notification->document_type);
    }

    /**
     * THE ONE THAT MATTERS. These calls run inside approval flows that post to
     * the general ledger. A notification failure that rolled one back would turn
     * a cosmetic outage into a corrupted book.
     */
    public function test_a_delivery_failure_cannot_roll_back_the_approval(): void
    {
        $this->userWith('fin.approve', 'Direktur');
        $submitter = $this->userWith('fin.create', 'Staf');

        // Make the insert fail the way a schema drift or a full disk would. The
        // delivery outbox references core_notifications (a real FK on MySQL:
        // error 3730 "Cannot drop table … referenced by a foreign key", MySQL
        // full run 5 Sep 2026), so the child table goes first.
        DB::statement('DROP TABLE core_notification_deliveries');
        DB::statement('DROP TABLE core_notifications');

        $bill = $this->bill();
        $bill->submit($submitter);

        $this->assertSame('submitted', $bill->refresh()->status->value);
        $this->assertSame(1, $bill->approvals()->where('action', 'submitted')->count());
    }

    public function test_email_is_not_sent_unless_it_is_turned_on(): void
    {
        Mail::fake();

        $this->userWith('fin.approve', 'Direktur');
        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        Mail::assertNotSent(ApprovalNotificationMail::class);
    }

    public function test_email_goes_out_when_it_is_turned_on(): void
    {
        Mail::fake();
        app(SettingService::class)->set('notifications.email_enabled', true);

        $approver = $this->userWith('fin.approve', 'Direktur');
        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        Mail::assertSent(ApprovalNotificationMail::class, function (ApprovalNotificationMail $mail) use ($approver): bool {
            return $mail->hasTo($approver->email) && str_contains($mail->title, 'menunggu persetujuan');
        });
    }

    // ----------------------------------------------------------------- inbox

    public function test_marking_read_only_touches_your_own_notifications(): void
    {
        $mine = $this->userWith('fin.approve', 'Saya');
        $theirs = $this->userWith('fin.approve', 'Orang Lain');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));

        $theirNotification = Notification::query()->where('user_id', $theirs->id)->firstOrFail();

        $marked = $this->notifications->markRead($mine, [$theirNotification->id]);

        $this->assertSame(0, $marked);
        $this->assertNull($theirNotification->refresh()->read_at);
    }

    public function test_the_unread_count_is_per_user(): void
    {
        $first = $this->userWith('fin.approve', 'Pertama');
        $second = $this->userWith('fin.approve', 'Kedua');

        $this->bill()->submit($this->userWith('fin.create', 'Staf'));
        $this->bill()->submit($this->userWith('fin.create', 'Staf Dua'));

        $this->assertSame(2, $this->notifications->unreadCount($first));

        $this->notifications->markAllRead($first);

        $this->assertSame(0, $this->notifications->unreadCount($first));
        $this->assertSame(2, $this->notifications->unreadCount($second));
    }

    /**
     * Every approvable model must be in the registry, or its approvers are
     * silently never told. The trait is the only thing that knows the full list.
     */
    public function test_every_approvable_document_type_is_registered(): void
    {
        $missing = [];

        foreach ($this->approvableModels() as $class) {
            if (! ApprovableDocuments::knows($class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Approvable models absent from ApprovableDocuments: '.implode(', ', $missing));
    }

    /**
     * Every generated link has to match a route the SPA actually registers.
     * The router's own patterns are the source of truth, so they are read.
     */
    public function test_every_document_link_matches_a_route_the_spa_registers(): void
    {
        $router = (string) file_get_contents(public_path('app/js/app.js'));
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));

        $this->assertStringContainsString("route('d/*'", $router, 'the detail route pattern has changed');

        foreach (ApprovableDocuments::all() as $class => $entry) {
            $this->assertStringContainsString(
                "  '{$entry['resource']}': {",
                $schema,
                "{$class} links to resource [{$entry['resource']}], which is not a RESOURCES key.",
            );
        }
    }

    public function test_every_registered_permission_actually_exists(): void
    {
        $permissions = Permission::query()->pluck('name')->all();

        foreach (ApprovableDocuments::all() as $class => $entry) {
            $this->assertContains(
                $entry['prefix'].'.approve',
                $permissions,
                "ApprovableDocuments maps {$class} to a permission that is not seeded.",
            );
        }
    }

    /** @return list<class-string> */
    private function approvableModels(): array
    {
        $found = [];

        foreach (glob(base_path('Modules/*/Models/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'use Approvable;')) {
                continue;
            }

            preg_match('/namespace ([^;]+);/', $source, $namespace);
            $found[] = $namespace[1].'\\'.basename($file, '.php');
        }

        return $found;
    }
}
