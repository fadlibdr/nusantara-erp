<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Attachment;
use Modules\Core\Services\AttachmentService;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pintu tulis masa berlaku lampiran (F-8) dan empat keadaannya.
 *
 * Tiga hal yang dijaga file ini, semuanya karena cacat yang berulang di
 * kampanye ini adalah "aturan ditegakkan di SATU permukaan dan bocor di
 * permukaan lain yang sama":
 *
 *  - KEDUA TRANSPORT UNGGAH menerima tanggalnya (JSON base64 dan multipart).
 *    Satu pintu yang menerimanya dan satu yang membuangnya diam-diam adalah
 *    berkas 25 MB yang kehilangan masa berlakunya tanpa satu pesan pun.
 *  - PINTU UBAH memakai izin yang SAMA dengan mengubah lampirannya, dan
 *    penolakannya tidak bisa dibedakan dari "tidak ada" — jalur reachable()
 *    yang sama dengan destroy(), termasuk penjaga induk yang hilang.
 *  - HARI TERAKHIR MASIH BERLAKU. "Berlaku s/d" pada tanggal hari ini adalah
 *    MENIPIS, bukan KEDALUWARSA — bacaan yang sama dengan pengawas tenggat
 *    (valid_through_end), VendorDocument::isExpired dan Guarantee::isExpired.
 */
class AttachmentValidUntilTest extends ErpTestCase
{
    use FinanceFixtures;

    private const TODAY = '2026-08-01';

    private AttachmentService $attachments;

    private ApBill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
        Storage::fake('local');
        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->attachments = app(AttachmentService::class);
        $this->bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'description' => 'Polis CAR',
            'dpp' => 10_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-F8',
        ]);
    }

    private function pdf(): string
    {
        return base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna',
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function attachment(?string $validUntil): Attachment
    {
        return $this->attachments->store($this->bill, 'polis.pdf', $this->pdf(), null, null, [], $validUntil);
    }

    // ---------------------------------------------------------- pintu unggah

    public function test_the_json_upload_route_stores_the_expiry_it_was_given(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $response = $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'polis-car.pdf',
            'content' => $this->pdf(),
            'valid_until' => '2026-12-31',
        ])->assertCreated();

        $this->assertSame('2026-12-31', Attachment::query()->latest('id')->first()->valid_until->toDateString());
        $this->assertSame(Attachment::VALIDITY_OK, $response->json('data.validity.state'));
    }

    /**
     * Transport kedua, kebijakan yang sama. Sebuah gambar kerja 25 MB hanya
     * bisa naik lewat rute ini (base64 tidak muat), dan sebuah aturan yang
     * hanya berlaku di rute JSON berarti berkas terbesar di sistem adalah
     * satu-satunya yang tidak bisa membawa masa berlakunya.
     */
    public function test_the_multipart_upload_route_stores_it_too(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $this->actingAs($clerk)->post('/api/core/attachments/upload', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'file' => UploadedFile::fake()->createWithContent('polis-car.pdf', base64_decode($this->pdf())),
            'valid_until' => '2027-06-30',
        ])->assertCreated()
            ->assertJsonPath('data.valid_until', '2027-06-30');

        $this->assertSame('2027-06-30', Attachment::query()->latest('id')->first()->valid_until->toDateString());
    }

    /** Keadaan normal: tidak menyebut tanggal sama sekali menyimpan NULL. */
    public function test_an_upload_without_an_expiry_stores_null_and_reads_as_the_normal_state(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $response = $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'foto-lapangan.pdf',
            'content' => $this->pdf(),
        ])->assertCreated();

        $this->assertNull(Attachment::query()->latest('id')->first()->valid_until);
        $this->assertSame(Attachment::VALIDITY_NONE, $response->json('data.validity.state'));
        $this->assertNull($response->json('data.validity.days'));
    }

    public function test_a_nonsense_expiry_is_refused_by_validation_not_stored(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'polis.pdf',
            'content' => $this->pdf(),
            'valid_until' => '31-02-2026',
        ])->assertStatus(422)->assertJsonValidationErrors('valid_until');

        $this->assertSame(0, Attachment::query()->count());
    }

    // ------------------------------------------------------------ pintu ubah

    public function test_the_owning_modules_update_permission_may_change_it_afterwards(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attachment(null);

        $this->actingAs($clerk)
            ->patchJson("/api/core/attachments/{$attachment->id}", ['valid_until' => '2026-08-20'])
            ->assertOk()
            ->assertJsonPath('data.validity.state', Attachment::VALIDITY_NEAR)
            ->assertJsonPath('data.validity.days', 19);

        $this->assertSame('2026-08-20', $attachment->fresh()->valid_until->toDateString());
    }

    public function test_an_expiry_can_be_cleared_back_to_the_normal_state(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attachment('2026-08-20');

        $this->actingAs($clerk)
            ->patchJson("/api/core/attachments/{$attachment->id}", ['valid_until' => null])
            ->assertOk()
            ->assertJsonPath('data.validity.state', Attachment::VALIDITY_NONE);

        $this->assertNull($attachment->fresh()->valid_until);
    }

    /**
     * Mengosongkan harus DIKATAKAN. Sebuah PATCH yang lupa menyebut kuncinya
     * bukan permintaan "kosongkan"; menebaknya begitu menghapus tanggal orang
     * lain tanpa satu klik pun yang memintanya.
     */
    public function test_a_patch_that_never_mentions_the_key_is_refused_instead_of_clearing_it(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attachment('2026-08-20');

        $this->actingAs($clerk)
            ->patchJson("/api/core/attachments/{$attachment->id}", ['caption' => 'diganti'])
            ->assertStatus(422)->assertJsonValidationErrors('valid_until');

        $this->assertSame('2026-08-20', $attachment->fresh()->valid_until->toDateString());
    }

    /**
     * Membaca lampiran menuntut fin.view; MENGUBAH masa berlakunya menuntut
     * fin.update, izin yang sama dengan menghapusnya. Penolakannya 404 dan
     * bukan 403, jalur reachable() yang sama dengan destroy(): 403 yang
     * menyebut modulnya mengubah pasangan endpoint ini menjadi pencacah
     * seluruh lampiran di sistem.
     */
    public function test_a_viewer_of_the_module_may_not_change_it(): void
    {
        $viewer = $this->userWith(['fin.view']);
        $attachment = $this->attachment('2026-08-20');

        $this->actingAs($viewer)
            ->patchJson("/api/core/attachments/{$attachment->id}", ['valid_until' => '2030-01-01'])
            ->assertStatus(404);

        $this->assertSame('2026-08-20', $attachment->fresh()->valid_until->toDateString());
    }

    /** Izin update MODUL LAIN bukan izin atas lampiran ini. */
    public function test_another_modules_update_permission_may_not_change_it(): void
    {
        $foreman = $this->userWith(['prj.view', 'prj.update']);
        $attachment = $this->attachment('2026-08-20');

        $this->actingAs($foreman)
            ->patchJson("/api/core/attachments/{$attachment->id}", ['valid_until' => '2030-01-01'])
            ->assertStatus(404);

        $this->assertSame('2026-08-20', $attachment->fresh()->valid_until->toDateString());
    }

    /** Induk yang benar-benar hilang: penjaga reachable() berlaku di sini juga. */
    public function test_an_attachment_whose_document_is_gone_cannot_have_its_expiry_edited(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attachment('2026-08-20');

        $this->bill->forceDelete();

        $this->actingAs($clerk)
            ->patchJson("/api/core/attachments/{$attachment->id}", ['valid_until' => '2030-01-01'])
            ->assertStatus(404);

        $this->assertSame('2026-08-20', $attachment->fresh()->valid_until->toDateString());
    }

    // ------------------------------------------------------- empat keadaannya

    /**
     * Angka-angka DIPAKU, bukan dibaca dari konstanta yang diujinya: sebuah uji
     * yang menyusun harapannya dari VALID_UNTIL_LEAD_DAYS akan hijau untuk
     * nilai APA PUN, dan dua mutasi lolos hijau di F-6 justru karena itu.
     * 30 hari adalah jendela peringatan yang dijanjikan kartu lampiran dan
     * entri WatchedDeadlines; kalau angkanya berubah, uji ini harus merah.
     */
    public function test_the_thirtieth_day_before_the_date_is_the_first_warning_day(): void
    {
        $this->assertSame(Attachment::VALIDITY_OK, $this->attachment('2026-09-01')->validityState()); // 31 hari lagi
        $this->assertSame(Attachment::VALIDITY_NEAR, $this->attachment('2026-08-31')->validityState()); // 30 hari lagi
        $this->assertSame(30, Attachment::VALID_UNTIL_LEAD_DAYS);
    }

    public function test_the_last_valid_day_is_still_valid_not_expired(): void
    {
        $today = $this->attachment(self::TODAY);

        $this->assertFalse($today->isExpired());
        $this->assertSame(Attachment::VALIDITY_NEAR, $today->validityState());
        $this->assertSame(0, $today->daysUntilExpiry());
    }

    public function test_the_day_after_is_expired(): void
    {
        $yesterday = $this->attachment('2026-07-31');

        $this->assertTrue($yesterday->isExpired());
        $this->assertSame(Attachment::VALIDITY_EXPIRED, $yesterday->validityState());
        $this->assertSame(-1, $yesterday->daysUntilExpiry());
    }

    public function test_no_expiry_is_its_own_state_and_never_reads_as_expired(): void
    {
        $plain = $this->attachment(null);

        $this->assertFalse($plain->isExpired());
        $this->assertSame(Attachment::VALIDITY_NONE, $plain->validityState());
        $this->assertNull($plain->daysUntilExpiry());
    }

    /** Setiap lampiran yang diserialisasi membawa keadaannya — satu aturan, di server. */
    public function test_the_listing_endpoint_carries_the_state_of_every_row(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $this->attachment(null);
        $this->attachment('2026-07-31');

        $states = $this->actingAs($clerk)->getJson(
            '/api/core/attachments?document_type=finance/ap-bills&document_id='.$this->bill->id
        )->assertOk()->json('data.*.validity.state');

        sort($states);
        $this->assertSame([Attachment::VALIDITY_EXPIRED, Attachment::VALIDITY_NONE], $states);
    }
}
