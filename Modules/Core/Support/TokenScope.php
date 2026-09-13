<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ABILITY TOKEN YANG TIDAK DITEGAKKAN ADALAH KEBOHONGAN (P-3d, perangkap A).
 *
 * Sampai paket ini, setiap token di aplikasi ini membawa `["*"]` dan TIDAK ADA
 * satu pun `tokenCan()` di seluruh kode (grep = 0): gerbangnya adalah middleware
 * `permission` milik spatie, yang memeriksa izin PENGGUNA. Sebuah layar yang
 * menawarkan "abilities = subset izin" di atas dasar itu akan menjual sebuah
 * kendali yang tidak ada — token bernama "hanya baca proyek" milik integrasi
 * pihak ketiga diam-diam bisa MENYETUJUI PEMBAYARAN.
 *
 * SATU TEMPAT, DAN INILAH ALASAN TEMPAT ITU DIPILIH.
 *
 * Setiap pemeriksaan izin di aplikasi ini — middleware rute (`canAny`), Gate
 * yang didaftarkan spatie (`checkPermissionTo`), `$request->user()->can()` di
 * dalam controller, `hasAnyPermission()` di dalam service — bermuara pada SATU
 * metode: `App\Models\User::hasPermissionTo()`. Di sanalah penyempitan ini
 * dipasang, jadi tidak ada rute yang bisa melewatinya karena seseorang lupa
 * menambahkan sesuatu pada rutenya. Sebagian rute `api/` TIDAK membawa
 * `permission:` di rutenya sama sekali dan memeriksa izin di dalam controller
 * (mis. `AttachmentController`, yang menurunkan izin dari DOKUMEN-nya, bukan
 * dari rutenya), jadi sebuah gerbang ability yang membaca parameter
 * `permission:` rute akan membiarkan rute TULIS di antaranya tanpa penjaga
 * sama sekali.
 *
 * BERAPA BANYAK — TANYAKAN, JANGAN KUTIP (pelajaran 5, V-TOKEN-4). Angka itu
 * tumbuh setiap kali modul mana pun menambah endpoint; versi pertama komentar
 * ini menuliskan sensus `main` 8438066 (852 / 637 / 215, dan 29 rute tulis
 * tanpa gerbang) tanpa mengatakan dari pohon mana, dan sudah salah di cabang
 * yang memuatnya. Yang dipaku adalah DAFTAR LITERAL rute tulis tanpa gerbang
 * izin di `UngatedApiRouteCensusTest`; jumlah berjalannya diukur dengan
 * `php artisan route:list --json`.
 *
 * `Gate::before` SENGAJA TIDAK DIPAKAI untuk menolak. Spatie mendaftarkan
 * `Gate::before`-nya sendiri lewat `callAfterResolving(Gate::class)` di dalam
 * `register()`, jadi ia selalu callback PERTAMA; `Gate` memulangkan hasil
 * non-null pertama, dan callback spatie memulangkan `true` untuk izin yang
 * dimiliki pengguna. Sebuah `before` kedua yang menolak tidak akan pernah
 * dipanggil untuk kasus yang justru harus ditolaknya.
 *
 * SIAPA YANG DIPERSEMPIT — SATU SUMBER, DAN HANYA SATU. Token yang dipakai
 * permintaan ini diingat dari peristiwa `Laravel\Sanctum\Events\
 * TokenAuthenticated`: satu-satunya titik yang dilewati SETIAP permintaan yang
 * masuk lewat bearer token, dipancar Guard sesudah barisnya dinyatakan sah.
 * Karena yang diingat adalah BARISNYA (bukan instans User-nya), sebuah
 * `User::find($id)` yang segar di dalam sebuah service ikut dipersempit.
 *
 * `currentAccessToken()` SENGAJA TIDAK dibaca sebagai sumber kedua, dan
 * harganya diukur: `Sanctum::actingAs($user)` — bentuk yang dipakai ratusan uji
 * di repositori ini — memasang sebuah MOCKERY dari model token dengan daftar
 * ability KOSONG (`actingAs($user, $abilities = [])`, lalu `shouldReceive('can')`
 * hanya untuk ability yang disebut). `can()` pada dobel itu memulangkan nilai
 * falsy untuk apa pun, jadi membacanya berarti setiap sesi uji kehilangan
 * SELURUH izinnya: 23 uji di tests/Feature/Core berubah menjadi 403 «core.update»,
 * «prj.create», «crm.approve» — kegagalan yang tidak punya padanan di produksi,
 * karena token sungguhan selalu punya daftar ability sungguhan. Aturannya karena
 * itu sesempit yang bisa dikatakan: penyempitan hanya pernah datang dari baris
 * token yang BENAR-BENAR diautentikasi Guard.
 *
 * YANG TIDAK DIPERSEMPIT, dan itu disengaja:
 *
 *  - `["*"]` — token cangkang SPA dan setiap baris yang lahir sebelum P-3d.
 *    `PersonalAccessToken::can()` milik Sanctum memulangkan true untuk apa pun
 *    bila daftar abilitynya memuat `*`. Kalau ini berubah, seluruh aplikasi mati.
 *  - `TransientToken` — sesi pihak pertama (cookie). Tidak ada token yang bisa
 *    membatasinya.
 *  - Pemanggilan tanpa permintaan HTTP: perintah artisan, pekerja antrean,
 *    seeder. Tidak ada token yang diingat, jadi tidak ada yang dipersempit.
 *
 * ABILITY ADALAH SUBSET, BUKAN PEMBERIAN. Penyempitan ini berjalan SESUDAH
 * `parent::hasPermissionTo()` memulangkan true, jadi ia hanya pernah MENGURANGI:
 * izin yang dicabut dari peran mencabut aksesnya token pada permintaan
 * berikutnya juga, tanpa satu baris pun yang menyentuh tokennya.
 */
final class TokenScope
{
    /**
     * Ability yang berarti "seluruh izin penggunanya" (`["*"]`).
     *
     * Ditulis di sini DAN di `Modules\Iam\Models\ApiToken::ABILITY_ALL` karena
     * Core tidak meng-import modul fitur; `ApiTokenAbilityMatrixTest` memaku
     * keduanya sama.
     */
    public const ABILITY_ALL = '*';

    /**
     * Kalimat penolakan — satu kalimat, dengan ability yang KURANG disebut.
     *
     * `%s` = daftar ability yang ditolak permintaan ini.
     */
    public const REFUSAL = 'Token API yang Anda pakai tidak memiliki ability %s. '
        .'Ability sebuah token dipilih saat token dibuat dan tidak bisa ditambah sesudahnya — '
        .'buat token baru di Profil › Token API bila integrasi ini memang perlu memanggilnya.';

    private ?PersonalAccessToken $token = null;

    /** @var array<string, true> */
    private array $denied = [];

    /**
     * Token yang dipakai permintaan ini, dari `TokenAuthenticated`.
     */
    public function remember(mixed $token): void
    {
        if ($token instanceof PersonalAccessToken) {
            $this->token = $token;
        }
    }

    /**
     * Boleh pengguna ini memakai izin ini LEWAT TOKEN YANG SEDANG DIPAKAI?
     *
     * Memulangkan true ketika tidak ada token yang berpendapat — itulah
     * keadaan setiap perintah artisan, setiap pekerja antrean, dan setiap
     * sesi pihak pertama.
     */
    public function allows(User $user, string $ability): bool
    {
        $token = $this->tokenFor($user);

        if ($token === null || $token->can(self::ABILITY_ALL) || $token->can($ability)) {
            return true;
        }

        $this->denied[$ability] = true;

        return false;
    }

    /**
     * Ability token yang sedang mempersempit pengguna ini, atau null bila
     * tidak ada token yang berpendapat (V-TOKEN-1).
     *
     * SATU-SATUNYA PINTU LEWAT MANA SEBUAH TOKEN BISA MENGETAHUI BATASNYA
     * SENDIRI. `GET iam/auth/me` memulangkan `permissions` — izin ORANGNYA,
     * yang untuk akun admin memuat `fin.approve` — dan sampai putaran
     * verifikasi ini tidak ada field mana pun yang menyebut ability tokennya;
     * `iam/me/api-tokens` ditutup `SessionOnly` justru untuk token, jadi tidak
     * ada pintu lain sama sekali. Sumbernya sengaja SAMA dengan sumber
     * penegakannya: yang dikatakan field itu persis yang menyempitkan
     * permintaan ini, bukan tebakan yang bisa berbeda.
     *
     * @return list<string>|null
     */
    public function abilitiesFor(User $user): ?array
    {
        $token = $this->tokenFor($user);

        if ($token === null) {
            return null;
        }

        return array_values(array_filter((array) $token->abilities, 'is_string'));
    }

    /** Ability yang ditolak permintaan ini, untuk kalimat 403. */
    public function deniedAbilities(): array
    {
        return array_keys($this->denied);
    }

    /**
     * Kalimat 403 untuk permintaan ini, atau null bila token bukan sebabnya.
     */
    public function refusalSentence(): ?string
    {
        $denied = $this->deniedAbilities();

        if ($denied === []) {
            return null;
        }

        sort($denied);

        return sprintf(self::REFUSAL, '«'.implode('», «', $denied).'»');
    }

    /**
     * Baris token yang berpendapat atas pengguna ini, atau null.
     *
     * Pemiliknya diperiksa: sebuah permintaan bisa membaca izin ORANG LAIN
     * (delegasi memeriksa izin PEMBERINYA, maker-checker memeriksa izin
     * pengaju), dan token pemanggil tidak boleh mempersempit jawaban tentang
     * orang yang bukan pemiliknya.
     */
    private function tokenFor(User $user): ?PersonalAccessToken
    {
        $remembered = $this->token;

        if ($remembered === null
            || (int) $remembered->tokenable_id !== (int) $user->getKey()
            || $remembered->tokenable_type !== $user->getMorphClass()) {
            return null;
        }

        return $remembered;
    }

    /**
     * Ability yang SAH untuk sebuah token pribadi = nama izin yang benar-benar
     * dicetak PermissionSeeder, dan tidak pernah `*`.
     *
     * Dipakai `ApiTokenStoreRequest` (Iam) lewat pemanggilnya; ditaruh di sini
     * supaya satu-satunya definisi "ability itu nama izin" hidup di sebelah
     * penegakannya.
     */
    public static function isGrantableAbility(string $ability): bool
    {
        return $ability !== self::ABILITY_ALL
            && $ability !== ''
            && ! Str::contains($ability, ['*', ' ']);
    }
}
