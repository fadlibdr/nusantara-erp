<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\ErpTestCase;

/**
 * The command that has to run before erp1's Basic-auth gate can come down.
 *
 * Every assertion here is about one thing: that the command cannot leave the
 * demo in the state it was written to end. A rotation that silently skipped an
 * account, or accepted "password" as the new password, would read as success
 * and publish a writable ERP.
 */
class HardenDemoLoginsTest extends ErpTestCase
{
    private function seeded(string $email, string $password = 'password'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    public function test_it_rotates_every_account_still_on_the_shipped_password(): void
    {
        $admin = $this->seeded('admin@nusantara.test');
        $finance = $this->seeded('finance@nusantara.test');
        $alreadySafe = $this->seeded('owner@nusantara.test', 'sudah-diganti-2026');

        $this->artisan('erp:harden-demo-logins')
            ->expectsQuestion('New password for the accounts listed above', 'kunci-demo-baru-2026')
            ->expectsQuestion('Type it again', 'kunci-demo-baru-2026')
            ->assertSuccessful();

        $this->assertFalse(Hash::check('password', $admin->fresh()->password));
        $this->assertFalse(Hash::check('password', $finance->fresh()->password));
        $this->assertTrue(Hash::check('kunci-demo-baru-2026', $admin->fresh()->password));

        // An account that was never weak is not touched — the command's job is
        // the shipped password, not everybody's password.
        $this->assertTrue(Hash::check('sudah-diganti-2026', $alreadySafe->fresh()->password));
    }

    public function test_a_kept_account_stays_on_its_old_password(): void
    {
        $viewer = $this->seeded('demo@nusantara.test');
        $admin = $this->seeded('admin@nusantara.test');

        $this->artisan('erp:harden-demo-logins', ['--except' => ['demo@nusantara.test']])
            ->expectsQuestion('New password for the accounts listed above', 'kunci-demo-baru-2026')
            ->expectsQuestion('Type it again', 'kunci-demo-baru-2026')
            ->assertSuccessful();

        // The published, view-only login keeps working; the one that can post
        // journals does not.
        $this->assertTrue(Hash::check('password', $viewer->fresh()->password));
        $this->assertFalse(Hash::check('password', $admin->fresh()->password));
    }

    public function test_it_refuses_to_set_the_very_password_it_exists_to_remove(): void
    {
        $admin = $this->seeded('admin@nusantara.test');

        $this->artisan('erp:harden-demo-logins')
            ->expectsQuestion('New password for the accounts listed above', 'password')
            ->expectsQuestion('Type it again', 'password')
            ->assertFailed();

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_a_mistyped_confirmation_changes_nothing(): void
    {
        $admin = $this->seeded('admin@nusantara.test');

        $this->artisan('erp:harden-demo-logins')
            ->expectsQuestion('New password for the accounts listed above', 'kunci-demo-baru-2026')
            ->expectsQuestion('Type it again', 'kunci-demo-bary-2026')
            ->assertFailed();

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_a_short_password_changes_nothing(): void
    {
        $admin = $this->seeded('admin@nusantara.test');

        $this->artisan('erp:harden-demo-logins')
            ->expectsQuestion('New password for the accounts listed above', 'pendek')
            ->expectsQuestion('Type it again', 'pendek')
            ->assertFailed();

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_the_dry_run_asks_for_nothing_and_changes_nothing(): void
    {
        $admin = $this->seeded('admin@nusantara.test');

        $this->artisan('erp:harden-demo-logins', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_it_revokes_api_tokens_because_a_password_change_does_not(): void
    {
        $admin = $this->seeded('admin@nusantara.test');
        $admin->createToken('lama');

        $this->assertSame(1, $admin->tokens()->count());

        $this->artisan('erp:harden-demo-logins')
            ->expectsQuestion('New password for the accounts listed above', 'kunci-demo-baru-2026')
            ->expectsQuestion('Type it again', 'kunci-demo-baru-2026')
            ->assertSuccessful();

        // A bearer token issued while the account was open outlives the
        // rotation unless it is dropped, which would leave the gate removal
        // meaningless for whoever already holds one.
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_a_clean_installation_reports_nothing_to_do(): void
    {
        $this->seeded('owner@nusantara.test', 'sudah-diganti-2026');

        $this->artisan('erp:harden-demo-logins')
            ->expectsOutputToContain('No account is still on the seeded password')
            ->assertSuccessful();
    }
}
