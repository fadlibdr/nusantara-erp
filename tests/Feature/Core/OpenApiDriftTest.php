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
 * `Route::getRoutes()` pada TIGA hal, dan ketiganya dibuktikan bisa memerah
 * dengan mutasi (LAPORAN P-3d §7):
 *
 *   1. JALUR — endpoint yang didokumentasikan tetapi rutenya hilang atau
 *      berganti jalur;
 *   2. METODE HTTP — sebuah `GET` yang menjadi `POST`;
 *   3. IZIN — gerbang yang berubah, sehingga dokumen berbohong tentang SIAPA
 *      yang boleh memanggilnya. Ini yang paling berbahaya dari ketiganya:
 *      jalur dan metode yang salah gagal dengan berisik di sisi penerima, izin
 *      yang salah gagal 403 pada hari integrasi sudah berjalan berbulan-bulan.
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
                ];
            }
        }

        return $operations;
    }

    /**
     * Peta rute NYATA: "METODE /jalur" => daftar izin yang menggerbanginya.
     *
     * @return array<string, list<string>>
     */
    private function actualRoutes(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $uri = '/'.ltrim((string) $route->uri(), '/');
            $permissions = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                    $permissions[] = trim($permission);
                }
            }

            sort($permissions);

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $map[$method.' '.$uri] = $permissions;
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

            if ($documented !== $actual[$key]) {
                $wrong[$key] = ['dokumen' => $documented, 'aplikasi' => $actual[$key]];
            }
        }

        $this->assertSame([], $wrong,
            'openapi.json menyebut izin yang BUKAN izin yang menggerbangi rutenya. Sebuah dokumen yang salah soal '
            .'siapa yang boleh memanggil gagal dengan 403 pada hari integrasi sudah berjalan berbulan-bulan — '
            .'perbarui x-izin di docs/api/openapi.json.');
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
        $this->assertStringContainsString('data.permissions', $me);

        $this->assertStringContainsString('X-Api-Token', $doc['x-autentikasi']['header_alternatif']);
        $this->assertStringContainsString('KOSONG', $doc['x-autentikasi']['cors']);
        $this->assertStringContainsString('SUBSET izin', $doc['x-autentikasi']['ability']);

        // 300/menit untuk token integrasi, disebut di jawaban 429 setiap operasi
        // yang menuntut token.
        $throttled = 0;

        foreach ($doc['paths'] as $methods) {
            foreach ($methods as $operation) {
                if (isset($operation['responses']['429'])) {
                    $this->assertStringContainsString('300 permintaan/menit', $operation['responses']['429']['description']);
                    $this->assertStringContainsString('Retry-After', $operation['responses']['429']['description']);
                    $throttled++;
                }
            }
        }

        $this->assertSame(19, $throttled, 'setiap operasi bertoken menyebut batas lajunya; hanya login yang tidak');
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
