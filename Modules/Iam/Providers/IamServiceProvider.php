<?php

namespace Modules\Iam\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Modules\Iam\Console\Commands\PermissionCheckCommand;
use Modules\Iam\Support\PasswordHelp;

class IamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // erp:permission-check — dijalankan deploy/sync-erp1.sh setelah migrate;
        // admin erp1 memegang 74/86 izin pada 4 Sep 2026 tanpa ada yang tahu.
        $this->commands([PermissionCheckCommand::class]);

        /*
         * Surat "lupa kata sandi" (T2.7). Notifikasi bawaan Laravel menunjuk
         * route('password.reset') — yang tidak ada di aplikasi ini — dan
         * berbahasa Inggris; keduanya diganti di sini, bukan dengan kelas
         * notifikasi baru, supaya User model dan broker tetap bawaan.
         * Kalimat "60 menit" dibaca dari config/auth.php, bukan diketik.
         */
        ResetPassword::createUrlUsing(
            static fn (User $user, string $token): string => PasswordHelp::resetUrl($user, $token)
        );
        ResetPassword::toMailUsing(static function (User $user, string $token): MailMessage {
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject('Atur ulang kata sandi Nusantara ERP')
                ->greeting('Halo '.$user->name.',')
                ->line('Ada permintaan mengatur ulang kata sandi akun Anda di Nusantara ERP.')
                ->action('Atur ulang kata sandi', PasswordHelp::resetUrl($user, $token))
                ->line("Tautan ini berlaku {$minutes} menit dan hanya sekali pakai.")
                ->line('Bila Anda tidak meminta pengaturan ulang, abaikan surat ini — kata sandi Anda tidak berubah.')
                ->salutation('Salam, '.config('erp.company.name'));
        });

        /*
         * Accept the API token from X-Api-Token as well as the standard
         * Authorization: Bearer header.
         *
         * A deployment may sit behind an HTTP-level gate — Basic auth on a demo
         * host, or an authenticating reverse proxy — and those own the
         * Authorization header. A browser that has satisfied a Basic challenge
         * attaches its credential automatically, but the moment JavaScript sets
         * Authorization on a fetch, the credential is REPLACED rather than
         * merged: the gate rejects the request and the SPA sees a 401 it reads
         * as an expired session, logging the user out on every call.
         *
         * Giving the token a header of its own removes the collision. Bearer
         * remains fully supported and is still what the API documents, so
         * existing clients and curl examples are unaffected.
         */
        Sanctum::getAccessTokenFromRequestUsing(
            static fn (Request $request): ?string => $request->header('X-Api-Token') ?: $request->bearerToken()
        );

        Route::middleware('api')
            ->prefix('api/iam')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
