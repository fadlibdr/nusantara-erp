<?php

namespace Tests\Feature\Core;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\NumberSequence;
use Modules\Core\Models\Setting;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Support\BuktiPotongNumber;
use Tests\ErpTestCase;

/**
 * Nomor PERTAMA sebuah bucket penghitung baru — tahun baru, masa pajak baru,
 * scope {PROJ} baru — diminta oleh dua permintaan sekaligus.
 *
 * Diukur harness burst T0.4 (tests/harness/burst.py) di MySQL 8.0.46, 5 Sep
 * 2026: empat persetujuan tagihan ber-PPh paralel pada masa 2026-09 yang
 * belum punya baris BP-202609 → satu BP-202609-0001, tiga jawaban 500
 * "1062 Duplicate entry 'BP-202609-2026-'". firstOrCreate() membaca (tidak
 * ada), menyisipkan (menunggu commit yang pertama, lalu 1062), membaca ulang
 * lewat read view REPEATABLE READ yang sama (tetap tidak ada) dan melempar.
 * Di SQLite jalur ini tidak pernah terlihat: kunci berkas menyerialkan
 * seluruh transaksi.
 *
 * NumberSequence::lockBucket kini menyatukannya dalam satu INSERT ... ON
 * DUPLICATE KEY UPDATE (SQLite: ON CONFLICT DO UPDATE) diikuti bacaan
 * pengunci: permintaan kedua MENUNGGU kunci baris permintaan pertama, bukan
 * gagal — dibuktikan di sini lewat koneksi kedua dengan lock wait 1 detik yang
 * harus jatuh pada 1205 (menunggu, lalu habis waktu), dan bukan pada 1062.
 */
class NumberSequenceBucketRaceTest extends ErpTestCase
{
    private const WAITER = 'bucket_waiter';

    protected function tearDown(): void
    {
        DB::setDefaultConnection((string) config('database.default'));
        DB::purge(self::WAITER);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_first_number_of_a_new_bucket_creates_the_row_and_counts_from_one(): void
    {
        Carbon::setTestNow('2027-01-05 09:00:00'); // tidak ada seed yang punya bucket 2027
        $numbers = app(DocumentNumberService::class);

        $this->assertSame('PO/2027/I/0001', $numbers->next('PO'));
        $this->assertSame('PO/2027/I/0002', $numbers->next('PO'));

        $bucket = NumberSequence::query()->where(['type' => 'PO', 'year' => 2027, 'scope' => ''])->sole();
        $this->assertSame(2, (int) $bucket->last_number);

        // Bukti potong memakai helper yang sama dengan tipe per masa.
        $this->assertSame('BP-202701-0001', BuktiPotongNumber::allocate(2027, 1));
        $this->assertSame('BP-202701-0002', BuktiPotongNumber::allocate(2027, 1));
        $this->assertSame('BP-202702-0001', BuktiPotongNumber::allocate(2027, 2));
    }

    public function test_a_scoped_bucket_is_created_the_same_way(): void
    {
        Carbon::setTestNow('2027-03-02 08:00:00');
        app(SettingService::class)->flush();
        Setting::query()->updateOrCreate(['key' => 'documents.PO'], ['value' => 'PO/{PROJ}/{Y}/{N4}']);
        app(SettingService::class)->flush();

        $numbers = app(DocumentNumberService::class);

        $this->assertSame('PO/PRJ-2027-001/2027/0001', $numbers->next('PO', 'PRJ-2027-001'));
        $this->assertSame('PO/PRJ-2027-002/2027/0001', $numbers->next('PO', 'PRJ-2027-002'));
        $this->assertSame('PO/PRJ-2027-001/2027/0002', $numbers->next('PO', 'PRJ-2027-001'));
    }

    public function test_on_mysql_the_second_request_for_a_new_bucket_waits_for_the_first_instead_of_failing(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Kunci baris tidak ada di SQLite; balapan ini hanya terjadi di MySQL (phpunit.mysql.xml).');
        }

        Carbon::setTestNow('2027-01-05 09:00:00');

        // Permintaan 1: di dalam transaksi tes — bucket baru lahir dan
        // terkunci, belum commit.
        $this->assertSame('PO/2027/I/0001', app(DocumentNumberService::class)->next('PO'));

        // Permintaan 2: koneksi kedua, lock wait 1 detik, LAYANAN YANG SAMA
        // (model memakai koneksi bawaan, jadi bawaannya dialihkan sebentar).
        config(['database.connections.'.self::WAITER => config('database.connections.mysql')]);
        DB::connection(self::WAITER)->statement('set session innodb_lock_wait_timeout = 1');
        DB::setDefaultConnection(self::WAITER);

        $started = microtime(true);

        try {
            app(DocumentNumberService::class)->next('PO');
            $this->fail('Permintaan kedua mendapat nomor tanpa menunggu permintaan pertama commit.');
        } catch (UniqueConstraintViolationException $e) {
            $this->fail('Balapan firstOrCreate lama: 1062 dilempar ke pemanggil, bukan menunggu. '.$e->getMessage());
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1], $e->getMessage()); // Lock wait timeout exceeded
        } finally {
            DB::setDefaultConnection('mysql');
        }

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started, 'Gagal seketika — tidak ada kunci yang ditunggu.');
    }
}
