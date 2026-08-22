<?php

namespace Modules\Iam\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Production admin bootstrap: exactly one user, built from ERP_ADMIN_* env
 * variables (set in .env / the container environment — see
 * .env.production.example). Never seeds demo accounts; UserSeeder owns the
 * demo canon and must not run in production.
 *
 * Requires PermissionSeeder + RoleSeeder to have run first so the "admin"
 * role exists. Idempotent: re-running updates the same user (keyed by email).
 */
class AdminUserSeeder extends Seeder
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function run(): void
    {
        // env() (not config()) on purpose: these are one-shot bootstrap
        // secrets, not application config. In the production containers the
        // compose file injects .env via env_file, so they are present in the
        // process environment even when config is cached.
        $name = env('ERP_ADMIN_NAME');
        $email = env('ERP_ADMIN_EMAIL');
        $password = env('ERP_ADMIN_PASSWORD');

        if (! is_string($email) || trim($email) === '') {
            throw new RuntimeException(
                'ERP_ADMIN_EMAIL is not set. Set ERP_ADMIN_EMAIL and ERP_ADMIN_PASSWORD in the '
                .'environment, then re-run: php artisan db:seed --class=ProductionSeeder --force'
            );
        }

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'ERP_ADMIN_PASSWORD is not set. Set a strong password (min '
                .self::MIN_PASSWORD_LENGTH.' characters) in the environment, then re-run: '
                .'php artisan db:seed --class=ProductionSeeder --force'
            );
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException(
                'ERP_ADMIN_PASSWORD is too short: it must be at least '
                .self::MIN_PASSWORD_LENGTH.' characters. Refusing to seed a weak admin password.'
            );
        }

        /** @var User $user */
        $user = User::query()->updateOrCreate(
            ['email' => trim($email)],
            [
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : 'Administrator',
                'password' => $password, // hashed by the model's "hashed" cast
                'is_active' => true,
            ],
        );

        // email_verified_at is not mass-assignable on User; set it explicitly.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->syncRoles(['admin']);
    }
}
