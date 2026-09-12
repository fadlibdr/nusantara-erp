<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Http\Controllers\WebhookController;
use Modules\Core\Models\WebhookDelivery;
use Modules\Core\Models\WebhookSubscription;
use Modules\Core\Services\WebhookService;
use Modules\Core\Support\WebhookPayload;
use Modules\Core\Support\WebhookSignature;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Sistem › Webhook — pintu langganan dan log pengiriman (P-3d).
 *
 * RAHASIA TAMPIL SEKALI dan tidak pernah dipulangkan API lagi: itu satu-satunya
 * hal yang membuat kolom terenkripsi di basis data tidak setara dengan sebuah
 * kata sandi bersama.
 */
class WebhookEndpointTest extends ErpTestCase
{
    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('integrator', 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Integrator', 'email' => 'integrator@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Akuntansi eksternal',
            'url' => 'https://penerima.contoh.co.id/nusantara/webhook',
            'events' => ['document.approved'],
        ], $overrides);
    }

    public function test_the_secret_is_returned_once_and_never_again(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $created = $this->postJson('/api/core/webhooks', $this->payload())->assertCreated();

        $secret = (string) $created->json('data.secret');

        $this->assertSame(64, strlen($secret), 'rahasia 256 bit sebagai hex');
        $this->assertSame(WebhookController::SECRET_SHOWN_ONCE, $created->json('data.shown_once'));

        $listed = $this->getJson('/api/core/webhooks')->assertOk();

        $this->assertStringNotContainsString($secret, $listed->getContent());
        $this->assertNull($listed->json('data.subscriptions.0.secret'));
        $this->assertNotNull($listed->json('data.subscriptions.0.secret_set_at'));

        // Terenkripsi di kolomnya: sebuah dump basis data tidak menyerahkan
        // kemampuan menandatangani kiriman atas nama aplikasi ini.
        $raw = (string) DB::table('core_webhook_subscriptions')->value('secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, (string) WebhookSubscription::query()->firstOrFail()->secret);
    }

    public function test_rotating_the_secret_shows_the_new_one_once(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $first = (string) $this->postJson('/api/core/webhooks', $this->payload())->assertCreated()->json('data.secret');
        $id = (int) WebhookSubscription::query()->value('id');

        $rotated = $this->postJson("/api/core/webhooks/{$id}/rotate-secret")->assertOk();
        $second = (string) $rotated->json('data.secret');

        $this->assertNotSame($first, $second);
        $this->assertSame(WebhookController::SECRET_SHOWN_ONCE, $rotated->json('data.shown_once'));
        $this->assertSame($second, (string) WebhookSubscription::query()->firstOrFail()->secret);
    }

    public function test_an_internal_or_plaintext_url_is_refused_with_its_sentence(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $this->postJson('/api/core/webhooks', $this->payload(['url' => 'http://penerima.contoh.co.id/masuk']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $refused = $this->postJson('/api/core/webhooks', $this->payload(['url' => 'https://169.254.169.254/latest/meta-data/']))
            ->assertStatus(422);

        $this->assertStringContainsString('jaringan server ini', implode(' ', $refused->json('errors.url')));
        $this->assertSame(0, WebhookSubscription::query()->count());
    }

    public function test_an_unknown_event_or_document_type_is_refused(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $this->postJson('/api/core/webhooks', $this->payload(['events' => ['document.deleted']]))
            ->assertStatus(422)->assertJsonValidationErrors('events.0');

        $this->postJson('/api/core/webhooks', $this->payload(['document_types' => ['finance/tidak-ada']]))
            ->assertStatus(422)->assertJsonValidationErrors('document_types.0');
    }

    /** Layar membaca resep tanda tangan DARI SERVER, bukan mengetiknya sendiri. */
    public function test_the_signature_recipe_travels_to_the_screen(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $response = $this->getJson('/api/core/webhooks')->assertOk();

        $this->assertSame('X-Nusantara-Signature', $response->json('data.signature.header'));
        $this->assertSame('X-Nusantara-Event', $response->json('data.signature.event_header'));
        $this->assertSame('sha256', $response->json('data.signature.algorithm'));
        $this->assertSame('t.badan_mentah', $response->json('data.signature.signed_value'));
        $this->assertSame(300, $response->json('data.signature.tolerance_seconds'));
        // V-webhook-5: bentuk rahasianya ikut, karena penerima yang meng-hex-decode
        // kunci 64 karakter itu gagal pada SETIAP kiriman tanpa satu pun petunjuk.
        $this->assertSame(
            'Rahasia langganan adalah 64 karakter heksadesimal dan dipakai sebagai KUNCI HMAC APA ADANYA '
            .'(byte ASCII-nya), bukan di-decode dari hex.',
            $response->json('data.signature.secret_form'),
        );
        $this->assertSame(WebhookPayload::EVENTS, $response->json('data.selectable_events'));
        $this->assertSame(1, $response->json('data.payload_version'));
        $this->assertSame(WebhookService::DISABLE_AFTER_FAILURES, $response->json('data.disable_after_failures'));
    }

    /** "Semua jenis dokumen" adalah pilihan, dan harus terbaca sebagai pilihan. */
    public function test_an_unfiltered_subscription_says_all_document_types(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $this->postJson('/api/core/webhooks', $this->payload())->assertCreated();

        $this->getJson('/api/core/webhooks')->assertOk()
            ->assertJsonPath('data.subscriptions.0.document_types_label', 'Semua jenis dokumen');
    }

    public function test_the_delivery_log_reads_back_with_its_reason(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $subscription = new WebhookSubscription;
        $subscription->forceFill([
            'name' => 'Akuntansi eksternal',
            'url' => 'https://penerima.contoh.co.id/nusantara/webhook',
            'secret' => 'rahasia', 'secret_set_at' => now(),
            'events' => WebhookPayload::EVENTS, 'is_active' => true,
        ])->save();

        $body = WebhookPayload::encode(['version' => 1]);

        WebhookDelivery::query()->create([
            'subscription_id' => $subscription->getKey(),
            'subscription_name' => 'Akuntansi eksternal',
            'url' => $subscription->url,
            'event_id' => 'uji-1', 'event' => 'document.approved',
            'document_type' => 'finance/ap-bills', 'document_id' => 7, 'document_code' => 'BILL/2026/0007',
            'payload' => $body,
            'signature' => WebhookSignature::header($body, 'rahasia', now()->getTimestamp()),
            'status' => WebhookDelivery::FAILED,
        ])
            // attempts/error/response_status TIDAK fillable: job yang menulisnya
            // memakai forceFill, dan sebuah baris log yang bisa diisi dari luar
            // adalah sejarah yang bisa dikarang.
            ->forceFill(['attempts' => 5, 'error' => 'Penerima menjawab 500. Internal Server Error'])
            ->save();

        $response = $this->getJson('/api/core/webhooks/deliveries')->assertOk();

        $this->assertSame('failed', $response->json('data.0.status'));
        $this->assertSame(5, $response->json('data.0.attempts'));
        $this->assertSame('Penerima menjawab 500. Internal Server Error', $response->json('data.0.error'));
        $this->assertSame('BILL/2026/0007', $response->json('data.0.document_code'));
        $this->assertSame(1, $response->json('meta.counts.failed'));

        // Muatan dan tanda tangan TIDAK ikut ke daftar: log dibaca setiap
        // pemegang core.update, dan tanda tangan adalah bukti, bukan hiasan.
        $this->assertNull($response->json('data.0.payload'));
        $this->assertNull($response->json('data.0.signature'));
    }

    public function test_deleting_a_subscription_keeps_its_delivery_history(): void
    {
        Sanctum::actingAs($this->userWith('core.update', 'core.delete'), ['*']);

        $this->postJson('/api/core/webhooks', $this->payload())->assertCreated();
        $id = (int) WebhookSubscription::query()->value('id');

        $body = WebhookPayload::encode(['version' => 1]);
        WebhookDelivery::query()->create([
            'subscription_id' => $id, 'subscription_name' => 'Akuntansi eksternal',
            'url' => 'https://penerima.contoh.co.id/nusantara/webhook',
            'event_id' => 'uji-2', 'event' => 'document.approved',
            'document_type' => 'finance/ap-bills', 'document_id' => 8,
            'payload' => $body, 'signature' => 'x', 'status' => WebhookDelivery::SENT,
        ]);

        $this->deleteJson("/api/core/webhooks/{$id}")->assertOk();

        $this->assertSame(0, WebhookSubscription::query()->count());
        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertSame('Akuntansi eksternal', WebhookDelivery::query()->value('subscription_name'));
    }

    public function test_re_enabling_clears_the_automatic_disable(): void
    {
        Sanctum::actingAs($this->userWith('core.update'), ['*']);

        $this->postJson('/api/core/webhooks', $this->payload())->assertCreated();
        $row = WebhookSubscription::query()->firstOrFail();
        $row->forceFill(['disabled_at' => now(), 'disabled_reason' => 'Gagal terus', 'consecutive_failures' => 20])->save();

        $this->postJson('/api/core/webhooks/'.$row->getKey().'/enable')->assertOk();

        $fresh = $row->fresh();
        $this->assertNull($fresh->disabled_at);
        $this->assertNull($fresh->disabled_reason);
        $this->assertSame(0, (int) $fresh->consecutive_failures);
        $this->assertTrue((bool) $fresh->is_active);
    }

    public function test_the_screens_are_behind_core_update(): void
    {
        Sanctum::actingAs($this->userWith('fin.view'), ['*']);

        $this->getJson('/api/core/webhooks')->assertForbidden();
        $this->postJson('/api/core/webhooks', $this->payload())->assertForbidden();
        $this->getJson('/api/core/webhooks/deliveries')->assertForbidden();
    }
}
