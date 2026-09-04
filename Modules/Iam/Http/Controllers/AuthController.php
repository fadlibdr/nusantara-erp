<?php

namespace Modules\Iam\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Modules\Core\Http\ApiController;
use Modules\Iam\Http\Requests\ChangePasswordRequest;
use Modules\Iam\Http\Requests\ForgotPasswordRequest;
use Modules\Iam\Http\Requests\LoginRequest;
use Modules\Iam\Http\Requests\ResetPasswordRequest;
use Modules\Iam\Http\Resources\UserResource;
use Modules\Iam\Support\PasswordHelp;

class AuthController extends ApiController
{
    /** Sama persis dengan kalimat yang ditampilkan app.js setelah "Kirim tautan". */
    private const LINK_SENT_MESSAGE = 'Jika email itu terdaftar dan aktif, tautan pengaturan ulang dikirim ke sana dan berlaku 60 menit.';

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            return $this->error('Email atau password salah.', 401);
        }

        if (! $user->is_active) {
            return $this->error('Akun Anda dinonaktifkan. Hubungi administrator.', 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ], 'Login berhasil');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logout berhasil');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()->load('roles')));
    }

    /*
     * Layanan mandiri kata sandi (T2.7). Sampai 2 Sep 2026 menu akun hanya
     * "Tutup · Keluar" (HASIL-UJI §1, S9) dan PANDUAN-PENGGUNA §0 kalimat 5
     * berbunyi "Anda tidak bisa mengganti kata sandi sendiri" — setiap
     * penggantian lewat pemegang iam.update.
     */

    /**
     * PUT iam/me/password — sandi lama diperiksa di ChangePasswordRequest
     * (current_password:sanctum), jadi yang sampai ke sini sudah sah.
     *
     * Token yang memanggil TETAP hidup, dan sesi di perangkat lain juga —
     * aturan yang sama dengan penggantian lewat Sistem › Pengguna
     * (PANDUAN-ADMINISTRATOR §3.4); dialognya menyebut itu apa adanya.
     * Memutus perangkat lain adalah tindakan lain (menonaktifkan akun
     * menghapus seluruh token), bukan efek samping diam-diam dari sini.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        // Cast 'hashed' pada model yang menghitung hash-nya.
        $request->user()->forceFill(['password' => $request->input('password')])->save();

        return $this->ok(null, 'Kata sandi Anda diperbarui.');
    }

    /**
     * GET iam/auth/password-help — publik, saudara demo-accounts: halaman masuk
     * menanyakan apakah tautan email sungguh sampai (MAIL_MAILER bukan
     * log/array/null) dan siapa administratornya, alih-alih menebak.
     */
    public function passwordHelp(): JsonResponse
    {
        return $this->ok([
            'reset_by_email' => PasswordHelp::resetByEmail(),
            'administrator' => PasswordHelp::administratorName(),
        ]);
    }

    /**
     * POST iam/auth/forgot-password { email }.
     *
     * Satu kalimat 200 untuk alamat yang terdaftar, yang tidak, dan yang
     * nonaktif: jawaban yang berbeda memberi tahu orang luar email siapa yang
     * ada di sistem. Yang membedakan hanya throttle broker (satu tautan per
     * menit per akun) dan 409 selagi surat hanya masuk log — di keadaan itu
     * SPA tidak menampilkan tombolnya, dan klien API mendapat kalimat yang
     * sama dengan halaman masuk.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        if (! PasswordHelp::resetByEmail()) {
            return $this->error(
                'Pengaturan ulang lewat email belum aktif di server ini. '.PasswordHelp::askAdministrator(),
                409,
            );
        }

        // is_active ikut ke retrieveByCredentials: akun yang dinonaktifkan
        // tidak boleh hidup lagi lewat tautan email.
        $status = Password::broker()->sendResetLink([
            'email' => $request->input('email'),
            'is_active' => true,
        ]);

        if ($status === Password::RESET_THROTTLED) {
            return $this->error('Tautan baru saja dikirim ke alamat itu. Tunggu satu menit sebelum meminta lagi.', 429);
        }

        return $this->ok(null, self::LINK_SENT_MESSAGE);
    }

    /**
     * POST iam/auth/reset-password { token, email, password, password_confirmation }.
     * Broker Laravel: token bcrypt di password_reset_tokens, 60 menit
     * (config/auth.php), dihapus setelah dipakai.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            [
                'email' => $request->input('email'),
                'token' => $request->input('token'),
                'password' => $request->input('password'),
                'is_active' => true,
            ],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $message = 'Tautan pengaturan ulang tidak berlaku lagi (berlaku 60 menit, sekali pakai). Minta tautan baru dari halaman masuk.';

            return $this->error($message, 422, ['token' => [$message]]);
        }

        return $this->ok(null, 'Kata sandi diperbarui. Masuk dengan kata sandi baru Anda.');
    }
}
