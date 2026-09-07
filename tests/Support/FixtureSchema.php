<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel percobaan milik tes (test_documents, document_format_probe) yang dibuat
 * di dalam sebuah tes — dan MENGAPA di MySQL ia dibuat lewat koneksi kedua.
 *
 * RefreshDatabase membuka satu transaksi per tes dan menggulung balik di
 * akhir. Di SQLite, DDL ikut transaksi: CREATE TABLE di setUp lenyap bersama
 * rollback, tes berikutnya membuatnya lagi. Di MySQL, setiap CREATE/DROP TABLE
 * melakukan COMMIT IMPLISIT — transaksi tes berakhir diam-diam, baris yang
 * ditulis sesudahnya tinggal permanen, dan RefreshDatabase (yang melihat PDO
 * tidak lagi dalam transaksi) menjadwalkan migrate:fresh (~27 detik, diukur
 * di erp_test 5 Sep 2026) sebelum tes berikutnya. Tiga kelas Unit memanggil
 * createTestDocumentTable() di setUp — 43 tes × 27 detik hanya untuk membuat
 * tabel yang sama.
 *
 * Maka di driver selain sqlite, DDL dikirim lewat koneksi terpisah dengan
 * konfigurasi yang sama (autocommit, tanpa transaksi tes), lalu koneksi itu
 * ditutup. Transaksi tes di koneksi utama tidak tersentuh; baris yang ditulis
 * tes ke tabel itu tetap tergulung balik. Tabelnya sendiri DIBIARKAN: DROP
 * dari koneksi kedua akan menunggu metadata lock yang dipegang transaksi tes
 * sampai ia berakhir (lock_wait_timeout bawaan satu tahun) — dan migrate:fresh
 * pada proses berikutnya membuangnya bersama tabel lain.
 */
final class FixtureSchema
{
    private const SIDE_CONNECTION = 'fixture_ddl';

    /**
     * Membuat tabel bila belum ada (SQLite: langsung, di dalam transaksi tes).
     */
    public static function create(string $table, Closure $blueprint): void
    {
        if (self::isTransactionalDdl()) {
            Schema::create($table, $blueprint);

            return;
        }

        self::onSideConnection(function ($schema) use ($table, $blueprint): void {
            if (! $schema->hasTable($table)) {
                $schema->create($table, $blueprint);
            }
        });
    }

    /**
     * Membuang lalu membuat ulang — untuk tabel yang bentuknya boleh berbeda
     * antar tes. Aman dipanggil di awal tes: transaksi tes belum menyentuh
     * tabelnya, jadi tidak ada metadata lock yang ditunggu.
     */
    public static function recreate(string $table, Closure $blueprint): void
    {
        if (self::isTransactionalDdl()) {
            Schema::dropIfExists($table);
            Schema::create($table, $blueprint);

            return;
        }

        self::onSideConnection(function ($schema) use ($table, $blueprint): void {
            $schema->dropIfExists($table);
            $schema->create($table, $blueprint);
        });
    }

    /**
     * Membuang tabel di akhir tes — hanya di SQLite. Di MySQL tabel dibiarkan
     * (lihat di atas); recreate() atau migrate:fresh yang membereskannya.
     */
    public static function dropAtEnd(string $table): void
    {
        if (self::isTransactionalDdl()) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Menjalankan $body dengan $table SEOLAH BELUM TERPASANG, lalu
     * mengembalikannya — untuk menguji jalur "tabel modulnya belum ada".
     *
     * `Schema::drop()` langsung adalah jebakan yang sama dengan yang dijelaskan
     * di kepala berkas ini, dari arah sebaliknya. Di SQLite drop itu ikut
     * transaksi tes dan lenyap saat rollback, jadi tidak ada yang terlihat. Di
     * MySQL ia COMMIT IMPLISIT: tabelnya hilang UNTUK SETERUSNYA di proses itu,
     * dan tes-tes berikutnya menabraknya. Diukur pada gerbang rilis
     * 0937dec (7 Sep 2026): `Schema::drop('ast_assets')` di satu tes ekspor
     * menjatuhkan 22 tes Finance sesudahnya — seluruh gugus PeriodClose gagal
     * dengan "penyusutan bulan ini belum ada", sebuah peringatan tutup buku
     * yang membaca ast_assets — sementara tes yang menjatuhkannya sendiri hijau,
     * dan hijau juga bila dijalankan sendirian. Suite SQLite tidak pernah
     * memperlihatkannya.
     *
     * Ganti nama, bukan hapus: barisnya ikut pindah dan kembali utuh, jadi tes
     * berikutnya menemukan tabel DAN datanya seperti semula.
     */
    public static function withMissingTable(string $table, Closure $body): void
    {
        $parked = $table.'__parked';

        Schema::rename($table, $parked);

        try {
            $body();
        } finally {
            Schema::rename($parked, $table);
        }
    }

    public static function isTransactionalDdl(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private static function onSideConnection(Closure $work): void
    {
        $default = (string) config('database.default');
        config(['database.connections.'.self::SIDE_CONNECTION => config('database.connections.'.$default)]);

        try {
            $work(Schema::connection(self::SIDE_CONNECTION));
        } finally {
            DB::purge(self::SIDE_CONNECTION);
        }
    }
}
