<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;
use Tests\ErpTestCase;

/**
 * Pemilik prospek (F-3 / T3.4): `owner_user_id`, tanpa tebakan.
 *
 * Kolomnya sudah ada sejak hari pertama dengan nama `user_id`; migrasi 000397
 * menggantinya menjadi owner_user_id — ganti NAMA, bukan kolom kedua, karena
 * kolom kedua "tanpa backfill" akan membuat setiap prospek yang hari ini punya
 * pemilik membaca "Belum ditugaskan" sementara pemiliknya tersimpan di kolom
 * sebelah (2 dari 2 prospek di basis data produksi, terukur 8 Sep 2026).
 *
 * Yang dipaku di sini: nilainya selamat melewati penggantian nama, prospek
 * tanpa pemilik berbunyi satu kalimat yang sama di setiap permukaan, dan tidak
 * ada jalur mana pun yang mengisi pemilik dengan orang yang mengetik barisnya.
 */
class LeadOwnerTest extends ErpTestCase
{
    private function makeLead(array $attributes = []): Lead
    {
        return Lead::query()->create(array_merge([
            'name' => 'Rudi Hartanto',
            'company_name' => 'PT Bangun Sejahtera',
            'status' => LeadStatus::Contacted,
        ], $attributes));
    }

    public function test_the_column_is_named_owner_user_id(): void
    {
        $this->assertTrue(Schema::hasColumn('crm_leads', 'owner_user_id'));
        $this->assertFalse(Schema::hasColumn('crm_leads', 'user_id'),
            'dua kolom pemilik pada satu baris berarti dua kebenaran');
    }

    /**
     * Penggantian nama membawa nilainya, ke dua arah.
     *
     * Dijalankan sebagai down() lalu up() atas baris yang sudah ada: itulah
     * bentuk sesungguhnya dari deploy di atas basis data yang sudah berisi —
     * satu-satunya cara membuktikan bahwa pemilik yang sudah tercatat tidak
     * hilang dalam perjalanan.
     */
    public function test_the_rename_carries_the_owner_value(): void
    {
        $sales = User::query()->create([
            'name' => 'Sales Satu', 'email' => 'sales-1@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $owned = $this->makeLead(['owner_user_id' => $sales->id]);
        $unowned = $this->makeLead(['name' => 'Tanpa pemilik']);

        $migration = require base_path(
            'Modules/Crm/Database/Migrations/2026_09_08_000397_rename_lead_user_id_to_owner_user_id.php'
        );

        $migration->down();
        $this->assertTrue(Schema::hasColumn('crm_leads', 'user_id'));
        $this->assertSame($sales->id, (int) DB::table('crm_leads')->where('id', $owned->id)->value('user_id'));
        $this->assertNull(DB::table('crm_leads')->where('id', $unowned->id)->value('user_id'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('crm_leads', 'owner_user_id'));
        $this->assertSame($sales->id, (int) DB::table('crm_leads')->where('id', $owned->id)->value('owner_user_id'));
        $this->assertNull(DB::table('crm_leads')->where('id', $unowned->id)->value('owner_user_id'),
            'yang kosong tetap kosong — tidak ada backfill');
    }

    /** Prospek tanpa pemilik berbunyi sama di daftar dan di layar dokumen. */
    public function test_an_unowned_lead_reads_belum_ditugaskan_everywhere(): void
    {
        $lead = $this->makeLead();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson("/api/crm/leads/{$lead->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.owner_user_id', null)
            ->assertJsonPath('data.owner_user_name', 'Belum ditugaskan');

        $row = collect($this->actingAs($admin)->getJson('/api/crm/leads')->assertStatus(200)->json('data'))
            ->firstWhere('id', $lead->id);

        $this->assertSame('Belum ditugaskan', $row['owner_user_name']);
    }

    /** Membuat prospek TIDAK menugaskan pembuatnya sebagai pemilik. */
    public function test_creating_a_lead_never_assigns_its_author_as_owner(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->postJson('/api/crm/leads', ['name' => 'Prospek tanpa pemilik'])
            ->assertStatus(201)
            ->assertJsonPath('data.owner_user_name', 'Belum ditugaskan');

        $this->assertNull(Lead::query()->findOrFail($response->json('data.id'))->owner_user_id);
    }

    public function test_an_owner_can_be_assigned_and_read_back_by_name(): void
    {
        $sales = User::query()->create([
            'name' => 'Rina Wijaya', 'email' => 'rina@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $lead = $this->makeLead();

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/leads/{$lead->id}", ['owner_user_id' => $sales->id])
            ->assertStatus(200)
            ->assertJsonPath('data.owner_user_id', $sales->id)
            ->assertJsonPath('data.owner_user_name', 'Rina Wijaya');
    }

    /** Dua saringan: milik seseorang, dan milik belum siapa-siapa. */
    public function test_the_list_can_be_filtered_by_owner_and_by_unassigned(): void
    {
        $sales = User::query()->create([
            'name' => 'Agus Prasetyo', 'email' => 'agus@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $owned = $this->makeLead(['owner_user_id' => $sales->id]);
        $unowned = $this->makeLead(['name' => 'Belum dikejar siapa pun']);
        $admin = $this->adminUser();

        $mine = $this->actingAs($admin)->getJson("/api/crm/leads?owner_user_id={$sales->id}")
            ->assertStatus(200)->json('data');
        $this->assertSame([$owned->id], array_column($mine, 'id'));

        $nobody = $this->actingAs($admin)->getJson('/api/crm/leads?unassigned=1')
            ->assertStatus(200)->json('data');
        $this->assertSame([$unowned->id], array_column($nobody, 'id'));

        // "Tidak" adalah jawaban juga: yang SUDAH ditugaskan. Sampai 8 Sep 2026
        // `boolean()` membuat unassigned=0 memulangkan SELURUH baris — sebuah
        // pilihan yang berbohong tentang apa yang disaringnya.
        $someone = $this->actingAs($admin)->getJson('/api/crm/leads?unassigned=0')
            ->assertStatus(200)->json('data');
        $this->assertSame([$owned->id], array_column($someone, 'id'));

        // Tanpa parameter: dua-duanya.
        $all = $this->actingAs($admin)->getJson('/api/crm/leads')->assertStatus(200)->json('data');
        $this->assertCount(2, $all);
    }

    /**
     * Saringan itu punya KLIK, dan tautannya bisa dibagikan.
     *
     * views/list.js hanya menerima kunci query yang dideklarasikan
     * `def.filters` (seedFromUrl → `declared`); sebuah kunci yang hanya hidup
     * di controller berarti dua hal sekaligus: tidak ada kontrolnya di bilah
     * saringan, dan #/r/crm/leads?unassigned=1 yang dirakit tangan diam-diam
     * memulangkan jawaban yang BERBEDA (seluruh prospek). Diukur di peramban
     * 8 Sep 2026 sebelum perbaikan: hash ditulis ulang menjadi "#/r/crm/leads",
     * pager "dari 37 data".
     */
    public function test_the_unassigned_filter_has_a_control_on_the_screen(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $block = $this->schemaBlock($schema, 'crm/leads');

        $this->assertStringContainsString("{ key: 'unassigned', label: 'Belum ditugaskan', type: 'boolFilter' }", $block,
            'saringan "Belum ditugaskan" tidak dideklarasikan: tidak ada kliknya, dan ?unassigned=1 dibuang seedFromUrl');
    }

    /** Potongan definisi satu resource, dari kunci sampai resource berikutnya. */
    private function schemaBlock(string $schema, string $key): string
    {
        $start = strpos($schema, "  '{$key}': {");
        $this->assertNotFalse($start, "resource [{$key}] tidak ada di schema.js");

        $end = strpos($schema, "\n  '", $start + 5);

        return substr($schema, $start, $end === false ? null : $end - $start);
    }
}
