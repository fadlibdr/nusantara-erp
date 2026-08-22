<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\AuditedModels;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * Who changed what.
 *
 * Nothing recorded this before. A vendor's bank account could be edited, the PPN
 * rate changed, or a user deactivated, and the row was simply overwritten —
 * changing vendor bank details is the classic invoice-fraud vector, and it was
 * exactly the change that left no evidence.
 *
 * Two properties matter beyond "it writes a row": the log must never contain a
 * credential, and it must never be able to break the thing it observes.
 */
class AuditLogTest extends ErpTestCase
{
    private function vendor(): Vendor
    {
        return Vendor::query()->create([
            'code' => 'VND-'.str()->random(4),
            'name' => 'PT Uji Audit',
            'bank_account_no' => '1112223334',
            'is_subcontractor' => false,
            'classification' => 'material',
            'status' => 'active',
        ]);
    }

    public function test_changing_a_vendor_bank_account_is_recorded_with_both_values(): void
    {
        $vendor = $this->vendor();
        AuditLog::query()->delete();               // isolate from the create entry

        $vendor->forceFill(['bank_account_no' => '9998887776'])->save();

        $log = AuditLog::query()->sole();

        $this->assertSame('updated', $log->event);
        $this->assertSame(Vendor::class, $log->auditable_type);
        $this->assertSame('PT Uji Audit', $log->auditable_label);
        $this->assertSame('1112223334', $log->changes['bank_account_no']['from']);
        $this->assertSame('9998887776', $log->changes['bank_account_no']['to']);
    }

    public function test_the_actor_is_recorded(): void
    {
        $user = User::query()->create([
            'name' => 'Budi Auditor',
            'email' => 'budi@nusantara.test',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $vendor = $this->vendor();

        // Scoped by type as well as id: the User created above is audited too,
        // and both rows are id 1.
        $log = AuditLog::query()
            ->where('auditable_type', Vendor::class)
            ->where('auditable_id', $vendor->id)
            ->sole();

        $this->assertSame($user->id, (int) $log->user_id);
        $this->assertSame('Budi Auditor', $log->user_name);
    }

    /**
     * A seeder or console command legitimately has no user. The change is still
     * recorded — attributing it to whoever is convenient would be worse than
     * recording that nobody was signed in.
     */
    public function test_a_change_with_no_signed_in_user_is_still_recorded(): void
    {
        $vendor = $this->vendor();

        $log = AuditLog::query()
            ->where('auditable_type', Vendor::class)
            ->where('auditable_id', $vendor->id)
            ->sole();

        $this->assertNull($log->user_id);
        $this->assertSame('created', $log->event);
    }

    /** A password hash is still a credential, and the log is read by more people. */
    public function test_a_password_never_reaches_the_log(): void
    {
        $user = User::query()->create([
            'name' => 'Siti',
            'email' => 'siti@nusantara.test',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
        ]);

        $user->forceFill(['password' => bcrypt('a-different-password')])->save();

        foreach (AuditLog::query()->get() as $log) {
            foreach (AuditedModels::NEVER_LOGGED as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $log->changes, "[{$forbidden}] must never be logged");
            }
        }

        $this->assertStringNotContainsString(
            'secret-password',
            (string) DB::table('core_audit_log')->pluck('changes')->implode(' '),
        );
    }

    public function test_deactivating_a_user_is_recorded(): void
    {
        $user = User::query()->create([
            'name' => 'Agus',
            'email' => 'agus@nusantara.test',
            'password' => bcrypt('x'),
            'is_active' => true,
        ]);
        AuditLog::query()->delete();

        $user->forceFill(['is_active' => false])->save();

        $log = AuditLog::query()->sole();

        $this->assertSame('updated', $log->event);
        $this->assertArrayHasKey('is_active', $log->changes);
    }

    /** Editing a rate on the settings screen has to be attributable. */
    public function test_changing_a_setting_is_recorded(): void
    {
        app(SettingService::class)->set('tax.ppn_rate', 12.0);

        $log = AuditLog::query()->where('auditable_type', Setting::class)->latest('id')->first();

        $this->assertNotNull($log, 'a settings change must be recorded');
        $this->assertSame('tax.ppn_rate', $log->auditable_label);
    }

    /**
     * A save that changes nothing must not produce a log entry, or the log fills
     * with rows saying something happened when nothing did.
     */
    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $vendor = $this->vendor();
        AuditLog::query()->delete();

        $vendor->save();
        $vendor->forceFill(['name' => 'PT Uji Audit'])->save();

        $this->assertSame(0, AuditLog::query()->count());
    }

    /**
     * THE ONE THAT MATTERS. The observer runs inside the transaction saving the
     * row. A logging failure that rolled that back would turn a missing audit
     * line into a lost edit.
     */
    public function test_a_logging_failure_cannot_break_the_change_it_observes(): void
    {
        $vendor = $this->vendor();

        DB::statement('DROP TABLE core_audit_log');

        $vendor->forceFill(['bank_account_no' => '5556667778'])->save();

        $this->assertSame('5556667778', $vendor->fresh()->bank_account_no);
    }

    public function test_every_audited_class_exists(): void
    {
        foreach (AuditedModels::classes() as $class) {
            $this->assertTrue(class_exists($class), "{$class} is registered for auditing but does not exist");
        }
    }
}
