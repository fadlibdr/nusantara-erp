<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Models\SavedReport;
use Modules\Core\Support\ReportableResources;
use Modules\Core\Support\ReportDefinition;

/**
 * Menyimpan, membagikan dan menjaga kepemilikan Laporan Bebas (Fase 1 / P1-F).
 *
 * KEPEMILIKAN DIJAGA DI SINI, BUKAN DI CONTROLLER, dan ia melempar 422 — bukan
 * 403. Itu bentuk yang sudah ada di codebase ini persis satu kali:
 * `PettyCashVoucherService::assertCustodian` melempar dari SERVICE dan
 * dirender sebagai 422 dengan kalimat yang menyebut dokumennya. Alasannya sama
 * di sini: 403 berarti "Anda tidak boleh berada di halaman ini", padahal orang
 * ini BOLEH melihat laporannya — ia hanya bukan pemiliknya. Kalimatnya karena
 * itu menyebut TIGA hal: laporannya, pemiliknya, dan jalan keluarnya
 * ("Simpan sebagai salinan"), sama seperti assertCustodian menyebut kode dana
 * lalu memberi tahu apa yang harus dilakukan.
 *
 * TIDAK ADA JALAN PINTAS ADMIN. Sebuah laporan adalah pertanyaan yang disusun
 * seseorang; admin yang menyuntingnya diam-diam mengubah angka yang dibawa
 * orang lain ke rapat. Admin boleh MENYALINNYA, seperti semua orang.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * BERBAGI PER PERAN, dan kenapa NAMA
 *
 * Ini tabel pertama di sistem ini yang menyebut peran sama sekali, jadi kedua
 * arahnya baru:
 *
 *  - Id peran tidak bisa membawa FK — peran milik Iam, tabel ini milik Core,
 *    dan CONVENTIONS §3 melarang FK lintas modul. Sebuah id yang perannya
 *    dihapus menggantung tanpa cascade dan tanpa tanda apa pun.
 *  - Nama adalah yang SUDAH diseberangkan ke klien (`iam/auth/me` mengirim
 *    `roles: [nama]`) dan yang dibaca `$user->hasRole($name)`, yang TIDAK
 *    PERNAH melempar. Scope `User::role($name)` milik Spatie sebaliknya
 *    melempar RoleDoesNotExist untuk nama basi — 500, bukan daftar kosong.
 *
 * Nama basi hanya lahir dari penggantian nama, dan basinya TERLIHAT: penulisan
 * memvalidasi setiap nama terhadap tabel peran yang hidup (lewat
 * config('permission.table_names.roles'), idiom Iam — bukan model Role, supaya
 * jebakan guard 'sanctum' tidak pernah disentuh), dan pembacaan menandai peran
 * yang sudah tidak ada sehingga daftar laporan mengatakannya alih-alih diam-
 * diam berhenti membagikan.
 * ────────────────────────────────────────────────────────────────────────────
 */
final class SavedReportService
{
    /** Laporan tersimpan maksimum per orang — bukan plafon teknis, tetapi ia ada. */
    public const MAX_PER_USER = 60;

    /**
     * Laporan yang boleh DIBACA $user: miliknya sendiri, plus yang dibagikan
     * ke salah satu perannya — dan selalu disaring lagi oleh izin SUMBERnya,
     * karena berbagi tidak boleh memberi akses yang tidak dipunyai orangnya.
     *
     * @return list<SavedReport>
     */
    public function visibleTo(User $user): array
    {
        $roles = $user->getRoleNames()->all();

        $rows = SavedReport::query()
            ->where(function ($query) use ($user, $roles): void {
                $query->where('user_id', $user->getKey());

                foreach ($roles as $role) {
                    // JSON disimpan sebagai TEXT (satu bentuk untuk kedua
                    // driver), jadi pencocokannya LIKE atas nama yang dikutip —
                    // dan hasilnya disaring lagi di PHP, karena LIKE '%"pm"%'
                    // juga cocok untuk peran bernama 'pm-lama'.
                    $query->orWhere('shared_roles', 'like', '%'.json_encode($role).'%');
                }
            })
            ->orderBy('name')
            ->get()
            ->filter(function (SavedReport $report) use ($user, $roles): bool {
                if ($report->user_id !== $user->getKey() && ! array_intersect($roles, $report->sharedRoles())) {
                    return false;
                }

                // Berbagi tidak memberi akses baru: sumber yang izinnya tidak
                // dipegang tetap tidak terlihat, dan itu berarti laporan yang
                // dibagikan ke seluruh kantor pun hanya sampai kepada yang
                // memang boleh membaca angkanya.
                return ReportableResources::has($report->resource)
                    && ReportableResources::allows($user, ReportableResources::definition($report->resource));
            })
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Pemilik selalu boleh MENGELOLA barisnya sendiri — menamai ulang,
     * membagikan, menghapus — bahkan setelah izin sumbernya dicabut.
     *
     * Temuan verifikasi P1-F: `canRead()` menolak pada izin sumber SEBELUM
     * mempertimbangkan kepemilikan, dan ketiga verb pengelolaan bergerbang
     * padanya — sehingga seseorang yang kehilangan `fin.view` tidak bisa lagi
     * menghapus laporannya sendiri, dan barisnya tinggal selamanya tanpa satu
     * pun cara membuangnya. Membaca ANGKAnya tetap butuh izin (canRead);
     * membuang barisnya tidak, karena tidak ada data yang terbaca di situ.
     */
    public function canManage(User $user, SavedReport $report): bool
    {
        return $report->user_id === $user->getKey();
    }

    public function canRead(User $user, SavedReport $report): bool
    {
        if (! ReportableResources::has($report->resource)) {
            return false;
        }

        if (! ReportableResources::allows($user, ReportableResources::definition($report->resource))) {
            return false;
        }

        return $report->user_id === $user->getKey()
            || (bool) array_intersect($user->getRoleNames()->all(), $report->sharedRoles());
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException definisi atau peran yang tidak sah
     */
    public function create(User $user, array $input): SavedReport
    {
        $definition = $this->definitionFrom($input);
        $name = $this->nameFrom($input);
        $roles = $this->rolesFrom($input);

        /* Izin diperiksa saat MENULIS, bukan hanya saat membaca. Tanpa ini
           seseorang bisa menyimpan laporan atas sumber yang tidak boleh ia
           lihat: barisnya lalu tak terlihat olehnya (visibleTo menyaringnya)
           tetapi TETAP ADA — dan bila ia kemudian membagikannya ke peran yang
           memegang izin itu, ia telah menyusun laporan atas data yang tidak
           pernah boleh ia sentuh. */
        $entry = ReportableResources::definition($definition['resource']);

        if (! ReportableResources::allows($user, $entry)) {
            throw new InvalidArgumentException(sprintf(
                'Anda tidak memiliki hak akses %s untuk menyimpan laporan atas "%s".',
                implode(' atau ', $entry['permission']),
                $definition['resource'],
            ));
        }

        if (SavedReport::query()->where('user_id', $user->getKey())->count() >= self::MAX_PER_USER) {
            throw new InvalidArgumentException(sprintf(
                'Anda sudah menyimpan %d laporan. Hapus salah satunya sebelum menyimpan yang baru.',
                self::MAX_PER_USER,
            ));
        }

        if (SavedReport::query()->where('user_id', $user->getKey())->where('name', $name)->exists()) {
            throw new InvalidArgumentException(sprintf('Anda sudah punya laporan bernama "%s".', $name));
        }

        return SavedReport::query()->create([
            'user_id' => $user->getKey(),
            'name' => $name,
            'resource' => $definition['resource'],
            'definition' => $definition,
            'shared_roles' => $roles,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws LogicException bila bukan pemiliknya (dirender 422)
     */
    public function update(User $user, SavedReport $report, array $input): SavedReport
    {
        $this->assertOwner($user, $report);

        $changes = [];

        if (array_key_exists('name', $input)) {
            $name = $this->nameFrom($input);

            if (SavedReport::query()->where('user_id', $user->getKey())->where('name', $name)
                ->whereKeyNot($report->getKey())->exists()) {
                throw new InvalidArgumentException(sprintf('Anda sudah punya laporan bernama "%s".', $name));
            }

            $changes['name'] = $name;
        }

        if (array_key_exists('definition', $input) || array_key_exists('resource', $input)) {
            $definition = $this->definitionFrom($input + ['resource' => $report->resource]);

            // Alasan yang sama dengan create(): sunting boleh MEMINDAHKAN
            // laporan ke sumber lain, dan sumber itu harus boleh ia baca.
            $target = ReportableResources::definition($definition['resource']);

            if (! ReportableResources::allows($user, $target)) {
                throw new InvalidArgumentException(sprintf(
                    'Anda tidak memiliki hak akses %s untuk sumber "%s".',
                    implode(' atau ', $target['permission']),
                    $definition['resource'],
                ));
            }

            $changes['definition'] = $definition;
            $changes['resource'] = $definition['resource'];
        }

        if (array_key_exists('shared_roles', $input)) {
            $changes['shared_roles'] = $this->rolesFrom($input);
        }

        $report->update($changes);

        return $report->refresh();
    }

    /** @throws LogicException bila bukan pemiliknya */
    public function delete(User $user, SavedReport $report): void
    {
        $this->assertOwner($user, $report);
        $report->delete();
    }

    /**
     * Salinan milik penyalin: jalan keluar dari "hanya pemilik yang boleh
     * mengubah", dan satu-satunya jalan bagi laporan milik akun yang sudah
     * dinonaktifkan (pengguna tidak pernah dihapus keras di sistem ini, jadi
     * barisnya tidak akan pernah hilang sendiri).
     *
     * Salinan TIDAK mewarisi berbagi: yang menyalin belum tentu ingin
     * membagikan, dan mewarisi diam-diam adalah cara laporan menyebar ke peran
     * yang tidak pernah dipilih siapa pun.
     */
    public function copy(User $user, SavedReport $report, mixed $name = null): SavedReport
    {
        if (! $this->canRead($user, $report)) {
            throw new LogicException('Laporan ini tidak dapat Anda baca, jadi tidak dapat Anda salin.');
        }

        /* `mixed`, bukan `?string`: controller melewatkan `$request->input('name')`
           apa adanya, dan sebuah array di sana adalah TypeError — yang mewarisi
           Error, bukan Exception, jadi lengan catch controller tidak
           menangkapnya dan jawabannya 500 (verifikasi kedua P1-F). Nama yang
           benar-benar dikirim lewat pintu yang sama dengan create/update. */
        $wanted = $name === null || (is_string($name) && trim($name) === '')
            ? $report->name.' (salinan)'
            : self::asName($name);
        $candidate = $wanted;
        $suffix = 2;

        while (SavedReport::query()->where('user_id', $user->getKey())->where('name', $candidate)->exists()) {
            $candidate = $wanted.' '.$suffix;
            $suffix++;
        }

        return SavedReport::query()->create([
            'user_id' => $user->getKey(),
            'name' => $candidate,
            'resource' => $report->resource,
            'definition' => $report->definition,
            'shared_roles' => null,
        ]);
    }

    /**
     * Peran berbagi yang sudah TIDAK ADA lagi — dilaporkan, bukan disembunyikan.
     *
     * @return list<string>
     */
    public function staleRoles(SavedReport $report): array
    {
        $shared = $report->sharedRoles();

        if ($shared === []) {
            return [];
        }

        return array_values(array_diff($shared, $this->liveRoleNames()));
    }

    /* ------------------------------------------------------------- internal */

    /**
     * @throws LogicException
     */
    private function assertOwner(User $user, SavedReport $report): void
    {
        if ($report->user_id === $user->getKey()) {
            return;
        }

        $owner = $report->user?->name ?? 'pengguna lain';

        throw new LogicException(sprintf(
            'Laporan "%s" milik %s; hanya pemiliknya yang dapat mengubah atau menghapusnya. '
            .'Simpan sebagai salinan untuk menyesuaikannya.',
            $report->name,
            $owner,
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function definitionFrom(array $input): array
    {
        $definition = $input['definition'] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException('Definisi laporan belum ada.');
        }

        // `resource` boleh datang di luar definisi (bentuk permintaan) atau di
        // dalamnya (bentuk tersimpan); keduanya divalidasi lewat pintu yang sama.
        if (! isset($definition['resource']) && isset($input['resource'])) {
            $definition['resource'] = $input['resource'];
        }

        return ReportDefinition::validate($definition);
    }

    /** @param array<string, mixed> $input */
    private function nameFrom(array $input): string
    {
        return self::asName($input['name'] ?? null);
    }

    /**
     * Nilai apa pun → nama laporan, atau 422 berkalimat.
     *
     * `(string) $value` atas sebuah ARRAY memicu 'Array to string conversion',
     * yang di Laravel menjadi ErrorException — bukan InvalidArgumentException —
     * jadi ia melewati kedua lengan catch controller dan mendarat sebagai 500.
     * Terukur pada POST/PUT `core/reports/saved` dan `…/copy` dengan
     * `{"name": []}` (verifikasi kedua P1-F), sementara setiap bentuk salah
     * lain di endpoint yang sama menjawab 422 dengan kalimat Indonesia.
     *
     * `is_string` dan bukan `is_scalar`: nama laporan diketik orang, jadi
     * menerima angka hanya menutup efek samping kedua dari cast itu dengan
     * cara yang sama diam-diamnya (`{"name": 12345}` membuat laporan bernama
     * '12345'). Yang bukan teks ditolak dengan namanya.
     */
    private static function asName(mixed $value): string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Nama laporan harus berupa teks.');
        }

        $name = trim((string) ($value ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Laporan harus punya nama.');
        }

        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Nama laporan maksimal 120 karakter.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>|null
     */
    private function rolesFrom(array $input): ?array
    {
        $roles = $input['shared_roles'] ?? null;

        if ($roles === null || $roles === []) {
            return null;
        }

        if (! is_array($roles) || ! array_is_list($roles)) {
            throw new InvalidArgumentException('Daftar peran berbagi harus berupa daftar nama peran.');
        }

        $live = $this->liveRoleNames();
        $out = [];

        foreach ($roles as $role) {
            if (! is_string($role) || $role === '') {
                throw new InvalidArgumentException('Nama peran berbagi harus berupa teks.');
            }

            // Divalidasi terhadap peran yang HIDUP: sebuah berbagi tidak pernah
            // LAHIR basi, jadi satu-satunya cara ia menjadi basi adalah peran
            // itu diganti nama sesudahnya — dan itu terlihat di daftar laporan.
            if (! in_array($role, $live, true)) {
                throw new InvalidArgumentException(sprintf('Peran "%s" tidak ada.', $role));
            }

            if (! in_array($role, $out, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }

    /** @var list<string>|null memo per instans: index memanggilnya sekali per baris */
    private ?array $liveRoles = null;

    /** @return list<string> */
    private function liveRoleNames(): array
    {
        if ($this->liveRoles !== null) {
            return $this->liveRoles;
        }

        // DB::table lewat config, bukan model Role: Sanctum menjadikan 'sanctum'
        // guard bawaan di tengah permintaan sementara seluruh izin hidup di
        // guard 'web', dan relasi Spatie menyelesaikan modelnya dari guard_name.
        return $this->liveRoles = DB::table(config('permission.table_names.roles', 'roles'))
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();
    }
}
