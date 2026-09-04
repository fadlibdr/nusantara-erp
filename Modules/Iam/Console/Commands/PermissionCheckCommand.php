<?php

namespace Modules\Iam\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Penyimpangan izin — basis data hidup dibandingkan dengan niat seeder.
 *
 * Diukur di produksi 4 Sep 2026 (HASIL-UJI §6.2 P-1): peran `admin` erp1
 * memegang 74 dari 86 izin. eng.* dan qc.* ditambahkan ke
 * PermissionSeeder::PREFIXES untuk P1-ENG dan P1-QC, kodenya ter-deploy,
 * tetapi tidak ada yang menjalankan ulang seeder terhadap basis data hidup —
 * dua paket utuh tidak terjangkau siapa pun, dan tidak satu pun tes atau
 * langkah deploy menyadarinya. Perintah ini yang menyadarinya: dijalankan
 * deploy/sync-erp1.sh setelah migrate (dan sendirian lewat --check), keluar
 * bukan-nol begitu ada selisih.
 *
 * Dua lapis, keduanya diturunkan, bukan dihitung tangan: (1) daftar izin
 * PermissionSeeder::expected() — PREFIXES × ACTIONS + DIRECTOR_APPROVALS —
 * lawan tabel permissions; (2) per peran, RoleSeeder::intended() lawan izin
 * yang benar-benar dipegang peran itu. Peran yang tidak dikenal seeder
 * (dibuat lewat Sistem › Peran & Hak Akses) dilaporkan, tidak dinilai:
 * RoleSeeder tidak pernah menyentuhnya, jadi tidak ada niat untuk
 * dibandingkan.
 */
class PermissionCheckCommand extends Command
{
    protected $signature = 'erp:permission-check {--json : Keluaran JSON untuk skrip}';

    protected $description = 'Bandingkan izin dan peran di basis data dengan PermissionSeeder/RoleSeeder; keluar 1 bila menyimpang';

    private const GUARD = 'web';

    public function handle(): int
    {
        $table = config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) {
            $this->error("Tabel {$table} belum ada — jalankan migrate dulu.");

            return self::FAILURE;
        }

        // Cache Spatie bisa lebih tua dari tabelnya (migrasi baru saja
        // memberi grant); baca dari tabel, bukan dari cache.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $report = $this->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['drift'] ? self::FAILURE : self::SUCCESS;
        }

        $this->render($report);

        return $report['drift'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{
     *   expected: int, in_database: int, missing: list<string>, extra: list<string>,
     *   roles: array<string, array{exists: bool, expected: int, held: int, missing: list<string>, extra: list<string>}>,
     *   unmanaged_roles: list<string>, drifted_roles: list<string>, drift: bool
     * }
     */
    private function report(): array
    {
        $expected = PermissionSeeder::expected();
        $inDatabase = Permission::query()
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $roles = [];
        $drifted = [];
        foreach (RoleSeeder::intended() as $name => $intended) {
            $role = Role::query()->where('name', $name)->where('guard_name', self::GUARD)->first();
            $held = $role ? $role->permissions()->pluck('name')->all() : [];
            $missing = array_values(array_diff($intended, $held));
            $extra = array_values(array_diff($held, $intended));

            $roles[$name] = [
                'exists' => $role !== null,
                'expected' => count($intended),
                'held' => count($held),
                'missing' => $missing,
                'extra' => $extra,
            ];
            if ($role === null || $missing || $extra) {
                $drifted[] = $name;
            }
        }

        $unmanaged = Role::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', array_keys($roles))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $missingPermissions = array_values(array_diff($expected, $inDatabase));
        $extraPermissions = array_values(array_diff($inDatabase, $expected));

        return [
            'expected' => count($expected),
            'in_database' => count($inDatabase),
            'missing' => $missingPermissions,
            'extra' => $extraPermissions,
            'roles' => $roles,
            'unmanaged_roles' => $unmanaged,
            'drifted_roles' => $drifted,
            'drift' => $missingPermissions !== [] || $extraPermissions !== [] || $drifted !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function render(array $r): void
    {
        $this->line(sprintf(
            'Izin: %d diharapkan (%d awalan × %d aksi + %d persetujuan direktur), %d di basis data.',
            $r['expected'],
            count(PermissionSeeder::PREFIXES),
            count(PermissionSeeder::ACTIONS),
            count(PermissionSeeder::DIRECTOR_APPROVALS),
            $r['in_database'],
        ));
        if ($r['missing']) {
            $this->warn('  Kurang di basis data ('.count($r['missing']).'): '.implode(', ', $r['missing']));
        }
        if ($r['extra']) {
            $this->warn('  Tidak dikenal seeder ('.count($r['extra']).'): '.implode(', ', $r['extra']));
        }

        $rows = [];
        foreach ($r['roles'] as $name => $role) {
            $rows[] = [
                $name,
                $role['expected'],
                $role['exists'] ? $role['held'] : '—',
                $role['exists'] ? count($role['missing']) : '—',
                $role['exists'] ? count($role['extra']) : '—',
                $role['exists']
                    ? (($role['missing'] || $role['extra']) ? 'menyimpang' : 'sesuai')
                    : 'peran tidak ada',
            ];
        }
        $this->table(['Peran', 'Diharapkan', 'Dipegang', 'Kurang', 'Lebih', 'Keadaan'], $rows);

        foreach ($r['roles'] as $name => $role) {
            if (! $role['exists']) {
                $this->warn("  {$name}: peran tidak ada di basis data.");

                continue;
            }
            if ($role['missing']) {
                $this->warn("  {$name} kurang: ".implode(', ', $role['missing']));
            }
            if ($role['extra']) {
                $this->warn("  {$name} lebih: ".implode(', ', $role['extra']));
            }
        }

        if ($r['unmanaged_roles']) {
            $this->line('Peran di luar seeder, tidak diperiksa ('.count($r['unmanaged_roles']).'): '.implode(', ', $r['unmanaged_roles']));
        }

        if (! $r['drift']) {
            $this->info(sprintf(
                'Tidak ada penyimpangan izin: %d izin dan %d peran sesuai seeder.',
                $r['in_database'],
                count($r['roles']),
            ));

            return;
        }

        $this->error(sprintf(
            'PENYIMPANGAN IZIN: %d izin kurang, %d izin tidak dikenal, %d peran menyimpang%s.',
            count($r['missing']),
            count($r['extra']),
            count($r['drifted_roles']),
            $r['drifted_roles'] ? ' ('.implode(', ', $r['drifted_roles']).')' : '',
        ));
        // Perbaikannya idempoten (findOrCreate + syncPermissions) tetapi
        // menimpa suntingan manual pada peran bawaan — RECAP T1.1. Garis
        // miring terbalik ganda disengaja: bentuk yang bisa ditempel ke bash
        // apa adanya (bash memakan `\I` menjadi `I`), persis seperti RECAP.
        $this->line('Pulihkan dengan: php artisan db:seed --class=Modules\\\\Iam\\\\Database\\\\Seeders\\\\PermissionSeeder'
            .' && php artisan db:seed --class=Modules\\\\Iam\\\\Database\\\\Seeders\\\\RoleSeeder');
        $this->line('Perintah itu menimpa suntingan manual pada peran bawaan; periksa Sistem › Peran & Hak Akses dulu.');
    }
}
