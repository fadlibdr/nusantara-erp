<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks status untuk tiga hitungan registri ModuleCounts (P1-C).
 *
 * EXPLAIN keempat belas kueri registri di MySQL 8 (verifikasi P1-C putaran 2)
 * menemukan tiga yang memindai seluruh tabel — `type=ALL key=NULL`:
 * `qc_ncr.status`, `hr_leave_requests.status`, dan `eng_drawing_submittals`
 * (decision IS NULL AND superseded_at IS NULL). Sepuluh sisanya sudah memakai
 * `ref`/`range` di atas indeks status yang ada. Pemindaian itu tidak lagi duduk
 * di layar yang jarang dibuka: sejak P1-C ketiganya dijalankan setiap kali
 * launcher `#/home` dibuka — landing ponsel setiap pengguna.
 *
 * Hanya indeks: tidak ada baris yang ditulis, aman untuk data lama di kedua
 * driver. Tabel yang belum ada dilewati (Core tidak boleh menuntut modul fitur
 * terpasang), dan indeks yang sudah ada di deployment lain dilewati juga.
 *
 * Yang TIDAK bisa ditolong indeks: `inv_stock_balances` yang dibandingkan
 * dengan AMBANGNYA — sejak F-6 itu `inv_reorder_rules.reorder_point` bila ada
 * aturan aktif untuk pasangan gudang × item, dan `inv_items.min_stock` bila
 * tidak. Bentuknya berubah; sifatnya tidak: keduanya perbandingan antar KOLOM
 * dua tabel, dan itu tidak bisa dilayani indeks mana pun. Join ke tabel aturan
 * sendiri MEMANG berindeks (UNIQUE warehouse_id+item_id; `eq_ref` di MySQL 8,
 * `SEARCH … USING INDEX` di SQLite). EXPLAIN kedua driver ada di CONVENTIONS
 * § Registri ModuleCounts.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: list<string>, 2: string}> tabel, kolom, nama indeks */
    private const INDEXES = [
        ['qc_ncr', ['status'], 'qc_ncr_status_index'],
        ['hr_leave_requests', ['status'], 'hr_leave_requests_status_index'],
        ['eng_drawing_submittals', ['decision', 'superseded_at'], 'eng_drawing_submittals_decision_superseded_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || $this->hasIndex($table, $name)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue 2;
                }
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, , $name]) {
            if (! Schema::hasTable($table) || ! $this->hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
