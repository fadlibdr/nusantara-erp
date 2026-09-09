<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ErpTestCase;

/**
 * Bentuk `core_attachments.valid_until` (F-8, migrasi 001800).
 *
 * Dua hal dijaga di sini, dan keduanya sudah pernah dibayar mahal di repo ini:
 *
 *  1. KOLOMNYA NULLABLE. NULL adalah keadaan NORMAL sebuah lampiran, bukan
 *     "lupa diisi" — hampir semua baris tabel ini adalah foto lapangan yang
 *     tidak pernah punya masa berlaku. Sebuah NOT NULL di sini akan memaksa
 *     setiap unggahan mengarang tanggal.
 *  2. INDEKSNYA PASANGAN (attachable_type, valid_until), bukan (valid_until).
 *     Perencana SQLite tanpa statistik memilih indeks kesetaraan yang sudah
 *     ada dan mengabaikan indeks satu kolom `valid_until` sepenuhnya —
 *     terukur 9 Sep 2026 atas 40.000 baris: 14,689 ms vs 0,024 ms. Nama
 *     indeksnya dipaku di sini supaya "perbaikan" yang menyederhanakannya
 *     menjadi satu kolom jatuh sebagai uji merah, bukan sebagai layar Tenggat
 *     yang pelan-pelan melambat tanpa ada yang tahu sebabnya.
 */
class AttachmentValidUntilSchemaTest extends ErpTestCase
{
    public function test_valid_until_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('core_attachments', 'valid_until'));

        $column = collect(Schema::getColumns('core_attachments'))->firstWhere('name', 'valid_until');

        $this->assertNotNull($column, 'core_attachments kehilangan kolom valid_until.');
        $this->assertTrue(
            $column['nullable'],
            'valid_until wajib nullable: "tanpa masa berlaku" adalah keadaan normal sebuah lampiran, '
            .'dan NOT NULL memaksa setiap foto lapangan mengarang tanggal.',
        );

        // Maju-saja: tidak ada baris lama yang berubah arti.
        $this->assertNull($column['default'], 'valid_until tidak boleh punya nilai bawaan.');
    }

    public function test_the_watcher_column_is_indexed_with_attachable_type_first(): void
    {
        $indexes = collect(Schema::getIndexes('core_attachments'))
            ->map(static fn (array $index): array => array_map('strval', $index['columns']))
            ->values()
            ->all();

        $this->assertContains(
            ['attachable_type', 'valid_until'],
            $indexes,
            'Indeks (attachable_type, valid_until) hilang. Pemindai tenggat menanyakan '
            .'attachable_type IN (…) AND valid_until <rentang> per modul; tanpa pasangan berawalan '
            .'attachable_type, SQLite memakai indeks (attachable_type, attachable_id) lalu menyaring '
            .'tanggal baris demi baris atas puluhan ribu foto lapangan.',
        );
    }

    /**
     * Bukti bahwa indeksnya BENAR-BENAR dipakai oleh kueri yang dituju, bukan
     * hanya ada. Diambil dari rencana kueri SQLite, driver yang dipakai suite
     * ini — pada MySQL bentuknya diukur terpisah (CONVENTIONS §37).
     */
    public function test_sqlite_plans_the_watcher_query_through_that_index(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Rencana kueri ini spesifik SQLite; angka MySQL ada di CONVENTIONS §37.');
        }

        $plan = collect(DB::select(
            'EXPLAIN QUERY PLAN SELECT count(*) FROM core_attachments '
            .'WHERE attachable_type IN (?, ?) AND valid_until IS NOT NULL AND valid_until < ?',
            ['Modules\Projects\Models\DailyReport', 'Modules\Projects\Models\Defect', '2026-08-02'],
        ))->pluck('detail')->implode(' | ');

        $this->assertStringContainsString('core_attachments_attachable_type_valid_until_index', $plan,
            "Rencana kueri tidak memakai indeks pasangan: {$plan}");
        $this->assertStringNotContainsString('attachable_type_attachable_id_index', $plan,
            "Perencana jatuh kembali ke indeks (attachable_type, attachable_id): {$plan}");
    }
}
