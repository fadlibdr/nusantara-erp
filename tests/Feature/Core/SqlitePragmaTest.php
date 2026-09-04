<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\ErpTestCase;

/**
 * PRAGMA SQLite yang dikirim saat konek: busy_timeout, journal_mode, synchronous.
 *
 * Diukur 2 Sep 2026 (HASIL-UJI §6.4): /up di erp1 menjawab 503 dua kali dalam
 * tiga hari, yang kedua sekitar dua menit setelah harness mengirim ~40
 * permintaan (28 berurutan + 12 paralel per_page=500) — permintaan
 * menggantung "pending" dan tidak pernah dijawab. Hipotesis yang paling cocok:
 * SQLite bare-metal + php-fpm dengan sedikit worker; permintaan yang menunggu
 * kunci SQLite menghabiskan worker, nginx menjawab 503. Tiga kunci di
 * config/database.php — busy_timeout 5000, journal_mode WAL, synchronous
 * NORMAL — sudah ada sejak commit awal (3b933f1), dan SQLiteConnector Laravel
 * mengirimnya sebagai PRAGMA pada setiap konek. Yang belum ada sampai 4 Sep
 * 2026 adalah buktinya: tidak satu pun tes membacanya balik, sehingga kunci
 * yang terhapus, atau DB_JOURNAL_MODE=null di .env, tidak disadari siapa pun.
 *
 * MENGAPA PROBE BERKAS, BUKAN KONEKSI TES. phpunit.xml memasang
 * DB_DATABASE=:memory:, dan basis data :memory: tidak pernah bisa WAL:
 * `pragma journal_mode = WAL` dijawab "memory", tanpa galat. Asersi
 * journal_mode pada koneksi bawaan lulus kosong (bila asersinya "bukan
 * delete") atau merah karena alasan yang salah (bila asersinya "wal"). Maka
 * konfigurasi sqlite yang sama disalin ke koneksi kedua yang menunjuk berkas
 * sementara, dikonek lewat connector Laravel yang sama, lalu dibaca balik.
 * busy_timeout dan synchronous berlaku per koneksi dan bisa dibuktikan pada
 * :memory: juga — dibuktikan pada keduanya.
 *
 * Diukur di sini 4 Sep 2026 (PHP 8.3.6, SQLite 3.45.1): koneksi PDO yang
 * TIDAK menerima pragma melapor busy_timeout 60000 (PDO::ATTR_TIMEOUT bawaan
 * pdo_sqlite, 60 detik), journal_mode delete pada berkas baru, synchronous 2
 * (FULL). Jadi 5000 adalah plafon lama worker php-fpm menunggu kunci, bukan
 * beda antara menunggu dan gagal seketika — relevan persis untuk 503 di atas:
 * worker yang menunggu 60 detik habis jauh lebih cepat daripada yang
 * menunggu 5.
 */
class SqlitePragmaTest extends ErpTestCase
{
    private const PROBE = 'pragma_probe';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        // tempnam membuat berkas kosong 0 byte; SQLite membukanya sebagai basis
        // data baru. sys_get_temp_dir() menghormati TMPDIR.
        $this->file = tempnam(sys_get_temp_dir(), 'pragma_probe_');

        // Salinan konfigurasi sqlite yang sebenarnya — hanya jalur berkasnya yang
        // diganti — supaya yang diuji adalah kunci di config/database.php, bukan
        // kunci yang ditulis ulang di sini.
        config(['database.connections.'.self::PROBE => array_merge(
            config('database.connections.sqlite'),
            ['database' => $this->file],
        )]);
    }

    protected function tearDown(): void
    {
        // Tutup dulu: koneksi terakhir yang ditutup melakukan checkpoint dan
        // menghapus -wal/-shm sendiri; unlink sesudahnya membereskan sisa bila
        // tesnya mati di tengah.
        DB::purge(self::PROBE);

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file.$suffix);
        }

        parent::tearDown();
    }

    public function test_a_file_backed_connection_receives_busy_timeout_wal_and_synchronous_normal(): void
    {
        $pdo = DB::connection(self::PROBE)->getPdo();

        $this->assertSame(5000, (int) $this->pragma($pdo, 'busy_timeout'));
        $this->assertSame('wal', strtolower((string) $this->pragma($pdo, 'journal_mode')));
        // 1 = NORMAL (0 OFF, 2 FULL, 3 EXTRA).
        $this->assertSame(1, (int) $this->pragma($pdo, 'synchronous'));
    }

    public function test_the_in_memory_test_connection_gets_the_same_pragmas_but_can_never_be_wal(): void
    {
        $pdo = DB::connection()->getPdo();

        $this->assertSame(5000, (int) $this->pragma($pdo, 'busy_timeout'));
        $this->assertSame(1, (int) $this->pragma($pdo, 'synchronous'));

        // Inilah alasan probe berkas di atas ada: konektor mengirim
        // `pragma journal_mode = WAL` ke koneksi ini juga, dan SQLite menjawab
        // "memory" — bukan galat, bukan "wal".
        $this->assertSame('memory', strtolower((string) $this->pragma($pdo, 'journal_mode')));
    }

    /**
     * Implikasi WAL untuk cadangan, dibuktikan, bukan diceritakan: baris yang
     * sudah commit tinggal di berkas -wal sampai checkpoint (otomatis tiap 1000
     * halaman, atau saat koneksi terakhir ditutup). cp/rsync berkas utama saja
     * tidak memuatnya; VACUUM INTO lewat koneksi hidup — yang dipakai
     * deploy/backup-erp1.sh — membaca lewat WAL dan memuatnya.
     */
    public function test_committed_rows_live_in_the_wal_file_until_checkpoint_so_a_copy_of_the_main_file_is_not_a_backup(): void
    {
        $probe = DB::connection(self::PROBE);
        $probe->statement('create table bukti (id integer primary key, nomor text not null)');
        $probe->insert('insert into bukti (nomor) values (?)', ['PO/2026/IX/0004']);

        $this->assertFileExists($this->file.'-wal');
        $this->assertFileExists($this->file.'-shm');

        $copy = $this->file.'-copy';
        copy($this->file, $copy);
        $plain = new PDO('sqlite:'.$copy);
        $this->assertSame(
            0,
            (int) $plain->query("select count(*) from sqlite_master where name = 'bukti'")->fetchColumn(),
            'Salinan berkas utama saja memuat tabel yang baru ada di -wal — checkpoint terjadi lebih awal dari yang diasumsikan.',
        );
        $plain = null;
        $this->unlinkWithSideFiles($copy);

        $snapshot = $this->file.'-snapshot';
        $probe->statement('vacuum into '.$probe->getPdo()->quote($snapshot));
        $check = new PDO('sqlite:'.$snapshot);
        $this->assertSame('PO/2026/IX/0004', $check->query('select nomor from bukti')->fetchColumn());
        $check = null;
        $this->unlinkWithSideFiles($snapshot);
    }

    private function pragma(PDO $pdo, string $name): mixed
    {
        return $pdo->query('pragma '.$name)->fetchColumn();
    }

    private function unlinkWithSideFiles(string $path): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($path.$suffix);
        }
    }
}
