<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Support\PushDeviceLabel;
use Tests\ErpTestCase;

/**
 * Bentuk `core_push_subscriptions` (P-3e, migrasi 001804).
 *
 * Empat hal, dan tiga di antaranya sudah pernah dibayar mahal di repo ini:
 *
 *  1. ENDPOINT UTUH DAN TIDAK DIINDEKS. Endpoint FCM yang sungguhan diukur 188
 *     karakter (13 Sep 2026), dan spesifikasi Web Push tidak menjanjikan batas
 *     apa pun; varchar(190) utf8mb4 = 760 byte sudah menyentuh batas indeks
 *     InnoDB. Uji ini menulis endpoint 400 karakter dan membacanya kembali
 *     UTUH — di MySQL sebuah varchar(190) memotongnya (atau menolak, pada mode
 *     ketat), dan langganan yang terpotong adalah langganan yang tidak pernah
 *     bisa dicocokkan lagi.
 *  2. IDENTITAS LEWAT HASH. `endpoint_hash` unik, jadi peramban yang
 *     berlangganan ulang MEMPERBARUI barisnya. Tanpa itu satu orang menerima
 *     satu pemberitahuan sebanyak jumlah kali ia menekan Aktifkan.
 *  3. TIDAK ADA SATU PUN RAHASIA KITA DI TABEL INI. p256dh dan auth milik
 *     PERAMBAN (mereka yang mengenkripsi isi untuk perangkat itu); kunci
 *     privat VAPID hanya ada di .env. Uji memaku bahwa tidak ada kolom yang
 *     namanya menyerupainya.
 *  4. User-Agent MENTAH TIDAK DISIMPAN. Yang disimpan hanya labelnya.
 */
class PushSubscriptionSchemaTest extends ErpTestCase
{
    private function endpointOf(int $length): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.str_repeat('aB3-_x', 200);
    }

    public function test_the_table_exists_with_the_columns_the_channel_reads(): void
    {
        $this->assertTrue(Schema::hasTable('core_push_subscriptions'));

        foreach (['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device_label', 'last_success_at', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('core_push_subscriptions', $column),
                "core_push_subscriptions kehilangan kolom {$column}.",
            );
        }
    }

    public function test_no_column_holds_a_secret_of_ours(): void
    {
        $columns = array_map(
            static fn (array $column): string => strtolower((string) $column['name']),
            Schema::getColumns('core_push_subscriptions'),
        );

        foreach ($columns as $column) {
            $this->assertStringNotContainsString(
                'private',
                $column,
                "Kolom `{$column}` menyimpan sesuatu yang privat. Kunci privat VAPID hanya hidup di .env; "
                .'tabel langganan tidak boleh punya salinan apa pun darinya.',
            );
            $this->assertStringNotContainsString('vapid', $column);
        }

        $this->assertNotContains(
            'user_agent',
            $columns,
            'User-Agent mentah disimpan. Yang dibutuhkan layar hanya label "Chrome di Android"; sisanya adalah '
            .'sidik jari peramban yang tidak dibaca satu baris kode pun dan ikut ke setiap salinan cadangan.',
        );
    }

    public function test_a_400_character_endpoint_survives_the_round_trip_whole(): void
    {
        $user = User::factory()->create();
        $endpoint = $this->endpointOf(400);
        $this->assertGreaterThan(190, strlen($endpoint));

        $row = PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => str_repeat('k', 87),
            'auth' => str_repeat('a', 22),
            'device_label' => 'Chrome di Android',
        ]);

        $this->assertSame(
            $endpoint,
            (string) $row->refresh()->endpoint,
            'Endpoint tidak kembali utuh. Endpoint yang terpotong tidak bisa dicocokkan lagi dengan langganan '
            .'peramban mana pun — perangkat itu berhenti menerima tanpa satu pun galat.',
        );
    }

    public function test_the_endpoint_itself_is_never_indexed_but_its_hash_is_unique(): void
    {
        $indexes = collect(Schema::getIndexes('core_push_subscriptions'));

        foreach ($indexes as $index) {
            $this->assertNotContains(
                'endpoint',
                array_map('strval', $index['columns']),
                'Kolom `endpoint` diindeks. Di MySQL utf8mb4 batas indeks InnoDB adalah 760 byte = 190 karakter, '
                .'dan endpoint push yang NYATA sudah 188 karakter — indeksnya adalah bom waktu, dan itulah '
                .'sebabnya identitasnya dibawa endpoint_hash.',
            );
        }

        $unique = $indexes->first(
            static fn (array $index): bool => array_map('strval', $index['columns']) === ['endpoint_hash'] && $index['unique'],
        );

        $this->assertNotNull(
            $unique,
            'endpoint_hash tidak unik lagi. Tanpa keunikan, satu peramban yang menekan Aktifkan dua kali '
            .'menghasilkan dua baris dan orangnya menerima setiap pemberitahuan dua kali.',
        );
    }

    public function test_resubscribing_the_same_browser_updates_its_row_instead_of_stacking_a_second_one(): void
    {
        $user = User::factory()->create();
        $endpoint = $this->endpointOf(220);

        foreach (['Chrome di Android', 'Chrome di Android'] as $label) {
            PushSubscription::query()->updateOrCreate(
                ['endpoint_hash' => PushSubscription::hashFor($endpoint)],
                ['user_id' => $user->id, 'endpoint' => $endpoint, 'p256dh' => str_repeat('k', 87), 'auth' => str_repeat('a', 22), 'device_label' => $label],
            );
        }

        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_deleting_the_user_takes_their_subscriptions_with_them(): void
    {
        $user = User::factory()->create();
        $endpoint = $this->endpointOf(200);

        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => str_repeat('k', 87),
            'auth' => str_repeat('a', 22),
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertSame(
            0,
            PushSubscription::query()->count(),
            'Langganan pengguna yang dihapus tetap ada. Endpoint yang tidak lagi milik siapa pun tetap akan '
            .'dikirimi selama layanan push menerimanya.',
        );
    }

    public function test_the_delivery_row_keeps_pointing_at_a_device_that_has_been_deleted(): void
    {
        $this->assertTrue(Schema::hasColumn('core_notification_deliveries', 'push_subscription_id'));

        $column = collect(Schema::getColumns('core_notification_deliveries'))->firstWhere('name', 'push_subscription_id');
        $this->assertTrue((bool) $column['nullable'], 'push_subscription_id wajib nullable: baris e-mail dan WhatsApp tidak punya perangkat.');

        $indexes = collect(Schema::getIndexes('core_notification_deliveries'))
            ->map(static fn (array $index): array => array_map('strval', $index['columns']))
            ->all();

        $this->assertContains(
            ['push_subscription_id'],
            $indexes,
            'push_subscription_id tidak diindeks: Kirim ulang dan layar perangkat mencari baris menurut kolom ini.',
        );
    }

    public function test_the_device_label_is_derived_and_never_guessed(): void
    {
        $this->assertSame('Chrome di Android', PushDeviceLabel::fromUserAgent(
            'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
        ));
        $this->assertSame('Safari di iPhone', PushDeviceLabel::fromUserAgent(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        ));
        $this->assertSame('Edge di Windows', PushDeviceLabel::fromUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
        ), 'Edge menulis "Chrome" di User-Agent-nya juga; yang lebih spesifik harus diperiksa lebih dulu.');
        $this->assertSame('Firefox di Linux', PushDeviceLabel::fromUserAgent(
            'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
        ));
        $this->assertSame('Perangkat tanpa label', PushDeviceLabel::fromUserAgent(null));
        $this->assertSame('Peramban lain', PushDeviceLabel::fromUserAgent('sesuatu yang bukan peramban'));
    }
}
