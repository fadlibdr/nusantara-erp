<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `crm_leads.user_id` → `crm_leads.owner_user_id` (F-3 / T3.4).
 *
 * ROADMAP F-3 meminta "owner_user_id pada prospek, tanpa backfill". Kolom itu
 * SUDAH ADA sejak migrasi 000310 dengan nama yang lebih miskin: `user_id`,
 * berkomentar "Owner (sales/estimator)", dengan relasi Lead::owner() di atasnya
 * dan saringan `?user_id=` di daftarnya. Yang ada hanyalah namanya yang tidak
 * mengatakan apa-apa — di sebuah baris yang juga punya created_by dan
 * assigned_to di modul-modul tetangga, "user_id" bisa berarti tiga hal.
 *
 * KENAPA DIGANTI NAMA, BUKAN DITAMBAH KOLOM KEDUA. Menambahkan `owner_user_id`
 * di samping `user_id` menyisakan DUA kolom pemilik pada satu baris, dan
 * "tanpa backfill" akan berarti: setiap prospek yang HARI INI punya pemilik
 * membaca "Belum ditugaskan" di layar sementara basis datanya menyimpan
 * pemiliknya di kolom sebelah. Terukur pada salinan basis data produksi
 * (8 Sep 2026): 2 prospek, KEDUANYA ber-user_id — jadi kolom kedua akan
 * mengosongkan 2 dari 2 pemilik yang sudah tercatat. Itu persis "berubah makna
 * diam-diam" yang dilarang paket ini.
 *
 * "TANPA BACKFILL" TETAP DIPATUHI, dalam artinya yang sebenarnya: tidak ada
 * satu prospek pun yang MENDAPAT pemilik yang tidak pernah dituliskan
 * seseorang. Ganti nama memindahkan nilai yang sudah ada apa adanya —
 * termasuk yang NULL, yang tetap NULL dan berbunyi "Belum ditugaskan".
 *
 * Indeksnya ikut diganti (drop + create, bukan renameIndex): nama indeks yang
 * masih menyebut kolom yang tidak ada lagi adalah petunjuk palsu untuk pembaca
 * berikutnya, dan dua pernyataan sederhana berlaku sama di SQLite dan MySQL.
 *
 * down() mengembalikan namanya — ini murni perubahan bentuk, tidak ada data
 * yang hilang di arah mana pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_leads', 'user_id') || Schema::hasColumn('crm_leads', 'owner_user_id')) {
            return;
        }

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'owner_user_id');
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('crm_leads', 'owner_user_id') || Schema::hasColumn('crm_leads', 'user_id')) {
            return;
        }

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex(['owner_user_id']);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->renameColumn('owner_user_id', 'user_id');
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->index('user_id');
        });
    }
};
