<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\UserPreferences;
use Tests\ErpTestCase;

/**
 * GET/PUT core/me/preferences — preferensi yang mengikuti ORANGNYA (P1-C, T1C.1).
 *
 * Sampai P1-B favorit, "Terakhir dibuka" dan kepadatan hidup di localStorage,
 * jadi bintang yang dipasang di desktop kantor tidak ada di tablet lapangan dan
 * peramban yang dibersihkan menghapus semuanya. Yang diuji di sini adalah tiga
 * hal yang membuat pemindahan itu aman:
 *
 *  - WHITELIST. Kunci di luar UserPreferences::keys() dijawab 422 yang MENYEBUT
 *    kuncinya, bukan disimpan diam-diam: sebuah baris yang tidak pernah dibaca
 *    siapa pun adalah data pengguna yang kita simpan tanpa alasan, dan endpoint
 *    ini tanpa gerbang izin (baris milik pemanggil sendiri) akan menjadi tempat
 *    penyimpanan bebas 16 KB × jumlah kunci × jumlah pengguna.
 *  - PLAFON. 16 KB per nilai, dijawab 422 yang menyebut kunci DAN ukurannya.
 *  - ISOLASI. Tidak ada parameter yang menyebut pengguna: baris yang dibaca dan
 *    ditulis selalu milik $request->user(). Diuji dengan dua akun sungguhan,
 *    bukan dengan membaca kode.
 */
class UserPreferencesTest extends ErpTestCase
{
    private function user(string $email): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengguna '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);

        return $user;
    }

    // ------------------------------------------------------------ round trip

    public function test_put_then_get_round_trips_every_whitelisted_key(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        $values = [
            'favorites' => ['r/projects', 'r/finance/ar-invoices'],
            'recent' => [['route' => 'd/projects/1', 'label' => 'PRJ-001', 'sub' => 'Proyek', 'at' => '2026-09-06T08:00:00Z']],
            'density' => 'compact',
            'dashboard.layout' => [['id' => 'ar-aging', 'w' => 2]],
            'launcher.hidden' => ['svc'],
        ];

        foreach ($values as $key => $value) {
            $this->putJson("/api/core/me/preferences/{$key}", ['value' => $value])
                ->assertOk()
                ->assertJsonPath('data.key', $key)
                ->assertJsonPath('data.value', $value);
        }

        $response = $this->getJson('/api/core/me/preferences')->assertOk();
        $stored = collect($response->json('data'))->pluck('value', 'key')->all();

        // Baris datang urut kunci; yang diuji isinya, bukan urutannya.
        ksort($values);
        ksort($stored);
        $this->assertSame($values, $stored);
        // meta.keys is the whitelist itself, so the SPA never hard-codes it.
        $this->assertSame(array_keys(UserPreferences::keys()), array_column((array) $response->json('meta.keys'), 'key'));
    }

    /**
     * meta membawa plafon yang BENAR-BENAR BERLAKU per kunci, dan prefs.js
     * membacanya. Bentuk lamanya (daftar nama + MAX_BYTES saja) menjanjikan
     * "supaya klien tidak menyalin daftarnya" sambil membiarkan klien menyalin
     * justru angka yang tidak ada di sana: 50 favorit dan 20 entri terakhir
     * ditulis dua kali, dan 16384 yang diumumkan bukan angka yang berlaku
     * untuk kedua kunci itu (4096 dan 8192) — sebuah klien yang mempercayainya
     * membangun muatan yang ditolak server (verifikasi P1-C, 6 Sep 2026).
     */
    public function test_the_index_meta_carries_the_ceilings_that_actually_apply(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        $meta = (array) $this->getJson('/api/core/me/preferences')->assertOk()->json('meta.keys');

        $this->assertSame([
            ['key' => 'favorites', 'label' => 'Favorit', 'max_bytes' => 4096, 'max_entries' => 50],
            ['key' => 'recent', 'label' => 'Terakhir dibuka', 'max_bytes' => 8192, 'max_entries' => 20],
            ['key' => 'density', 'label' => 'Kepadatan', 'max_bytes' => 64, 'max_entries' => null],
            ['key' => 'dashboard.layout', 'label' => 'Susunan dasbor', 'max_bytes' => 16384, 'max_entries' => null],
            ['key' => 'launcher.hidden', 'label' => 'Modul disembunyikan', 'max_bytes' => 512, 'max_entries' => 32],
        ], $meta);

        // …dan angka yang diumumkan adalah angka yang ditegakkan: satu entri
        // lebih banyak daripada max_entries ditolak, dengan angka itu disebut.
        foreach ($meta as $entry) {
            if ($entry['max_entries'] === null) {
                continue;
            }

            $value = array_fill(0, $entry['max_entries'] + 1, $entry['key'] === 'recent' ? ['route' => 'd/projects/1'] : 'r/projects');
            $message = (string) $this->putJson("/api/core/me/preferences/{$entry['key']}", ['value' => $value])
                ->assertStatus(422)->json('errors.value.0');
            $this->assertStringContainsString((string) $entry['max_entries'], $message,
                "Penolakan {$entry['key']} tidak menyebut jumlah maksimum yang diumumkan meta.");
        }
    }

    public function test_a_second_put_replaces_the_row_instead_of_stacking_history(): void
    {
        $user = $this->user('a@test.local');
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/core/me/preferences/density', ['value' => 'compact'])->assertOk();
        $this->putJson('/api/core/me/preferences/density', ['value' => 'comfortable'])->assertOk();

        $this->assertSame(1, DB::table('core_user_preferences')->where('user_id', $user->id)->where('key', 'density')->count());
        $this->getJson('/api/core/me/preferences')->assertOk()->assertJsonPath('data.0.value', 'comfortable');
    }

    public function test_a_key_never_set_is_absent_rather_than_a_made_up_default(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        // Kejujuran: yang belum pernah dipilih orangnya TIDAK ADA, bukan
        // 'normal' yang seolah-olah dipilihnya. Bawaannya milik SPA.
        $this->getJson('/api/core/me/preferences')->assertOk()->assertJsonPath('data', []);
    }

    // -------------------------------------------------------------- refusals

    public function test_an_unknown_key_is_refused_by_name(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        $this->putJson('/api/core/me/preferences/warna-kesukaan', ['value' => 'biru'])
            ->assertStatus(422)
            ->assertJsonPath('errors.key.0', fn ($message) => str_contains((string) $message, 'warna-kesukaan'));

        $this->assertSame(0, DB::table('core_user_preferences')->count());
    }

    /**
     * Plafonnya ANGKA, dan angkanya ditulis di sini — bukan dibaca dari
     * konstanta yang sedang diuji. Sampai verifikasi P1-C (6 Sep 2026) uji
     * kelebihan ukuran membangun muatannya dari UserPreferences::MAX_BYTES dan
     * mencocokkan pesannya dengan konstanta yang sama, jadi kedua sisinya
     * bergerak bersama: menaikkan MAX_BYTES 16384 → 32768 lolos hijau. Satu
     * angka inilah yang membatasi berapa banyak data kendali-pengguna yang
     * dibawa core_user_preferences ke setiap backup dan setiap ekspor.
     */
    public function test_the_ceilings_are_the_numbers_the_documents_promise(): void
    {
        $this->assertSame(16384, UserPreferences::MAX_BYTES);

        $this->assertSame([
            'favorites' => 4096,
            'recent' => 8192,
            'density' => 64,
            'dashboard.layout' => 16384,
            'launcher.hidden' => 512,
        ], array_map(fn (array $entry) => $entry['max_bytes'], UserPreferences::keys()));

        $this->assertSame(['compact', 'normal', 'comfortable'], UserPreferences::DENSITIES);
    }

    public function test_a_value_over_16_kb_is_refused_naming_the_key(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        // Panjangnya dihitung mundur dari 16384 LITERAL: `["x…"]` = isi + 4
        // byte pembungkus. 16384 tepat masuk, 16385 ditolak.
        $atLimit = [str_repeat('x', 16384 - 4)];
        $over = [str_repeat('x', 16385 - 4)];
        $this->assertSame(16384, UserPreferences::encodedBytes($atLimit));
        $this->assertSame(16385, UserPreferences::encodedBytes($over));

        $this->putJson('/api/core/me/preferences/dashboard.layout', ['value' => $atLimit])->assertOk();

        $response = $this->putJson('/api/core/me/preferences/dashboard.layout', ['value' => $over])->assertStatus(422);
        $message = (string) $response->json('errors.value.0');
        $this->assertStringContainsString('dashboard.layout', $message);
        $this->assertStringContainsString('16385', $message, 'Pesannya tidak menyebut ukuran yang dikirim.');
        $this->assertStringContainsString('16384', $message, 'Pesannya tidak menyebut batasnya.');

        // Yang ditolak tidak menyisakan baris; yang tepat 16384 tadi tetap satu.
        $this->assertSame(1, DB::table('core_user_preferences')->count());
    }

    /**
     * Plafon per kunci LEBIH KECIL daripada plafon keras, dan itulah yang
     * berlaku duluan: sebuah daftar favorit 6 KB masih di bawah 16384 dan tetap
     * ditolak — dengan angka kuncinya sendiri yang disebut, bukan 16384.
     */
    public function test_each_key_is_refused_at_its_own_smaller_ceiling(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        $cases = [
            'favorites' => [array_fill(0, 50, str_repeat('r', 120)), 4096],
            'recent' => [array_fill(0, 20, ['route' => str_repeat('d', 200), 'label' => str_repeat('L', 200), 'sub' => str_repeat('s', 200)]), 8192],
            'density' => [str_repeat('c', 100), 64],
            'launcher.hidden' => [array_fill(0, 32, str_repeat('p', 16)), 512],
        ];

        foreach ($cases as $key => [$value, $limit]) {
            $bytes = UserPreferences::encodedBytes($value);
            $this->assertGreaterThan($limit, $bytes);
            $this->assertLessThan(UserPreferences::MAX_BYTES, $bytes,
                "Muatan {$key} melebihi plafon keras juga, jadi uji ini tidak membuktikan plafon kuncinya berlaku.");

            $message = (string) $this->putJson("/api/core/me/preferences/{$key}", ['value' => $value])
                ->assertStatus(422)->json('errors.value.0');
            $this->assertStringContainsString($key, $message);
            $this->assertStringContainsString((string) $limit, $message,
                "Penolakan {$key} tidak menyebut plafon kuncinya ({$limit} byte).");
        }

        $this->assertSame(0, DB::table('core_user_preferences')->count());
    }

    public function test_favorites_refuses_a_route_that_is_not_in_the_sidebar(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        // Bentuknya benar, tetapi tidak ada baris NAV yang menuju ke sana:
        // sebuah bintang pada layar yang tidak ada adalah baris mati yang
        // dibawa-bawa selamanya (SPA memang menyaringnya lagi, tetapi baris
        // yang tidak pernah bisa dipakai tidak perlu disimpan).
        $this->putJson('/api/core/me/preferences/favorites', ['value' => ['r/finance/tidak-ada-layar-ini']])
            ->assertStatus(422)
            ->assertJsonPath('errors.value.0', fn ($message) => str_contains((string) $message, 'r/finance/tidak-ada-layar-ini'));

        // …sementara rute NAV sungguhan lolos, jadi penolakan di atas bukan
        // "menolak segalanya".
        $this->putJson('/api/core/me/preferences/favorites', ['value' => ['r/projects']])->assertOk();
    }

    public function test_favorites_refuses_more_than_fifty_entries(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        $this->putJson('/api/core/me/preferences/favorites', ['value' => array_fill(0, 51, 'r/projects')])
            ->assertStatus(422);
    }

    public function test_density_accepts_only_the_three_profiles(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        foreach (['compact', 'normal', 'comfortable'] as $value) {
            $this->putJson('/api/core/me/preferences/density', ['value' => $value])->assertOk();
        }

        $this->putJson('/api/core/me/preferences/density', ['value' => 'rapat'])->assertStatus(422);
        $this->putJson('/api/core/me/preferences/density', ['value' => ['compact']])->assertStatus(422);
    }

    public function test_recent_refuses_more_than_twenty_entries_and_unknown_fields(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        $entry = ['route' => 'd/projects/1', 'label' => 'PRJ-001', 'sub' => 'Proyek', 'at' => '2026-09-06T08:00:00Z'];

        $this->putJson('/api/core/me/preferences/recent', ['value' => array_fill(0, 21, $entry)])->assertStatus(422);
        $this->putJson('/api/core/me/preferences/recent', ['value' => [$entry + ['token' => 'rahasia']]])->assertStatus(422);
        $this->putJson('/api/core/me/preferences/recent', ['value' => [$entry]])->assertOk();
    }

    public function test_launcher_hidden_accepts_only_nav_prefixes(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        $this->putJson('/api/core/me/preferences/launcher.hidden', ['value' => ['fin', 'prj']])->assertOk();
        $this->putJson('/api/core/me/preferences/launcher.hidden', ['value' => ['bukan-modul']])->assertStatus(422);
    }

    // -------------------------------------------------------------- isolation

    public function test_one_user_can_neither_read_nor_write_another_users_row(): void
    {
        $a = $this->user('a@test.local');
        $b = $this->user('b@test.local');

        $this->actingAs($a, 'sanctum');
        $this->putJson('/api/core/me/preferences/density', ['value' => 'compact'])->assertOk();

        $this->actingAs($b, 'sanctum');
        $this->getJson('/api/core/me/preferences')->assertOk()->assertJsonPath('data', []);
        $this->putJson('/api/core/me/preferences/density', ['value' => 'comfortable'])->assertOk();

        // A's row is untouched — there is no parameter that could have reached it.
        $this->assertSame('"compact"', DB::table('core_user_preferences')->where('user_id', $a->id)->value('value'));
        $this->assertSame('"comfortable"', DB::table('core_user_preferences')->where('user_id', $b->id)->value('value'));

        $this->actingAs($a, 'sanctum');
        $this->getJson('/api/core/me/preferences')->assertOk()->assertJsonPath('data.0.value', 'compact');
    }

    public function test_the_endpoints_require_a_session(): void
    {
        $this->getJson('/api/core/me/preferences')->assertStatus(401);
        $this->putJson('/api/core/me/preferences/density', ['value' => 'compact'])->assertStatus(401);
    }
}
