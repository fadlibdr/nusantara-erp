<?php

namespace Modules\Iam\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Baris `personal_access_tokens`, dengan satu kolom tambahan dan tiga aturan.
 *
 * MENURUNI `Laravel\Sanctum\PersonalAccessToken`, BUKAN `Modules\Core\Models\
 * BaseModel` (CONVENTIONS §1). Sanctum menyelesaikan token lewat
 * `Sanctum::$personalAccessTokenModel::findToken()` dan memanggil
 * `can()/cant()` di atas instans itu; sebuah model yang tidak menuruni
 * kelas Sanctum tidak akan pernah dipakai Guard. Penyimpangan ini disebut di
 * LAPORAN P-3d §14.
 *
 * TIGA ATURAN:
 *
 *  1. `kind` memisahkan token SESI SPA (`session`, dan NULL untuk setiap baris
 *     yang lahir sebelum paket ini) dari token PRIBADI (`personal`). Pemisahan
 *     itulah yang membuat plafon global 720 menit bisa terus berlaku pada yang
 *     pertama tanpa membunuh yang kedua — lihat `IamServiceProvider::
 *     registerTokenExpiryPolicy()`.
 *  2. `MAX_LIFETIME_DAYS` = 365. Roadmap menulis "kedaluwarsa ≤ 1 tahun", dan
 *     angkanya ditegakkan di `ApiTokenStoreRequest`, bukan di sini — sebuah
 *     model tidak menolak permintaan.
 *  3. `abilities` token pribadi adalah nama IZIN (`fin.view`, `prj.approve`),
 *     bukan kosakata sendiri. `['*']` adalah token sesi SPA dan berarti
 *     "seluruh izin penggunanya" — perilaku yang sudah ada sejak
 *     `AuthController::login` ditulis, dan yang tidak boleh berubah.
 */
class ApiToken extends PersonalAccessToken
{
    /** Token yang dibuat `AuthController::login` untuk cangkang SPA. */
    public const KIND_SESSION = 'session';

    /** Token yang dibuat pemiliknya sendiri di Profil › Token API. */
    public const KIND_PERSONAL = 'personal';

    /** "kedaluwarsa ≤ 1 tahun" (ROADMAP-HASHMICRO Fase 3, P-3d). */
    public const MAX_LIFETIME_DAYS = 365;

    /**
     * Ability yang berarti "seluruh izin penggunanya".
     *
     * Sanctum menyimpannya sebagai `["*"]` ketika `createToken()` dipanggil
     * tanpa daftar ability, yang persis yang dilakukan `login`.
     */
    public const ABILITY_ALL = '*';

    /**
     * DIDEKLARASIKAN, karena Eloquent menurunkan nama tabel dari nama KELAS.
     *
     * `Laravel\Sanctum\PersonalAccessToken` tidak pernah menuliskannya — ia
     * tidak perlu, namanya sudah menurunkan `personal_access_tokens`. Kelas
     * ini bernama lain, jadi tanpa baris ini setiap penulisan token menuju
     * tabel `api_tokens` yang tidak ada, dan SELURUH login mati dengan
     * "no such table" (terukur saat uji kedaluwarsa pertama dijalankan).
     */
    protected $table = 'personal_access_tokens';

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'kind',
        'expires_at',
    ];

    /** Token pribadi — yang dinilai dari `expires_at`-nya sendiri. */
    public function isPersonal(): bool
    {
        return $this->kind === self::KIND_PERSONAL;
    }

    /** @param  Builder<self>  $query */
    public function scopePersonal(Builder $query): void
    {
        $query->where('kind', self::KIND_PERSONAL);
    }

    /**
     * Daftar ability baris ini, selalu sebagai list string.
     *
     * Sanctum menyimpan kolomnya sebagai JSON dan menerima null; keduanya
     * dipulangkan sebagai array kosong supaya pemanggil tidak pernah
     * memeriksa bentuk.
     *
     * @return list<string>
     */
    public function abilityList(): array
    {
        $abilities = $this->abilities;

        if (! is_array($abilities)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($ability): string => is_string($ability) ? $ability : '',
            $abilities,
        ), static fn (string $ability): bool => $ability !== ''));
    }

    /** Token ini membawa `*` — cangkang SPA, dan setiap token sebelum P-3d. */
    public function grantsEveryPermission(): bool
    {
        return in_array(self::ABILITY_ALL, $this->abilityList(), true);
    }
}
