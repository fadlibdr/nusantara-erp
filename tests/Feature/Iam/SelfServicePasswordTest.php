<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Modules\Iam\Support\PasswordHelp;
use Tests\ErpTestCase;

/**
 * Layanan mandiri kata sandi (T2.7) — menu akun hanya "Tutup · Keluar" dan
 * setiap penggantian sandi lewat administrator (HASIL-UJI §1, S9, 2 Sep 2026;
 * PANDUAN-PENGGUNA §0 kalimat 5).
 *
 * Tiga hal dipaku di sini. (1) PUT iam/me/password menolak sandi lama yang
 * salah dengan kalimat Indonesia dari lang/id/validation.php, dan setelah
 * berhasil sandi lama benar-benar ditolak di auth/login sementara yang baru
 * diterima. (2) Halaman masuk tidak menebak: GET iam/auth/password-help
 * mengatakan apakah tautan lewat email sungguh terkirim (MAIL_MAILER bukan
 * log/array/null) dan siapa administratornya. (3) Alur "lupa kata sandi"
 * memakai broker Laravel — tautan mengarah ke #/reset-password di SPA, surat
 * berbahasa Indonesia, akun nonaktif dan email tak dikenal dijawab dengan
 * kalimat yang sama (tidak membocorkan siapa yang terdaftar).
 */
class SelfServicePasswordTest extends ErpTestCase
{
    private const OLD = 'password';

    private const NEW = 'sandi-baru-2026';

    private function user(array $overrides = []): User
    {
        /** @var User $user */
        $user = User::query()->create(array_merge([
            'name' => 'Rina Kartika',
            'email' => 'rina@test.local',
            'password' => self::OLD,
            'is_active' => true,
        ], $overrides));

        return $user;
    }

    private function asUser(User $user): static
    {
        return $this->withHeader('X-Api-Token', $user->createToken('test')->plainTextToken);
    }

    private function loginStatus(string $email, string $password): int
    {
        return $this->postJson('/api/iam/auth/login', ['email' => $email, 'password' => $password])->status();
    }

    // ------------------------------------------------------- ganti kata sandi

    public function test_a_wrong_current_password_is_refused_in_indonesian_and_nothing_changes(): void
    {
        $user = $this->user();

        $this->asUser($user)
            ->putJson('/api/iam/me/password', [
                'current' => 'bukan-sandi-saya',
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current.0', 'Kata sandi saat ini salah.')
            ->assertJsonPath('message', 'Kata sandi saat ini salah.');

        $this->assertSame(200, $this->loginStatus($user->email, self::OLD), 'the old password still works');
        $this->assertSame(401, $this->loginStatus($user->email, self::NEW), 'the new one was never stored');
    }

    public function test_a_successful_change_refuses_the_old_password_and_accepts_the_new_one(): void
    {
        $user = $this->user();

        $this->asUser($user)
            ->putJson('/api/iam/me/password', [
                'current' => self::OLD,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Kata sandi Anda diperbarui.');

        $this->assertSame(401, $this->loginStatus($user->email, self::OLD), 'the old password is refused');
        $this->assertSame(200, $this->loginStatus($user->email, self::NEW), 'the new password is accepted');
    }

    /**
     * The token that made the change keeps working: the person is still at the
     * keyboard. Other sessions are left alone too — the same rule the admin
     * path documents (PANDUAN-ADMINISTRATOR §3.4), stated on the dialog.
     */
    public function test_the_session_that_changed_the_password_stays_signed_in(): void
    {
        $user = $this->user();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('X-Api-Token', $token)
            ->putJson('/api/iam/me/password', [
                'current' => self::OLD,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertOk();

        $this->withHeader('X-Api-Token', $token)
            ->getJson('/api/iam/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_the_new_password_must_be_confirmed_and_at_least_eight_characters(): void
    {
        $user = $this->user();

        $this->asUser($user)
            ->putJson('/api/iam/me/password', [
                'current' => self::OLD,
                'password' => self::NEW,
                'password_confirmation' => 'lain',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Konfirmasi Kata sandi tidak cocok.');

        $this->asUser($user)
            ->putJson('/api/iam/me/password', [
                'current' => self::OLD,
                'password' => 'pendek',
                'password_confirmation' => 'pendek',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Kata sandi minimal 8 karakter.');

        $this->asUser($user)
            ->putJson('/api/iam/me/password', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.current.0', 'Kata sandi saat ini wajib diisi.')
            ->assertJsonPath('errors.password.0', 'Kata sandi wajib diisi.');

        $this->assertSame(200, $this->loginStatus($user->email, self::OLD));
    }

    public function test_changing_the_password_needs_a_session(): void
    {
        $this->putJson('/api/iam/me/password', [
            'current' => self::OLD,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertStatus(401);
    }

    // ------------------------------------------------------- halaman masuk

    /**
     * The login page shows "Lupa kata sandi" only when the server says a link
     * would reach a person; otherwise it names the administrator. Both facts
     * come from here, never from the SPA guessing.
     */
    public function test_password_help_reports_the_mailer_and_names_the_first_active_administrator(): void
    {
        $this->user(['name' => 'Bukan Admin', 'email' => 'staf@test.local']);
        $former = $this->adminUser(); // 'Test Admin', first admin by id
        $former->forceFill(['name' => 'Admin Lama', 'is_active' => false])->save();
        User::query()->create([
            'name' => 'Dewi Lestari', 'email' => 'dewi@test.local', 'password' => self::OLD, 'is_active' => true,
        ])->assignRole('admin');

        config(['mail.default' => 'log']);
        $this->getJson('/api/iam/auth/password-help')
            ->assertOk()
            ->assertJsonPath('data.reset_by_email', false)
            ->assertJsonPath('data.administrator', 'Dewi Lestari');

        config(['mail.default' => 'smtp']);
        $this->getJson('/api/iam/auth/password-help')
            ->assertOk()
            ->assertJsonPath('data.reset_by_email', true);

        // "Sent" to array/null reaches nobody either — the same honesty as log.
        foreach (['array', 'null'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertFalse(PasswordHelp::resetByEmail(), "mailer {$mailer} delivers to nobody");
        }
    }

    public function test_password_help_without_any_administrator_says_so_instead_of_inventing_one(): void
    {
        $this->getJson('/api/iam/auth/password-help')
            ->assertOk()
            ->assertJsonPath('data.administrator', null);
    }

    // ------------------------------------------------------- lupa kata sandi

    public function test_forgot_password_is_refused_while_mail_only_goes_to_the_log(): void
    {
        Notification::fake();
        $admin = $this->adminUser();
        $user = $this->user();
        config(['mail.default' => 'log']);

        $this->postJson('/api/iam/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengaturan ulang lewat email belum aktif di server ini. Minta Test Admin (administrator) mengatur ulang kata sandi Anda.');

        Notification::assertNothingSent();
        $this->assertSame('Test Admin', $admin->name);
    }

    public function test_forgot_password_sends_an_indonesian_link_into_the_spa(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $user = $this->user();

        $this->postJson('/api/iam/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'Jika email itu terdaftar dan aktif, tautan pengaturan ulang dikirim ke sana dan berlaku 60 menit.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $rendered = (string) $mail->render();

            $this->assertSame('Atur ulang kata sandi Nusantara ERP', $mail->subject);
            $this->assertSame('Atur ulang kata sandi', $mail->actionText);
            $this->assertStringStartsWith(url('/').'#/reset-password?token=', $mail->actionUrl);
            $this->assertStringContainsString('email='.rawurlencode($user->email), $mail->actionUrl);
            $this->assertStringContainsString('Halo Rina Kartika', $rendered);
            $this->assertStringContainsString('60 menit', $rendered);
            $this->assertStringContainsString('salin dan tempel', $rendered, 'the template subcopy is Indonesian too');
            $this->assertStringNotContainsString('Regards', $rendered);
            $this->assertStringNotContainsString('Hello!', $rendered);

            return true;
        });
    }

    /**
     * An unknown address and a deactivated account get the SAME 200 sentence
     * as a live one: a different answer would tell a stranger which emails
     * exist. Nothing is sent in either case.
     */
    public function test_forgot_password_answers_unknown_and_inactive_accounts_identically_and_sends_nothing(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $inactive = $this->user(['is_active' => false]);

        foreach ([$inactive->email, 'tidak-ada@test.local'] as $email) {
            $this->postJson('/api/iam/auth/forgot-password', ['email' => $email])
                ->assertOk()
                ->assertJsonPath('message', 'Jika email itu terdaftar dan aktif, tautan pengaturan ulang dikirim ke sana dan berlaku 60 menit.');
        }

        Notification::assertNothingSent();

        $this->postJson('/api/iam/auth/forgot-password', ['email' => 'bukan-email'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email harus berupa alamat email yang valid.');
    }

    public function test_a_second_link_within_a_minute_is_throttled(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $user = $this->user();

        $this->postJson('/api/iam/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/iam/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Tautan baru saja dikirim ke alamat itu. Tunggu satu menit sebelum meminta lagi.');

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_a_valid_reset_token_replaces_the_password_once(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ];

        $this->postJson('/api/iam/auth/reset-password', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Kata sandi diperbarui. Masuk dengan kata sandi baru Anda.');

        $this->assertSame(401, $this->loginStatus($user->email, self::OLD));
        $this->assertSame(200, $this->loginStatus($user->email, self::NEW));

        // Sekali pakai: the same link cannot be replayed to set a third password.
        $this->postJson('/api/iam/auth/reset-password', array_merge($payload, [
            'password' => 'sandi-ketiga-2026', 'password_confirmation' => 'sandi-ketiga-2026',
        ]))->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'Tautan pengaturan ulang tidak berlaku lagi (berlaku 60 menit, sekali pakai). Minta tautan baru dari halaman masuk.');

        $this->assertSame(200, $this->loginStatus($user->email, self::NEW));
    }

    public function test_a_bad_token_or_a_deactivated_account_cannot_reset(): void
    {
        $user = $this->user();
        $inactive = $this->user(['email' => 'nonaktif@test.local', 'is_active' => false]);
        $inactiveToken = Password::broker()->createToken($inactive);

        $this->postJson('/api/iam/auth/reset-password', [
            'token' => 'token-palsu',
            'email' => $user->email,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertStatus(422)->assertJsonPath('errors.token.0', 'Tautan pengaturan ulang tidak berlaku lagi (berlaku 60 menit, sekali pakai). Minta tautan baru dari halaman masuk.');

        $this->postJson('/api/iam/auth/reset-password', [
            'token' => $inactiveToken,
            'email' => $inactive->email,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertStatus(422);

        $this->assertSame(200, $this->loginStatus($user->email, self::OLD), 'untouched');
        $this->assertSame(403, $this->loginStatus($inactive->email, self::OLD), 'still the deactivated account, old password');
    }
}
