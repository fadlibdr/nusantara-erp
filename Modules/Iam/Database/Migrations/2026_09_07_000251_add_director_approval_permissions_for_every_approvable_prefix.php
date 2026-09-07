<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SATU IZIN PERSETUJUAN DIREKTUR PER AWALAN YANG PUNYA DOKUMEN (F-1).
 *
 * Sampai sekarang hanya prc dan scm memilikinya, karena hanya PO dan SPK yang
 * punya ambang. Matriks persetujuan mengubah itu: pemilik dapat memasang
 * ambang pada baris mana pun dari dua puluh delapan, dan sebuah ambang yang
 * menuntut izin yang tidak pernah dicetak menghasilkan dokumen yang tidak
 * dapat disetujui SIAPA PUN — kegagalan yang tampak sebagai penolakan yang
 * membingungkan, bukan sebagai konfigurasi yang salah.
 *
 * Delapan izin baru (diukur dari registri 7 Sep 2026): crm, est, prj, eng, qc,
 * inv, fin, hr. Empat awalan tanpa dokumen ber-approve (core, iam, ast, svc)
 * sengaja tidak mendapatkannya — sebuah izin yang tidak diperiksa apa pun
 * terbaca sebagai kendali yang ada.
 *
 * DIBERIKAN KEPADA direktur DAN admin, dengan alasan yang sama dengan migrasi
 * 000240: penyeedan admin adalah "setiap izin di sistem", dan seorang admin
 * yang tiba-tiba tidak bisa menyetujui sesuatu terbaca sebagai regresi, bukan
 * sebagai kendali.
 *
 * TIDAK MENGUBAH SATU PUN KEPUTUSAN HARI INI. Kedelapan izin baru diperiksa
 * hanya ketika sebuah baris matriks membawa ambang, dan kedelapan barisnya
 * dikirim tanpa ambang. Ia menyala pada baris yang pemiliknya isi.
 *
 * Menyebut PermissionSeeder::directorApprovals() dengan sengaja — BERBEDA
 * dengan migrasi 000240, yang dibekukan pada dua nama. Perbedaannya nyata:
 * 000240 adalah catatan tentang dua izin tertentu; migrasi INI adalah aturan
 * "satu per awalan berdokumen", dan aturan itulah yang harus dijalankannya
 * pada basis data yang sudah hidup. up()-nya idempoten dan down()-nya menahan
 * diri (lihat di bawah), jadi daftar yang tumbuh tidak dapat merusaknya.
 */
return new class extends Migration
{
    /** Sudah dibuat migrasi 000240; migrasi ini tidak mengklaimnya. */
    private const ALREADY_SHIPPED = ['prc.approve-director', 'scm.approve-director'];

    public function up(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->newNames() as $name) {
            $permission = Permission::findOrCreate($name, 'web');

            Role::where('name', 'direktur')->where('guard_name', 'web')->first()
                ?->givePermissionTo($permission);
            Role::where('name', 'admin')->where('guard_name', 'web')->first()
                ?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * down() MENCABUT DARI PERAN, TIDAK MENGHAPUS BARIS IZINNYA.
     *
     * Menghapus baris izin akan melepasnya dari setiap peran DAN setiap
     * pengguna — termasuk peran khusus yang dibuat instalasi ini sendiri, yang
     * tidak ada hubungannya dengan migrasi ini dan tidak akan pernah kembali
     * bila migrasi dijalankan maju lagi. Yang dibatalkan di sini hanyalah yang
     * diberikan di sini.
     */
    public function down(): void
    {
        if (! $this->rolesAreSeeded()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->newNames() as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();

            if ($permission === null) {
                continue;
            }

            Role::where('name', 'direktur')->where('guard_name', 'web')->first()?->revokePermissionTo($permission);
            Role::where('name', 'admin')->where('guard_name', 'web')->first()?->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function newNames(): array
    {
        return array_values(array_diff(PermissionSeeder::directorApprovals(), self::ALREADY_SHIPPED));
    }

    /**
     * Pada instalasi yang belum punya peran sama sekali, seeder membuat semua
     * ini dalam bentuk akhirnya; tidak ada yang perlu diretrofit.
     */
    private function rolesAreSeeded(): bool
    {
        $roles = config('permission.table_names.roles', 'roles');

        return Schema::hasTable($roles)
            && Role::where('name', 'direktur')->where('guard_name', 'web')->exists();
    }
};
