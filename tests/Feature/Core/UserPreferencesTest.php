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
        $this->assertSame(array_keys(UserPreferences::keys()), $response->json('meta.keys'));
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

    public function test_a_value_over_16_kb_is_refused_naming_the_key(): void
    {
        $this->actingAs($this->user('a@test.local'), 'sanctum');

        // 16 KB of JSON inside one reserved-for-P1-D layout array.
        $big = [str_repeat('x', UserPreferences::MAX_BYTES + 1)];

        $response = $this->putJson('/api/core/me/preferences/dashboard.layout', ['value' => $big])->assertStatus(422);

        $this->assertStringContainsString('dashboard.layout', (string) $response->json('errors.value.0'));
        $this->assertStringContainsString((string) UserPreferences::MAX_BYTES, (string) $response->json('errors.value.0'));
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
