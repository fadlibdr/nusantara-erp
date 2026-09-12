<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Support\PhoneNumber;
use Modules\Core\Support\WhatsAppConsent;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * users.phone_e164 + opt-in BERSTEMPEL WAKTU (P-3a, T3a.3, perangkap F).
 *
 *  - E.164 ketat: "+62…", 8–15 digit setelah '+', tanpa spasi/strip — yang
 *    DISIMPAN selalu bentuk itu; "0812-3456-7890" diterima dan menjadi
 *    "+628123456789"; huruf, digit telanjang, terlalu pendek ditolak 422
 *    dengan kalimatnya.
 *  - Opt-in adalah dua kolom (kapan, lewat apa), bukan boolean: PUT
 *    iam/me/phone menstempel via 'profil', PUT iam/users/{id} via 'admin';
 *    stempel yang ada tidak ditulis ulang; ganti nomor mengosongkannya;
 *    opt_in=false mencabut; nomor kosong mencabut.
 *  - Pintu me/phone hanya menyentuh pemanggil; pintu admin butuh iam.update.
 */
class PhoneOptInTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name = 'Budi'): User
    {
        return User::query()->create(['name' => $name, 'email' => str()->random(8).'@nusantara.test', 'password' => bcrypt('password'), 'is_active' => true]);
    }

    private function admin(): User
    {
        $role = Role::findOrCreate('admin-iam', 'web');
        $role->givePermissionTo(['iam.view', 'iam.create', 'iam.update']);
        $admin = $this->user('Admin');
        $admin->assignRole($role);

        return $admin;
    }

    // ------------------------------------------------------------- E.164

    public function test_normalisation_is_strict_and_stores_one_shape(): void
    {
        $this->assertSame('+628123456789', PhoneNumber::normalize('0812-3456-789'));
        $this->assertSame('+628123456789', PhoneNumber::normalize(' 0812 3456 789 '));
        $this->assertSame('+628123456789', PhoneNumber::normalize('628123456789'));
        $this->assertSame('+628123456789', PhoneNumber::normalize('+62 (812) 3456.789'));
        $this->assertSame('+628123456789', PhoneNumber::normalize('00628123456789'));
        $this->assertSame('+14155552671', PhoneNumber::normalize('+1 415 555 2671'));

        foreach (['', '   ', '8123456789', '+0812', '+62abc123456', '+621234', '+6212345678901234567', 'nol delapan', '+62-'] as $bad) {
            $this->assertNull(PhoneNumber::normalize($bad), "\"{$bad}\" harus ditolak.");
        }

        $this->assertSame('628123456789', PhoneNumber::digits('+628123456789'));
        $this->assertSame(1, preg_match(PhoneNumber::PATTERN, '+628123456789'));
        $this->assertSame(0, preg_match(PhoneNumber::PATTERN, '+62 812'));
    }

    // ------------------------------------------------------- PUT me/phone

    public function test_the_owner_sets_a_number_and_the_opt_in_is_stamped_via_profil(): void
    {
        Carbon::setTestNow('2026-09-11 10:00:00');
        $user = $this->user();
        $this->actingAs($user, 'sanctum');

        $response = $this->putJson('/api/iam/me/phone', ['phone_e164' => '0812-3456-789', 'whatsapp_opt_in' => true])->assertOk();

        $this->assertSame('+628123456789', $response->json('data.phone_e164'));
        $this->assertTrue($response->json('data.whatsapp_opt_in'));
        $this->assertSame('profil', $response->json('data.whatsapp_opt_in_via'));
        $this->assertStringContainsString('opt-in tercatat 11 Sep 2026 10:00 WIB', $response->json('message'));

        $user->refresh();
        $this->assertSame('+628123456789', $user->phone_e164);
        $this->assertSame('2026-09-11 10:00:00', $user->whatsapp_opt_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('profil', $user->whatsapp_opt_in_via);

        // auth/me membawa ketiganya.
        $me = $this->getJson('/api/iam/auth/me')->assertOk()->json('data');
        $this->assertSame('+628123456789', $me['phone_e164']);
        $this->assertNotNull($me['whatsapp_opt_in_at']);
    }

    public function test_an_existing_stamp_is_kept_a_new_number_clears_it_and_false_revokes(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum');

        Carbon::setTestNow('2026-09-11 10:00:00');
        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628123456789', 'whatsapp_opt_in' => true])->assertOk();

        // Simpan lagi nomor yang sama sehari kemudian: tanggal persetujuan adalah fakta, tidak ditulis ulang.
        Carbon::setTestNow('2026-09-12 10:00:00');
        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628123456789', 'whatsapp_opt_in' => true])->assertOk();
        $this->assertSame('2026-09-11 10:00:00', $user->refresh()->whatsapp_opt_in_at->format('Y-m-d H:i:s'));

        // Nomor berganti tanpa menyatakan opt-in lagi: persetujuan lama tidak ikut.
        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628999999999', 'whatsapp_opt_in' => false])->assertOk();
        $user->refresh();
        $this->assertSame('+628999999999', $user->phone_e164);
        $this->assertNull($user->whatsapp_opt_in_at);
        $this->assertNull($user->whatsapp_opt_in_via);

        // Nomor berganti DAN opt-in dinyatakan: stempel baru untuk nomor baru.
        Carbon::setTestNow('2026-09-13 08:00:00');
        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628111111111', 'whatsapp_opt_in' => true])->assertOk();
        $this->assertSame('2026-09-13 08:00:00', $user->refresh()->whatsapp_opt_in_at->format('Y-m-d H:i:s'));

        // Cabut.
        $response = $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628111111111', 'whatsapp_opt_in' => false])->assertOk();
        $this->assertNull($user->refresh()->whatsapp_opt_in_at);
        $this->assertStringContainsString('tanpa opt-in', $response->json('message'));

        // Hapus nomor: apa pun opt_in, tidak ada persetujuan tanpa nomor.
        $response = $this->putJson('/api/iam/me/phone', ['phone_e164' => null, 'whatsapp_opt_in' => true])->assertOk();
        $user->refresh();
        $this->assertNull($user->phone_e164);
        $this->assertNull($user->whatsapp_opt_in_at);
        $this->assertStringContainsString('Nomor WhatsApp dihapus', $response->json('message'));
    }

    /**
     * Verifikasi P-3a (12 Sep 2026, F1/F2/V5): ganti nomor SAMBIL menyatakan
     * opt-in lagi → stempel HILANG di kedua pintu (`??` menelan null yang
     * baru saja diset untuk nomor berganti, lalu membaca stempel lama sebagai
     * "sudah ada"), padahal migrasi 000252 dan layar Profil menjanjikan
     * "kecuali opt-in dinyatakan lagi pada saat yang sama". Dan aturan "ganti
     * nomor mengosongkan stempel" sendiri tidak dijaga satu uji pun: setiap
     * langkah yang mengganti nomor juga mengirim opt_in=false.
     *
     * Dipaku dengan JAM yang berbeda: stempel nomor baru harus bertanggal saat
     * nomor baru disetujui, bukan tanggal persetujuan nomor lama.
     */
    public function test_a_new_number_declared_with_opt_in_gets_a_fresh_stamp_at_both_doors_and_without_it_the_old_stamp_goes(): void
    {
        // ---- pintu Profil (via 'profil')
        $user = $this->user();
        $this->actingAs($user, 'sanctum');
        Carbon::setTestNow('2026-09-11 10:00:00');
        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628123456789', 'whatsapp_opt_in' => true])->assertOk();
        $this->assertSame('2026-09-11 10:00:00', $user->refresh()->whatsapp_opt_in_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-09-13 08:00:00');
        $response = $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628999999999', 'whatsapp_opt_in' => true])->assertOk();
        $user->refresh();
        $this->assertSame('+628999999999', $user->phone_e164);
        $this->assertSame('2026-09-13 08:00:00', $user->whatsapp_opt_in_at?->format('Y-m-d H:i:s'), 'Stempel BARU untuk nomor baru — bukan hilang, bukan tanggal nomor lama.');
        $this->assertSame('profil', $user->whatsapp_opt_in_via);
        $this->assertTrue($response->json('data.whatsapp_opt_in'));
        $this->assertSame('Nomor +628999999999 disimpan dengan opt-in tercatat 13 Sep 2026 08:00 WIB.', $response->json('message'));

        // ---- pintu administrator (via 'admin')
        $admin = $this->admin();
        $target = $this->user('Sari');
        $this->actingAs($admin, 'sanctum');
        Carbon::setTestNow('2026-09-11 11:00:00');
        $this->putJson("/api/iam/users/{$target->id}", ['phone_e164' => '+6281300001111', 'whatsapp_opt_in' => true])->assertOk();
        $this->assertSame('2026-09-11 11:00:00', $target->refresh()->whatsapp_opt_in_at->format('Y-m-d H:i:s'));

        // Formulir generik: nomor dibetulkan dengan kotak "Opt-in WhatsApp tercatat" tetap tercentang.
        Carbon::setTestNow('2026-09-13 09:00:00');
        $response = $this->putJson("/api/iam/users/{$target->id}", ['phone_e164' => '+6281300002222', 'whatsapp_opt_in' => true])->assertOk();
        $target->refresh();
        $this->assertSame('+6281300002222', $target->phone_e164);
        $this->assertSame('2026-09-13 09:00:00', $target->whatsapp_opt_in_at?->format('Y-m-d H:i:s'));
        $this->assertSame('admin', $target->whatsapp_opt_in_via);
        $this->assertTrue($response->json('data.whatsapp_opt_in'));

        // Nomor berganti TANPA sikap opt-in (kunci tidak dikirim): persetujuan nomor lama tidak ikut.
        Carbon::setTestNow('2026-09-14 09:00:00');
        $response = $this->putJson("/api/iam/users/{$target->id}", ['phone_e164' => '+6281300003333'])->assertOk();
        $target->refresh();
        $this->assertSame('+6281300003333', $target->phone_e164);
        $this->assertNull($target->whatsapp_opt_in_at, 'Persetujuan melekat pada nomor: nomor baru tanpa pernyataan = tanpa stempel.');
        $this->assertNull($target->whatsapp_opt_in_via);
        $this->assertFalse($response->json('data.whatsapp_opt_in'));

        // Sunting NAMA saja sesudah itu: nomor tetap, tetap tanpa stempel — dan nomor yang sama
        // dengan opt-in dinyatakan lagi mendapat stempel hari itu.
        $this->putJson("/api/iam/users/{$target->id}", ['name' => 'Sari Dewi'])->assertOk();
        $this->assertNull($target->refresh()->whatsapp_opt_in_at);
        $this->putJson("/api/iam/users/{$target->id}", ['whatsapp_opt_in' => true])->assertOk();
        $this->assertSame('2026-09-14 09:00:00', $target->refresh()->whatsapp_opt_in_at?->format('Y-m-d H:i:s'));
    }

    public function test_bad_numbers_and_a_missing_stance_are_refused_in_indonesian(): void
    {
        $this->actingAs($this->user(), 'sanctum');

        $response = $this->putJson('/api/iam/me/phone', ['phone_e164' => '8123456789', 'whatsapp_opt_in' => true])->assertStatus(422);
        $this->assertSame(PhoneNumber::MESSAGE, $response->json('errors.phone_e164.0'));

        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+62 812', 'whatsapp_opt_in' => true])->assertStatus(422);
        $this->putJson('/api/iam/me/phone', ['phone_e164' => 'nol delapan', 'whatsapp_opt_in' => false])->assertStatus(422);

        $response = $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628123456789'])->assertStatus(422);
        $this->assertStringContainsString('Sikap opt-in WhatsApp harus dinyatakan', $response->json('errors.whatsapp_opt_in.0'));

        $this->putJson('/api/iam/me/phone', ['whatsapp_opt_in' => true])->assertStatus(422)
            ->assertJsonFragment(['phone_e164' => ['Nomor WhatsApp harus dikirim (boleh kosong untuk menghapusnya).']]);

        $this->assertNull(User::query()->sole()->phone_e164);
    }

    public function test_me_phone_touches_only_the_caller_and_needs_a_session(): void
    {
        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628123456789', 'whatsapp_opt_in' => true])->assertUnauthorized();

        $me = $this->user('Saya');
        $other = $this->user('Orang Lain');
        $this->actingAs($me, 'sanctum');
        $this->putJson('/api/iam/me/phone', ['phone_e164' => '+628123456789', 'whatsapp_opt_in' => true, 'user_id' => $other->id, 'id' => $other->id])->assertOk();

        $this->assertSame('+628123456789', $me->refresh()->phone_e164);
        $this->assertNull($other->refresh()->phone_e164);
    }

    // ---------------------------------------------- Sistem › Pengguna (admin)

    public function test_an_administrator_records_a_number_and_consent_given_outside_the_app_via_admin(): void
    {
        Carbon::setTestNow('2026-09-11 11:00:00');
        $admin = $this->admin();
        $target = $this->user('Sari');
        $this->actingAs($admin, 'sanctum');

        $response = $this->putJson("/api/iam/users/{$target->id}", ['phone_e164' => '0813 0000 1111', 'whatsapp_opt_in' => true])->assertOk();

        $this->assertSame('+6281300001111', $response->json('data.phone_e164'));
        $this->assertSame('admin', $response->json('data.whatsapp_opt_in_via'));
        $this->assertSame('2026-09-11 11:00:00', $target->refresh()->whatsapp_opt_in_at->format('Y-m-d H:i:s'));

        // Sunting nama saja: nomor dan stempel tidak disentuh.
        $this->putJson("/api/iam/users/{$target->id}", ['name' => 'Sari Dewi'])->assertOk();
        $target->refresh();
        $this->assertSame('+6281300001111', $target->phone_e164);
        $this->assertSame('2026-09-11 11:00:00', $target->whatsapp_opt_in_at->format('Y-m-d H:i:s'));

        // Formulir generik mengirim opt-in yang sama lagi: stempel tetap.
        $this->putJson("/api/iam/users/{$target->id}", ['whatsapp_opt_in' => true])->assertOk();
        $this->assertSame('2026-09-11 11:00:00', $target->refresh()->whatsapp_opt_in_at->format('Y-m-d H:i:s'));

        // Cabut dari sisi admin.
        $this->putJson("/api/iam/users/{$target->id}", ['whatsapp_opt_in' => false])->assertOk();
        $this->assertNull($target->refresh()->whatsapp_opt_in_at);

        // Nomor salah ditolak.
        $this->putJson("/api/iam/users/{$target->id}", ['phone_e164' => '12345'])->assertStatus(422)
            ->assertJsonFragment(['phone_e164' => [PhoneNumber::MESSAGE]]);

        // Pengguna baru dengan nomor + opt-in.
        $created = $this->postJson('/api/iam/users', [
            'name' => 'Baru', 'email' => 'baru@nusantara.test', 'password' => 'rahasia-123',
            'phone_e164' => '+628222222222', 'whatsapp_opt_in' => true,
        ])->assertCreated();
        $this->assertSame('+628222222222', $created->json('data.phone_e164'));
        $this->assertSame('admin', $created->json('data.whatsapp_opt_in_via'));
    }

    public function test_the_admin_door_needs_iam_update_and_the_consent_helper_refuses_unknown_channels(): void
    {
        $target = $this->user('Sari');
        $this->actingAs($this->user('Bukan admin'), 'sanctum');
        $this->putJson("/api/iam/users/{$target->id}", ['phone_e164' => '+628123456789', 'whatsapp_opt_in' => true])->assertForbidden();
        $this->assertNull($target->refresh()->phone_e164);

        $this->expectException(\InvalidArgumentException::class);
        WhatsAppConsent::apply($target, '+628123456789', true, 'telepon');
    }
}
