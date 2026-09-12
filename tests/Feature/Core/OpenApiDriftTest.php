<?php

namespace Tests\Feature\Core;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\ErpTestCase;

/**
 * DOKUMEN YANG DIPELIHARA TANGAN HARUS PUNYA UJI YANG BISA MEMERAHKANNYA
 * (P-3d, perangkap G).
 *
 * `docs/api/openapi.json` adalah janji kepada SISTEM LAIN, dan sebuah janji
 * yang tidak ada yang memeriksanya akan basi pada refactor pertama — diam-diam,
 * karena tidak ada yang gagal ketika sebuah rute berpindah jalur. Uji ini
 * membandingkan kedua puluh operasi yang didokumentasikan terhadap
 * `Route::getRoutes()` pada EMPAT hal, dan keempatnya dibuktikan bisa memerah
 * dengan mutasi (tabel mutasi: LAPORAN P-3d §4):
 *
 *   1. JALUR — endpoint yang didokumentasikan tetapi rutenya hilang atau
 *      berganti jalur;
 *   2. METODE HTTP — sebuah `GET` yang menjadi `POST`;
 *   3. IZIN — gerbang yang berubah, sehingga dokumen berbohong tentang SIAPA
 *      yang boleh memanggilnya. Ini yang paling berbahaya dari ketiganya:
 *      jalur dan metode yang salah gagal dengan berisik di sisi penerima, izin
 *      yang salah gagal 403 pada hari integrasi sudah berjalan berbulan-bulan;
 *   4. AUTENTIKASI — arah yang paling mendasar, dan yang paling lama tidak
 *      dijaga (V-OPENAPI-1). Sebuah rute yang didokumentasikan berpindah ke
 *      luar grup `auth:sanctum` (satu `withoutMiddleware`, mis. saat seseorang
 *      membuat endpoint "publik untuk monitoring") membuka daftar tagihan bagi
 *      siapa pun di internet, sementara dokumen tetap menjanjikan Bearer dan
 *      401 tanpa token. Sampai putaran verifikasi ini mutasi itu LOLOS HIJAU,
 *      karena `actualRoutes()` hanya memungut middleware `permission:`.
 *
 * DIBATASI PADA KEDUA PULUH ITU, dan itu keputusan (pelajaran 4). Sebuah paku
 * yang menyapu seluruh aplikasi akan merah setiap kali sebuah modul menambahkan
 * endpoint yang wajar — 862 rute hari ini — dan paku yang merah tanpa sebab
 * akan dimatikan orang. Yang dijaga di sini hanya apa yang dijanjikan dokumen.
 */
class OpenApiDriftTest extends ErpTestCase
{
    private const DOC = 'docs/api/openapi.json';

    /** ROADMAP-HASHMICRO P-3d: "OpenAPI dipelihara tangan untuk 20 endpoint terpakai". */
    private const DOCUMENTED_OPERATIONS = 20;

    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path(self::DOC);

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'openapi.json harus JSON yang sah — ia dibaca mesin orang lain.');

        return $decoded;
    }

    /** @return list<array{path: string, method: string, permissions: list<string>}> */
    private function documentedOperations(): array
    {
        $operations = [];

        foreach ($this->document()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations[] = [
                    'path' => $path,
                    'method' => strtoupper($method),
                    'permissions' => array_values((array) ($operation['x-izin'] ?? [])),
                    // `security: []` = operasi yang SENGAJA terbuka. Tidak
                    // ditulis = memakai `security` global (Bearer / X-Api-Token).
                    'open' => array_key_exists('security', $operation) && $operation['security'] === [],
                    'operation' => $operation,
                ];
            }
        }

        return $operations;
    }

    /**
     * Peta rute NYATA: "METODE /jalur" => izin yang menggerbanginya DAN apakah
     * rutenya menuntut token sama sekali.
     *
     * @return array<string, array{permissions: list<string>, authed: bool}>
     */
    private function actualRoutes(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $uri = '/'.ltrim((string) $route->uri(), '/');
            $permissions = [];
            $middlewares = $route->gatherMiddleware();

            foreach ($middlewares as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                    $permissions[] = trim($permission);
                }
            }

            sort($permissions);

            // `withoutMiddleware('auth:sanctum')` TIDAK mengeluarkannya dari
            // gatherMiddleware() — ia hanya mendaftarkannya sebagai yang
            // dikecualikan. Membaca yang pertama saja membuat mutasi yang
            // paling berbahaya (satu baris yang membuka endpoint bagi siapa
            // pun di internet) LOLOS HIJAU; V-OPENAPI-1 menemukannya begitu.
            $excluded = array_values(array_filter((array) $route->excludedMiddleware(), 'is_string'));

            $authed = in_array('auth:sanctum', $middlewares, true)
                && ! in_array('auth:sanctum', $excluded, true);

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $map[$method.' '.$uri] = ['permissions' => $permissions, 'authed' => $authed];
            }
        }

        return $map;
    }

    public function test_the_document_describes_exactly_twenty_operations(): void
    {
        $this->assertCount(self::DOCUMENTED_OPERATIONS, $this->documentedOperations());
    }

    /** (1) JALUR dan (2) METODE: setiap operasi yang didokumentasikan benar-benar ada. */
    public function test_every_documented_operation_exists_with_that_path_and_method(): void
    {
        $actual = $this->actualRoutes();
        $missing = [];

        foreach ($this->documentedOperations() as $operation) {
            $key = $operation['method'].' '.$operation['path'];

            if (! array_key_exists($key, $actual)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing,
            'openapi.json menjanjikan endpoint yang tidak ada di aplikasi ini — jalurnya berpindah, metodenya '
            .'berubah, atau rutenya dihapus. Perbaiki dokumennya (docs/api/openapi.json), bukan uji ini.');
    }

    /** (3) IZIN: gerbang yang didokumentasikan = gerbang yang sebenarnya. */
    public function test_every_documented_operation_names_the_permission_that_actually_gates_it(): void
    {
        $actual = $this->actualRoutes();
        $wrong = [];

        foreach ($this->documentedOperations() as $operation) {
            $key = $operation['method'].' '.$operation['path'];

            if (! array_key_exists($key, $actual)) {
                continue; // dilaporkan uji di atas
            }

            $documented = $operation['permissions'];
            sort($documented);

            if ($documented !== $actual[$key]['permissions']) {
                $wrong[$key] = ['dokumen' => $documented, 'aplikasi' => $actual[$key]['permissions']];
            }
        }

        $this->assertSame([], $wrong,
            'openapi.json menyebut izin yang BUKAN izin yang menggerbangi rutenya. Sebuah dokumen yang salah soal '
            .'siapa yang boleh memanggil gagal dengan 403 pada hari integrasi sudah berjalan berbulan-bulan — '
            .'perbarui x-izin di docs/api/openapi.json.');
    }

    /**
     * (4) AUTENTIKASI: dokumen menjanjikan Bearer — rutenya harus menuntutnya.
     *
     * Dibalik juga: `POST iam/auth/login` adalah SATU-SATUNYA operasi yang
     * `security: []`, dan rutenya harus TIDAK membawa `auth:sanctum` (sebuah
     * pintu masuk yang menuntut token yang belum dipunyai siapa pun adalah
     * pintu yang tidak bisa dibuka).
     */
    public function test_every_documented_operation_that_promises_a_token_is_on_a_route_that_demands_one(): void
    {
        $actual = $this->actualRoutes();
        $unauthenticated = [];
        $open = [];

        foreach ($this->documentedOperations() as $operation) {
            $key = $operation['method'].' '.$operation['path'];

            if (! array_key_exists($key, $actual)) {
                continue; // dilaporkan uji jalur/metode
            }

            if ($operation['open']) {
                $open[] = $key;

                if ($actual[$key]['authed']) {
                    $unauthenticated[] = $key.' — didokumentasikan terbuka tetapi rutenya menuntut auth:sanctum';
                }

                continue;
            }

            if (! $actual[$key]['authed']) {
                $unauthenticated[] = $key.' — dokumen menjanjikan Bearer dan 401, rutenya tidak menuntut auth:sanctum';
            }
        }

        $this->assertSame([], $unauthenticated,
            'openapi.json menjanjikan kredensial pada endpoint yang tidak menuntutnya (atau sebaliknya). Ini janji '
            .'paling mendasar dokumen ini kepada sistem lain — bahwa endpoint ini butuh token — dan sebuah rute '
            .'yang berpindah ke luar grup auth:sanctum membukanya bagi siapa pun di internet.');

        $this->assertSame(['POST /api/iam/auth/login'], $open,
            'hanya pintu masuk yang boleh didokumentasikan tanpa kredensial');
    }

    /**
     * OPERASI TULIS HARUS MENYEBUTKAN BADANNYA, DAFTAR HARUS MENYEBUTKAN
     * HALAMANNYA (V-OPENAPI-4).
     *
     * Roadmap menuntut dokumen untuk "20 endpoint terpakai", dan sebuah
     * dokumen yang tidak menyebutkan nama field `email`/`password` tidak bisa
     * dipakai menulis langkah PERTAMA sebuah integrasi; sebuah daftar yang
     * menjanjikan `meta.current_page` tanpa menyebut parameter yang meminta
     * halaman kedua berhenti di 20 baris pertama. Keduanya berakhir dengan
     * telepon — hal yang seluruh gunanya dokumen ini hindari.
     */
    public function test_write_operations_describe_their_body_and_lists_describe_their_paging(): void
    {
        $missingBody = [];
        $missingPaging = [];
        $lists = 0;

        foreach ($this->documentedOperations() as $entry) {
            $key = $entry['method'].' '.$entry['path'];
            $operation = $entry['operation'];

            if (! in_array($entry['method'], ['GET', 'DELETE'], true)) {
                $content = $operation['requestBody']['content']['application/json']['schema'] ?? null;

                if (! is_array($content) || ($content['properties'] ?? []) === []) {
                    $missingBody[] = $key;
                }
            }

            $schema = $operation['responses']['200']['content']['application/json']['schema']['$ref'] ?? null;

            if ($schema !== '#/components/schemas/AmplopDaftar') {
                continue;
            }

            $lists++;
            $names = [];

            foreach ((array) ($operation['parameters'] ?? []) as $parameter) {
                $ref = $parameter['$ref'] ?? null;
                $names[] = is_string($ref) ? $this->parameterName($ref) : ($parameter['name'] ?? '');
            }

            foreach (['page', 'per_page'] as $required) {
                if (! in_array($required, $names, true)) {
                    $missingPaging[] = $key.' tanpa '.$required;
                }
            }
        }

        $this->assertSame([], $missingBody,
            'operasi TULIS yang didokumentasikan tanpa requestBody: penerima tidak bisa menebak nama field-nya.');
        $this->assertSame([], $missingPaging,
            'daftar yang menjanjikan meta.current_page tanpa menyebut parameter yang meminta halaman berikutnya.');
        $this->assertSame(10, $lists, 'jumlah operasi DAFTAR yang didokumentasikan');
    }

    /** Nama parameter di balik sebuah $ref components.parameters. */
    private function parameterName(string $ref): string
    {
        $key = substr($ref, strlen('#/components/parameters/'));

        return (string) ($this->document()['components']['parameters'][$key]['name'] ?? '');
    }

    /**
     * PARAMETER HALAMAN YANG DIDOKUMENTASIKAN ADALAH PARAMETER YANG DIBACA
     * APLIKASINYA — lewat HTTP, bukan dengan membaca dokumennya sendiri.
     */
    public function test_the_paging_parameters_the_document_names_are_the_ones_the_application_reads(): void
    {
        $user = $this->adminUser();
        $token = $user->createToken('uji drift', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/crm/customers?page=2&per_page=1&sort=name&dir=desc')
            ->assertOk();

        $this->assertSame(2, $response->json('meta.current_page'));
        $this->assertSame(1, $response->json('meta.per_page'));
        $this->assertSame('name', $response->json('meta.sort'));
        $this->assertSame('desc', $response->json('meta.dir'));

        // Dan kolom urut yang tidak ada di meta.sortable dijawab 422, seperti
        // yang dituliskan parameter `sort` di dokumen.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/crm/customers?sort=tidak-ada')
            ->assertStatus(422);
    }

    /**
     * Kalimat yang menjanjikan sesuatu harus menjanjikan yang benar.
     *
     * Angka batas laju, bentuk header tanda tangan, dan sikap CORS dipaku
     * literal di sini karena ketiganya dibaca orang di luar tim ini.
     */
    public function test_the_document_states_the_contract_the_application_actually_implements(): void
    {
        $doc = $this->document();

        $this->assertSame('X-Nusantara-Signature', $doc['x-webhook']['tanda_tangan']['header']);
        $this->assertSame('sha256', $doc['x-webhook']['tanda_tangan']['algoritma']);
        $this->assertSame(300, $doc['x-webhook']['tanda_tangan']['jendela_detik']);
        $this->assertSame(
            ['document.submitted', 'document.approved', 'document.rejected'],
            $doc['x-webhook']['peristiwa'],
        );
        $this->assertSame(1, $doc['x-webhook']['versi_muatan']);

        // V-TOKEN-1: `GET iam/auth/me` adalah satu-satunya pintu lewat mana
        // sebuah token bisa membaca abilitynya sendiri, dan dokumen harus
        // mengatakan field mana yang menjawab pertanyaan itu — `permissions`
        // menjawab pertanyaan yang lain dan jauh lebih besar.
        $me = $doc['paths']['/api/iam/auth/me']['get']['responses']['200']['description'];
        $this->assertStringContainsString('token_abilities', $me);

        // V-OPENAPI-2: kedua kasus 403 dituliskan TERPISAH, karena bentuk
        // badannya memang berbeda — dan yang kedua tidak punya `errors` sama
        // sekali. Bentuk kenyataannya dipaku
        // ApiTokenAbilityMatrixTest::test_revoking_the_permission_from_the_role_…
        foreach ($doc['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! isset($operation['responses']['403'])) {
                    continue;
                }

                $forbidden = $operation['responses']['403']['description'];

                $this->assertStringContainsString('errors.token_abilities', $forbidden, $method.' '.$path);
                $this->assertStringContainsString('TANPA kunci `errors`', $forbidden, $method.' '.$path);
            }
        }
        $this->assertStringContainsString('data.permissions', $me);

        $this->assertStringContainsString('X-Api-Token', $doc['x-autentikasi']['header_alternatif']);
        $this->assertStringContainsString('KOSONG', $doc['x-autentikasi']['cors']);
        $this->assertStringContainsString('SUBSET izin', $doc['x-autentikasi']['ability']);

        // 300/menit untuk token integrasi, disebut di jawaban 429 setiap operasi
        // yang menuntut token — DAN 10/menit untuk pintu masuk, yang sampai
        // putaran verifikasi ini tidak disebut sama sekali sementara uji ini
        // MEMAKU kelalaian itu sebagai benar (V-OPENAPI-5). Klien yang
        // membangun logika ulang-coba dari dokumen menyimpulkan login tidak
        // punya batas laju, mengulang dalam loop, dan berhenti pada 429
        // berbahasa Inggris yang tidak ada di dokumen mana pun.
        $throttled = 0;

        foreach ($doc['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! isset($operation['responses']['429'])) {
                    continue;
                }

                $description = $operation['responses']['429']['description'];
                $expected = $path === '/api/iam/auth/login' ? '10 permintaan/menit' : '300 permintaan/menit';

                $this->assertStringContainsString($expected, $description, $method.' '.$path);
                $this->assertStringContainsString('Retry-After', $description);
                $throttled++;
            }
        }

        $this->assertSame(20, $throttled, 'SETIAP operasi menyebut batas lajunya, pintu masuk termasuk');

        // Dan angka 10/menit itu adalah angka yang benar-benar ada di rutenya:
        // menaikkan atau menurunkannya memerahkan dokumen.
        $this->assertContains('throttle:10,1', $this->loginRouteMiddleware());
    }

    /** @return list<string> */
    private function loginRouteMiddleware(): array
    {
        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if ($route->uri() === 'api/iam/auth/login' && in_array('POST', $route->methods(), true)) {
                return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            }
        }

        $this->fail('rute POST api/iam/auth/login tidak ditemukan');
    }

    /**
     * CORS KOSONG, dan itu diperiksa terhadap konfigurasi yang benar-benar
     * berjalan — bukan hanya dituliskan di dokumen (ledger §5 baris 10).
     */
    public function test_cors_is_actually_empty(): void
    {
        $allowed = config('cors.allowed_origins');

        $this->assertTrue(
            $allowed === null || $allowed === [],
            'Ledger pemilik baris 10 memilih CORS kosong. Membukanya berarti sebuah halaman asal lain bisa '
            .'memanggil API ini dari peramban korban dengan kredensialnya.',
        );
    }
}
