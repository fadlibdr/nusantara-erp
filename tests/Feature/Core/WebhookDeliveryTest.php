<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Jobs\DeliverWebhook;
use Modules\Core\Models\WebhookDelivery;
use Modules\Core\Models\WebhookSubscription;
use Modules\Core\Support\WebhookPayload;
use Modules\Core\Support\WebhookSignature;
use Modules\Core\Support\WebhookUrl;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Webhook keluar, dari persetujuan sampai byte di kabel (P-3d).
 *
 * TIDAK ADA SATU PUN PERMINTAAN SUNGGUHAN KE INTERNET: `Http::fake()` +
 * `Http::preventStrayRequests()` di setUp, dan penyelesai DNS `WebhookUrl`
 * ditukar dengan seam yang memulangkan alamat yang dituliskan uji. Sebuah uji
 * yang benar-benar menghubungi sebuah host akan hijau atau merah menurut
 * jaringan orang yang menjalankannya, yang berarti ia tidak menguji apa pun.
 */
class WebhookDeliveryTest extends ErpTestCase
{
    use FinanceFixtures;

    private const SECRET_HOST = 'penerima.contoh.co.id';

    private const URL = 'https://penerima.contoh.co.id/nusantara/webhook';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Http::preventStrayRequests();
        Http::fake([self::SECRET_HOST.'/*' => Http::response(['ok' => true], 200)]);

        WebhookUrl::resolverUsing(static fn (string $host): array => $host === self::SECRET_HOST ? ['203.0.113.10'] : []);
    }

    protected function tearDown(): void
    {
        WebhookUrl::resolverUsing(null);

        parent::tearDown();
    }

    private function subscription(array $overrides = []): WebhookSubscription
    {
        $row = new WebhookSubscription;
        $row->forceFill(array_merge([
            'name' => 'Akuntansi eksternal',
            'url' => self::URL,
            'secret' => 'rahasia-uji-'.str_repeat('a', 40),
            'secret_set_at' => now(),
            'events' => WebhookPayload::EVENTS,
            'document_types' => null,
            'is_active' => true,
        ], $overrides))->save();

        return $row->fresh();
    }

    private function userWith(string $permission, string $name = 'Petugas'): User
    {
        $role = Role::findOrCreate('role-'.md5($permission.$name), 'web');
        $role->givePermissionTo($permission);

        /** @var User $user */
        $user = User::query()->create([
            'name' => $name, 'email' => str()->random(8).'@nusantara.test', 'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function bill(): ApBill
    {
        return $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'description' => 'Material',
            'dpp' => 50_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-'.str()->random(5),
        ]);
    }

    public function test_an_approval_produces_one_signed_delivery_the_receiver_can_verify(): void
    {
        $subscription = $this->subscription();
        $submitter = $this->userWith('fin.create', 'Staf AP');
        $approver = $this->userWith('fin.approve', 'Direktur Keuangan');

        $bill = $this->bill();
        $bill->submit($submitter);
        $bill->approve($approver, 'Setuju, lampiran lengkap.');

        $deliveries = WebhookDelivery::query()->where('event', 'document.approved')->get();
        $this->assertCount(1, $deliveries);

        $delivery = $deliveries->first();
        $this->assertSame(WebhookDelivery::SENT, $delivery->status);
        $this->assertSame(200, (int) $delivery->response_status);
        $this->assertSame(1, (int) $delivery->attempts);
        $this->assertSame('finance/ap-bills', $delivery->document_type);
        $this->assertSame((int) $bill->id, (int) $delivery->document_id);
        $this->assertNotNull($delivery->delivered_at);

        // KONTRAK YANG DIBACA ORANG LAIN: penerima menghitung ulang tanda
        // tangan atas BYTE yang diterimanya, dengan rahasia langganannya.
        $this->assertTrue(WebhookSignature::verify(
            (string) $delivery->signature,
            (string) $delivery->payload,
            (string) $subscription->secret,
        ));

        $payload = json_decode((string) $delivery->payload, true);
        $this->assertSame(1, $payload['version']);
        $this->assertSame('document.approved', $payload['event']);
        $this->assertSame('approved', $payload['data']['status']);
        $this->assertSame('Direktur Keuangan', $payload['data']['actor']['name']);
        $this->assertSame('Setuju, lampiran lengkap.', $payload['data']['note']);
        $this->assertSame($delivery->event_id, $payload['id']);

        // MUATAN KURUS: tidak ada nilai rupiah yang ikut keluar.
        $this->assertArrayNotHasKey('dpp', $payload['data']);
        $this->assertStringNotContainsString('50000000', (string) $delivery->payload);
    }

    /** BYTE YANG DITANDATANGANI = BYTE YANG DIKIRIM. */
    public function test_the_bytes_that_were_signed_are_the_bytes_that_went_out(): void
    {
        $subscription = $this->subscription();
        $bill = $this->bill();
        $bill->submit($this->userWith('fin.create'));

        $delivery = WebhookDelivery::query()->firstOrFail();

        Http::assertSent(function ($request) use ($delivery, $subscription): bool {
            $this->assertSame((string) $delivery->payload, $request->body());
            $this->assertSame((string) $delivery->signature, $request->header(WebhookSignature::HEADER)[0]);
            $this->assertSame((string) $delivery->event_id, $request->header(WebhookSignature::EVENT_HEADER)[0]);

            return WebhookSignature::verify(
                $request->header(WebhookSignature::HEADER)[0],
                $request->body(),
                (string) $subscription->secret,
            );
        });
    }

    /**
     * SATU PERISTIWA, SATU ID — di seluruh langganan.
     *
     * Penerima yang menolak kiriman ganda berdasarkan `X-Nusantara-Event` harus
     * bisa mempercayainya, dan dua penerima yang membandingkan catatan harus
     * melihat peristiwa yang sama.
     */
    public function test_two_subscriptions_receive_the_same_event_id_with_their_own_signatures(): void
    {
        $first = $this->subscription(['name' => 'Akuntansi']);
        $second = $this->subscription(['name' => 'Gudang', 'secret' => 'rahasia-kedua-'.str_repeat('b', 40)]);

        $this->bill()->submit($this->userWith('fin.create'));

        $rows = WebhookDelivery::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]->event_id, $rows[1]->event_id);
        $this->assertNotSame($rows[0]->signature, $rows[1]->signature);

        $this->assertTrue(WebhookSignature::verify((string) $rows[0]->signature, (string) $rows[0]->payload, (string) $first->secret));
        $this->assertTrue(WebhookSignature::verify((string) $rows[1]->signature, (string) $rows[1]->payload, (string) $second->secret));
        $this->assertFalse(WebhookSignature::verify((string) $rows[1]->signature, (string) $rows[1]->payload, (string) $first->secret));
    }

    public function test_a_subscription_that_does_not_listen_to_the_event_gets_nothing(): void
    {
        $this->subscription(['events' => ['document.rejected']]);

        $this->bill()->submit($this->userWith('fin.create'));

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_a_subscription_filtered_to_other_document_types_gets_nothing(): void
    {
        $this->subscription(['document_types' => ['projects/daily-reports']]);

        $this->bill()->submit($this->userWith('fin.create'));

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_an_inactive_or_auto_disabled_subscription_gets_nothing(): void
    {
        $this->subscription(['name' => 'Dimatikan', 'is_active' => false]);
        $this->subscription(['name' => 'Dinonaktifkan otomatis', 'disabled_at' => now(), 'disabled_reason' => 'Gagal terus']);

        $this->bill()->submit($this->userWith('fin.create'));

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    /**
     * WEBHOOK TIDAK BOLEH MENGKLAIM PERISTIWA YANG DIBATALKAN (perangkap C).
     *
     * `DocumentTransitioned` dipancarkan dari DALAM transaksi bisnis. Di sini
     * transaksi itu dibatalkan sesudah persetujuannya ditulis — periode fiskal
     * yang tertutup, jurnal yang tidak seimbang, sebuah pengecualian mana pun.
     * Tidak boleh ada job yang berjalan, tidak boleh ada baris log, dan tidak
     * boleh ada satu pun permintaan HTTP yang terlanjur berangkat.
     */
    public function test_a_rolled_back_transaction_sends_nothing_and_logs_nothing(): void
    {
        $this->subscription();
        $approver = $this->userWith('fin.approve');
        $submitter = $this->userWith('fin.create');

        $bill = $this->bill();
        $bill->submit($submitter);

        WebhookDelivery::query()->delete();
        // `Http::fake()` menyetel ulang daftar permintaan yang tercatat, jadi
        // hitungan di bawah hanya menghitung apa yang terjadi DI DALAM
        // transaksi yang dibatalkan — bukan kiriman `submitted` di atasnya.
        Http::fake([self::SECRET_HOST.'/*' => Http::response(['ok' => true], 200)]);
        Queue::fake();

        try {
            DB::transaction(function () use ($bill, $approver): void {
                $bill->approve($approver, 'Disetujui lalu dibatalkan.');

                throw new \RuntimeException('periode fiskal tertutup');
            });
            $this->fail('transaksi seharusnya dibatalkan');
        } catch (\RuntimeException $e) {
            $this->assertSame('periode fiskal tertutup', $e->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, WebhookDelivery::query()->count());
        Http::assertSentCount(0);
    }

    /**
     * LANGGANAN YANG RUSAK TIDAK MENJATUHKAN PERSETUJUANNYA.
     *
     * URL yang sudah busuk di basis data (ditulis sebelum kebijakan SSRF, atau
     * lewat tinker) tidak boleh membuat dokumen gagal disetujui.
     */
    public function test_a_broken_subscription_does_not_fail_the_approval(): void
    {
        $this->subscription(['url' => 'https://127.0.0.1:9200/masuk']);

        $approver = $this->userWith('fin.approve');
        $bill = $this->bill();
        $bill->submit($this->userWith('fin.create'));
        $bill->approve($approver);

        $this->assertSame('approved', $bill->fresh()->status->value);

        $delivery = WebhookDelivery::query()->where('event', 'document.approved')->firstOrFail();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertStringContainsString('jaringan server ini', (string) $delivery->error);
        Http::assertSentCount(0);
    }

    /** Pola antrean rumah, dipaku literal — bukan dibaca dari kelas yang diujinya. */
    public function test_the_retry_schedule_is_the_house_pattern(): void
    {
        $job = new DeliverWebhook(1);

        $this->assertSame(5, DeliverWebhook::TRIES);
        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
    }
}
