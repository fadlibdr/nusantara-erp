<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Base64Url\Base64Url;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\PushSubscription;
use Tests\ErpTestCase;

/**
 * ROTASI LANGGANAN — RUTE PUBLIK YANG TIDAK BISA MEMBUAT APA PUN
 * (P-3e, T3e.5).
 *
 * `pushsubscriptionchange` menyala di service worker ketika peramban memutar
 * endpoint langganannya sendiri, dan ia menyala ketika TIDAK ADA satu tab pun
 * terbuka. Worker tidak bisa membaca token sesi (ia di localStorage, yang
 * tidak punya API di sana), jadi tidak ada bentuk "panggil API sebagai
 * penggunanya" yang tersedia — dan `PwaServiceWorkerTest` memaku bahwa kode
 * sw.js tidak menyebut `/api` sama sekali.
 *
 * Karena itu rutenya publik, dan karena rutenya publik ia dibatasi tiga kali:
 *
 *  a. TIDAK PERNAH MEMBUAT baris. Endpoint lama yang tidak cocok apa pun
 *     dijawab tanpa menulis — rute ini hanya memindahkan langganan yang sudah
 *     ada, tidak pernah mendaftarkan perangkat.
 *  b. ASAL HARUS SAMA: sebuah baris tidak bisa dialihkan ke layanan push milik
 *     penyerang.
 *  c. Laju dibatasi seperti halaman persetujuan eksternal.
 */
class PushRotationTest extends ErpTestCase
{
    private const P256DH = 'BAQ7Lq3vXk8hHhVrBqEfkS1rXkKq9gYQm2b0sCk5nJd0Uu3rHqDSdwZ9zZKqk1s2OaL0f7d9XyOo2n0F3q9d6bE';

    private function endpoint(string $host, string $suffix): string
    {
        return "https://{$host}/fcm/send/".str_repeat($suffix, 120);
    }

    private function device(User $user, string $endpoint): PushSubscription
    {
        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => self::P256DH,
            'auth' => Base64Url::encode(random_bytes(16)),
            'device_label' => 'Chrome di Android',
        ]);
    }

    private function body(string $old, string $new): array
    {
        return [
            'old_endpoint' => $old,
            'endpoint' => $new,
            'keys' => ['p256dh' => self::P256DH, 'auth' => Base64Url::encode(random_bytes(16))],
        ];
    }

    public function test_a_rotation_moves_the_row_instead_of_stacking_a_second_one(): void
    {
        $user = User::factory()->create();
        $old = $this->endpoint('fcm.googleapis.com', 'a');
        $new = $this->endpoint('fcm.googleapis.com', 'b');
        $row = $this->device($user, $old);

        $this->postJson('push/rotate', $this->body($old, $new))->assertOk();

        $this->assertSame(1, PushSubscription::query()->count(), 'Rotasi menumpuk baris kedua; orangnya akan menerima setiap pemberitahuan dua kali.');

        $row->refresh();

        $this->assertSame($new, (string) $row->endpoint);
        $this->assertSame(PushSubscription::hashFor($new), (string) $row->endpoint_hash);
        $this->assertSame($user->id, (int) $row->user_id, 'Rotasi tidak boleh memindahkan perangkat ke pemilik lain.');
        $this->assertSame('Chrome di Android', (string) $row->device_label, 'Label perangkat bertahan: ia tidak berubah karena endpoint-nya berputar.');
    }

    public function test_an_unknown_old_endpoint_creates_nothing(): void
    {
        $this->postJson('push/rotate', $this->body(
            $this->endpoint('fcm.googleapis.com', 'x'),
            $this->endpoint('fcm.googleapis.com', 'y'),
        ))->assertOk();

        $this->assertSame(
            0,
            PushSubscription::query()->count(),
            'Rute publik ini MEMBUAT baris. Itu berarti siapa pun di internet bisa mendaftarkan langganan push '
            .'tanpa satu pun kredensial.',
        );
    }

    public function test_a_new_endpoint_on_another_push_service_is_refused(): void
    {
        $user = User::factory()->create();
        $old = $this->endpoint('fcm.googleapis.com', 'a');
        $this->device($user, $old);

        $this->postJson('push/rotate', $this->body($old, $this->endpoint('penyerang.example.com', 'b')))
            ->assertStatus(422);

        $this->assertSame($old, (string) PushSubscription::query()->sole()->endpoint, 'Langganan dialihkan ke layanan push lain.');
    }

    public function test_rotating_onto_an_endpoint_that_is_already_registered_leaves_one_row(): void
    {
        $user = User::factory()->create();
        $old = $this->endpoint('fcm.googleapis.com', 'a');
        $new = $this->endpoint('fcm.googleapis.com', 'b');

        $this->device($user, $old);
        $kept = $this->device($user, $new);

        $this->postJson('push/rotate', $this->body($old, $new))->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($kept->id, PushSubscription::query()->sole()->id);
    }

    /**
     * DAN SENSUS RUTE TULIS PUBLIK DI LUAR `api/` — YANG BELUM PERNAH DIHITUNG.
     *
     * `UngatedApiRouteCensusTest` (P-3d) memaku daftar rute TULIS tanpa gerbang
     * izin **di bawah `api/`**, dan rute ini justru tidak berada di sana: ia di
     * `Routes/web.php`, tempat sensus itu tidak melihat sama sekali. Sebuah
     * rute tulis publik yang tidak terlihat sensus mana pun adalah persis
     * bentuk yang paket berikutnya bisa tambahkan tanpa ada yang menyadarinya
     * — jadi daftarnya dipaku di sini, di paket yang menambah anggotanya.
     *
     * Diukur 13 Sep 2026, dan angkanya **LIMA, bukan tiga**: menuliskannya
     * menemukan dua yang tidak pernah disebut dokumen mana pun (`penilaian/
     * {token}` dari F-9, dan rute unggah milik kerangka kerja sendiri). Itu
     * gunanya menghitung alih-alih mengingat.
     *
     * Kelimanya, dengan kapabilitasnya masing-masing:
     *
     *   POST penilaian/{token}    CSAT (F-9) — token sekali-pakai di URL, throttle 10/menit
     *   POST persetujuan/{token}  keputusan MK/Owner (P0-F) — token 20–64 karakter di URL, throttle 10/menit
     *   POST push/rotate          rotasi langganan (P-3e) — ENDPOINT LAMA, dan tidak pernah MEMBUAT baris
     *   POST whatsapp/webhook     status Meta (P-3a) — HMAC-SHA256 atas badan MENTAH, tanpa App Secret 403
     *   PUT  storage/{path}       `storage.local.upload` milik Laravel sendiri, terdaftar karena
     *                             `filesystems.disks.local.serve = true`; kapabilitasnya TANDA TANGAN
     *                             relatif (`ReceiveFile` abort tanpa `?upload=1` bertanda tangan sah)
     */
    public function test_the_public_write_routes_outside_the_api_are_the_five_that_were_counted(): void
    {
        $public = [];

        foreach (Route::getRoutes() as $route) {
            $uri = ltrim((string) $route->uri(), '/');

            if (str_starts_with($uri, 'api/')) {
                continue;
            }

            $methods = array_diff($route->methods(), ['HEAD', 'GET', 'OPTIONS']);
            if ($methods === []) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $guarded = array_filter($middleware, static fn ($m) => is_string($m)
                && (str_starts_with($m, 'permission:') || str_starts_with($m, 'auth:') || str_contains($m, 'Authenticate')));

            if ($guarded !== []) {
                continue;
            }

            foreach ($methods as $method) {
                $public[] = $method.' '.$uri;
            }
        }

        sort($public);

        $this->assertSame(
            [
                'POST penilaian/{token}',
                'POST persetujuan/{token}',
                'POST push/rotate',
                'POST whatsapp/webhook',
                'PUT storage/{path}',
            ],
            $public,
            'Daftar rute TULIS publik di luar `api/` berubah. Sensus P-3d tidak melihat bagian ini, jadi '
            .'sebuah rute baru di sini adalah permukaan tanpa sesi yang tidak terlihat paku mana pun. '
            .'Tambahkan hanya bersama kalimat yang menyebut APA kapabilitasnya.',
        );
    }

    public function test_the_route_needs_no_session_but_refuses_nonsense(): void
    {
        $this->postJson('push/rotate', ['old_endpoint' => 'bukan-url', 'endpoint' => 'juga-bukan', 'keys' => []])
            ->assertStatus(422);

        $this->postJson('push/rotate', $this->body(
            'http://fcm.googleapis.com/fcm/send/a',
            'http://fcm.googleapis.com/fcm/send/b',
        ))->assertStatus(422);
    }
}
