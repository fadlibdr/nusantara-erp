<?php

namespace Tests\Feature\Crm;

use Illuminate\Support\Facades\DB;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\ActivityService;
use Modules\Crm\Services\LeadFollowUpService;
use Tests\ErpTestCase;

/**
 * `next_follow_up_at` DITURUNKAN dari aktivitas terbuka (F-3 / T3.3).
 *
 * Dua janji yang dipaku di sini, dan yang kedua adalah alasan migrasi 000396
 * ada sama sekali:
 *
 *  1. TURUNANNYA BENAR: due_at terawal di antara aktivitas yang belum selesai,
 *     bergerak saat aktivitas dibuat, ditandai selesai, dibuka kembali,
 *     dihapus, atau digeser tanggalnya — dan kosong (bukan tanggal basi)
 *     ketika tidak ada lagi yang terbuka.
 *  2. TIDAK ADA PROSPEK YANG BERUBAH MAKNA. Setiap tanggal yang sudah diketik
 *     seseorang SEBELUM paket ini tetap berbunyi sama sesudahnya: migrasinya
 *     memindahkan tanggal itu menjadi satu aktivitas terbuka, jadi turunannya
 *     sama persis dengan angka yang sudah tertulis di layar.
 */
class LeadFollowUpDerivationTest extends ErpTestCase
{
    private function service(): ActivityService
    {
        return app(ActivityService::class);
    }

    private function makeLead(array $attributes = []): Lead
    {
        return Lead::query()->create(array_merge([
            'name' => 'Rudi Hartanto',
            'company_name' => 'PT Bangun Sejahtera',
            'status' => LeadStatus::Contacted,
        ], $attributes));
    }

    // ------------------------------------------------------------- turunannya

    public function test_the_follow_up_date_is_the_earliest_open_activity(): void
    {
        $lead = $this->makeLead();

        $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Telepon lanjutan', 'due_at' => '2026-09-20',
        ]);
        $this->assertSame('2026-09-20', $lead->refresh()->next_follow_up_at?->toDateString());

        // Aktivitas yang lebih awal menarik tanggalnya maju.
        $earlier = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'meeting', 'subject' => 'Rapat teknis', 'due_at' => '2026-09-12',
        ]);
        $this->assertSame('2026-09-12', $lead->refresh()->next_follow_up_at?->toDateString());

        // Yang selesai tidak lagi dihitung — tanggalnya mundur ke yang berikutnya.
        $this->service()->markDone($earlier);
        $this->assertSame('2026-09-20', $lead->refresh()->next_follow_up_at?->toDateString());

        // …dan membuka kembali mengembalikannya.
        $this->service()->reopen($earlier);
        $this->assertSame('2026-09-12', $lead->refresh()->next_follow_up_at?->toDateString());
    }

    /** Tidak ada aktivitas terbuka bertanggal = kosong, bukan tanggal basi. */
    public function test_the_date_empties_when_nothing_open_is_left(): void
    {
        $lead = $this->makeLead();
        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Satu-satunya rencana', 'due_at' => '2026-09-20',
        ]);

        $this->service()->delete($activity);

        $this->assertNull($lead->refresh()->next_follow_up_at);
    }

    /** Aktivitas tanpa tanggal tidak pernah menjadi tanggal tindak lanjut. */
    public function test_an_undated_activity_never_becomes_the_follow_up_date(): void
    {
        $lead = $this->makeLead();

        $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'note', 'subject' => 'Catatan hasil obrolan',
        ]);

        $this->assertNull($lead->refresh()->next_follow_up_at);
    }

    /**
     * Aktivitas PENAWARAN milik prospek yang sama tidak menggeser tanggalnya.
     *
     * Aturan, bukan kelalaian: tanggal di baris prospek harus bisa dijelaskan
     * oleh sesuatu yang terlihat di layar prospek itu.
     */
    public function test_a_quotation_activity_does_not_move_the_lead_date(): void
    {
        $lead = $this->makeLead();

        $customer = Customer::query()->create(['name' => 'PT Uji', 'status' => 'active']);
        $quotation = Quotation::query()->create([
            'customer_id' => $customer->id, 'lead_id' => $lead->id,
            'title' => 'Penawaran uji', 'scope_type' => 'system_integration',
        ]);

        $this->service()->create([
            'document_type' => 'quotation', 'document_id' => $quotation->id,
            'type' => 'call', 'subject' => 'Telepon soal penawaran', 'due_at' => '2026-09-01',
        ]);

        $this->assertNull($lead->refresh()->next_follow_up_at);
    }

    /** Memindahkan aktivitas ke prospek lain menghitung ulang KEDUANYA. */
    public function test_moving_an_activity_recomputes_both_leads(): void
    {
        $from = $this->makeLead();
        $to = $this->makeLead(['name' => 'Bambang Setiawan']);

        $activity = $this->service()->create([
            'document_type' => 'lead', 'document_id' => $from->id,
            'type' => 'call', 'subject' => 'Telepon yang salah tempel', 'due_at' => '2026-09-18',
        ]);

        $this->service()->update($activity, ['document_id' => $to->id]);

        $this->assertNull($from->refresh()->next_follow_up_at, 'prospek asal harus ikut dihitung ulang');
        $this->assertSame('2026-09-18', $to->refresh()->next_follow_up_at?->toDateString());
    }

    // ------------------------------------------------- ketikan lama, dan migrasinya

    /** Mengetiknya lewat API ditolak, dengan kalimat yang menyebut jalannya. */
    public function test_typing_the_date_is_refused_naming_the_activity_route(): void
    {
        $lead = $this->makeLead();
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->putJson("/api/crm/leads/{$lead->id}", ['next_follow_up_at' => '2026-08-20'])
            ->assertStatus(422);

        $this->assertStringContainsString('diturunkan dari aktivitas', $response->json('errors.next_follow_up_at.0'));
        $this->assertStringContainsString('Aktivitas', $response->json('errors.next_follow_up_at.0'));

        $this->actingAs($admin)
            ->postJson('/api/crm/leads', ['name' => 'Prospek Baru', 'next_follow_up_at' => '2026-08-20'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('next_follow_up_at');
    }

    /**
     * NULL EKSPLISIT ADALAH KETIKAN JUGA — dan yang paling merusak.
     *
     * Diukur 8 Sep 2026 sebelum perbaikan ini (php -S atas salinan DB demo):
     * PUT {"next_follow_up_at":null} dijawab HTTP 200, kolomnya jadi kosong,
     * aktivitas terbukanya masih di sana, dan SATU halaman memajang dua
     * jawaban — kartu Aktivitas "Tindak lanjut berikutnya 25 Sep 2026" di atas
     * panel Informasi "—". `prohibited` adalah kebalikan `required`, jadi ia
     * lulus untuk nilai kosong; `missing` yang menolak KEBERADAAN kuncinya.
     *
     * Tiga bentuk kosong diuji karena ketiganya sampai sebagai hal yang sama:
     * null, "" (ConvertEmptyStringsToNull), dan status null yang dulu menjadi
     * HTTP 500 "NOT NULL constraint failed" alih-alih kalimat "Ubah Tahap".
     */
    public function test_an_explicit_null_cannot_wipe_the_derived_date(): void
    {
        $lead = $this->makeLead();
        $admin = $this->adminUser();

        $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Telepon terjadwal', 'due_at' => '2026-09-25',
        ]);

        $this->assertSame('2026-09-25', $lead->refresh()->next_follow_up_at?->toDateString());

        foreach ([null, ''] as $empty) {
            $this->actingAs($admin)
                ->putJson("/api/crm/leads/{$lead->id}", ['name' => $lead->name, 'next_follow_up_at' => $empty])
                ->assertStatus(422)
                ->assertJsonValidationErrors('next_follow_up_at');

            $this->assertSame('2026-09-25', $lead->refresh()->next_follow_up_at?->toDateString(),
                'kolom turunan terhapus oleh nilai kosong yang lolos validasi');
        }

        // Tahap dijaga pintu yang sama: null bukan "biarkan saja", ia dulu
        // menulis NULL ke kolom NOT NULL.
        $this->actingAs($admin)
            ->putJson("/api/crm/leads/{$lead->id}", ['name' => $lead->name, 'status' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(LeadStatus::Contacted, $lead->refresh()->status, 'tahapnya bergeser oleh null');

        // Prospek BARU: status kosong berarti "tahap awal", bukan HTTP 500.
        $this->actingAs($admin)
            ->postJson('/api/crm/leads', ['name' => 'Prospek Tanpa Tahap', 'status' => null])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'new');
    }

    /**
     * Saringan kedua di controller (Arr::except) — supaya aturannya tidak
     * bergantung pada satu rule validasi yang bisa salah pilih lagi. Dipanggil
     * sebagaimana controller memanggilnya: lewat HTTP, dengan kunci yang lolos
     * karena FormRequest-nya dilucuti tidak mungkin di sini — maka yang diuji
     * adalah PERILAKUNYA: nilai turunan tidak pernah berpindah lewat formulir.
     */
    public function test_the_form_route_never_writes_the_derived_column(): void
    {
        $lead = $this->makeLead();
        $admin = $this->adminUser();

        $this->service()->create([
            'document_type' => 'lead', 'document_id' => $lead->id,
            'type' => 'call', 'subject' => 'Telepon terjadwal', 'due_at' => '2026-10-02',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/crm/leads/{$lead->id}", ['name' => 'Nama Baru', 'company_name' => 'PT Uji'])
            ->assertOk()
            ->assertJsonPath('data.next_follow_up_at', '2026-10-02');

        $this->assertSame('2026-10-02', $lead->refresh()->next_follow_up_at?->toDateString());
    }

    /**
     * Prospek yang sudah punya tanggal ketikan TIDAK berubah makna.
     *
     * Barisnya ditulis mentah lewat DB::table — keadaan sebuah pemasangan yang
     * sudah berjalan pada menit sebelum deploy F-3, sebelum satu aktivitas pun
     * ada. Sesudah migrasinya: angka yang sama, kini punya baris yang bisa
     * ditunjuk orang.
     */
    public function test_dates_typed_before_this_package_survive_as_activities(): void
    {
        $ids = [];
        foreach ([['LEAD-9001', '2026-08-20'], ['LEAD-9002', '2026-12-01'], ['LEAD-9003', null]] as [$code, $date]) {
            $ids[$code] = DB::table('crm_leads')->insertGetId([
                'code' => $code, 'name' => "Prospek {$code}", 'status' => 'contacted',
                'estimated_value' => 0, 'next_follow_up_at' => $date,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $before = DB::table('crm_leads')->pluck('next_follow_up_at', 'code');

        $this->migration()->up();

        $derivation = app(LeadFollowUpService::class);

        foreach ($before as $code => $typed) {
            $expected = $typed === null ? null : substr((string) $typed, 0, 10);

            $this->assertSame($expected, $derivation->derive($ids[$code]),
                "prospek {$code} berubah makna: yang tertulis {$typed}, yang diturunkan berbeda");
        }

        // Satu aktivitas per tanggal yang pernah diketik — bukan satu per prospek.
        $this->assertSame(2, Activity::query()->count());

        $activity = Activity::query()->where('document_id', $ids['LEAD-9001'])->firstOrFail();
        $this->assertSame('2026-08-20', $activity->due_at?->toDateString());
        $this->assertNull($activity->owner_user_id, 'pemiliknya tidak ditebak');
        $this->assertNull($activity->done_at);
        $this->assertStringContainsString('dipindahkan dari kolom lama', (string) $activity->subject);
    }

    /** Migrate yang berjalan dua kali tidak menggandakan barisnya. */
    public function test_the_conversion_is_idempotent(): void
    {
        DB::table('crm_leads')->insert([
            'code' => 'LEAD-9004', 'name' => 'Prospek berulang', 'status' => 'new',
            'estimated_value' => 0, 'next_follow_up_at' => '2026-10-05',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(1, Activity::query()->count());
    }

    /** Migrasi yang dimuat dari disk, supaya konversinya bisa dijalankan ulang. */
    private function migration(): object
    {
        return require base_path(
            'Modules/Crm/Database/Migrations/2026_09_08_000396_move_typed_lead_follow_up_into_activities.php'
        );
    }
}
