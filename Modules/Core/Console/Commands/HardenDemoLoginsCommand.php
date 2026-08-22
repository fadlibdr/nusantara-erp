<?php

namespace Modules\Core\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Rotate the seeded demo logins off the password they ship with.
 *
 * THE PROBLEM THIS EXISTS FOR. ProductionSeeder creates eleven accounts whose
 * password is literally "password", admin@nusantara.test among them, and that
 * account holds every permission in the system — it can post journals, close
 * periods, move stock and delete documents. On erp1 the only thing standing
 * between those accounts and the open internet is the nginx Basic-auth gate,
 * and the nginx config says so in as many words. Take the gate down without
 * running this first and the demo becomes a writable ERP that anyone who reads
 * the seeder can sign into.
 *
 * THE PASSWORD IS NOT GENERATED HERE, AND NOT ACCEPTED AS AN ARGUMENT. It is
 * prompted for with secret(), so it never reaches the shell history, the
 * process list, or a log line — all three of which are readable by anyone who
 * later gets a shell on the box, which is precisely the person this is meant to
 * stop. Whoever runs the command chooses the value and is the only one who ever
 * sees it.
 *
 * WHAT IT REFUSES. Anything under 12 characters, and anything on the short list
 * of passwords that are the reason this command was needed. The check is
 * deliberately crude: it is a guard against retyping "password", not a strength
 * meter.
 *
 * THE THING THIS DOES NOT SOLVE, and the reason to read on before removing the
 * gate: a demo that nobody can log into is not a demo. Publishing the site
 * means publishing a working login, so a rotated password that then appears on
 * the landing page is exactly where it started — one shared credential with
 * full write access. The durable answer is a login that CANNOT do damage: one
 * account holding .view permissions only, published freely, with every
 * write-capable account rotated to a value that is never published. Run this
 * with --except to keep that split, and see docs/DEPLOYMENT.md.
 */
class HardenDemoLoginsCommand extends Command
{
    protected $signature = 'erp:harden-demo-logins
        {--except=* : Emails to leave alone, e.g. a published view-only demo account}
        {--dry-run : List what would change and stop}';

    protected $description = 'Rotate the seeded demo accounts off their shipped password';

    /** Not a blocklist, a retype guard: the values this command exists to remove. */
    private const REFUSED = ['password', 'password123', 'demo', 'demo1234', 'rahasia', '12345678', 'nusantara'];

    private const MIN_LENGTH = 12;

    public function handle(): int
    {
        $except = array_map('strtolower', (array) $this->option('except'));

        $weak = User::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => Hash::check('password', $user->password));

        $targets = $weak->reject(fn (User $user): bool => in_array(strtolower((string) $user->email), $except, true));

        if ($weak->isEmpty()) {
            $this->info('No account is still on the seeded password. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line("Accounts still on the seeded password: {$weak->count()}");

        foreach ($weak as $user) {
            $kept = in_array(strtolower((string) $user->email), $except, true);
            $this->line(sprintf(
                '  %-34s %-22s %s',
                $user->email,
                $user->getRoleNames()->implode(','),
                $kept ? 'KEPT (--except)' : 'will be rotated',
            ));
        }

        // Named separately because it is the one that turns a browsing session
        // into a writable one: every permission the application defines.
        if ($targets->contains(fn (User $user): bool => $user->hasRole('admin'))) {
            $this->warn('  admin is in the rotation set — after this, nobody can sign in as admin without the new password.');
        }

        if ($targets->isEmpty()) {
            $this->warn('Every weak account was excluded. Nothing to rotate.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $password = (string) $this->secret('New password for the accounts listed above');
        $again = (string) $this->secret('Type it again');

        if ($password !== $again) {
            $this->error('The two entries do not match. Nothing was changed.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $this->error('At least '.self::MIN_LENGTH.' characters, please. Nothing was changed.');

            return self::FAILURE;
        }

        if (in_array(mb_strtolower($password), self::REFUSED, true)) {
            $this->error('That is one of the passwords this command exists to remove. Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($targets as $user) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        // Sanctum tokens survive a password change; anyone holding one issued
        // while the account was open keeps their access until it is dropped.
        $tokens = 0;

        foreach ($targets as $user) {
            $tokens += $user->tokens()->delete();
        }

        $this->info("Rotated {$targets->count()} accounts and revoked {$tokens} API tokens.");
        $this->line('Sessions are unaffected: SESSION_DRIVER=database keeps anyone already signed in, signed in.');

        return self::SUCCESS;
    }
}
