<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use Tests\ErpTestCase;

/**
 * SENSUS RUTE API, DAN BATAS YANG DIJAGANYA (P-3d).
 *
 * Ability sebuah token pribadi menyempitkan GERBANG IZIN — dan tidak
 * menciptakan gerbang di tempat aplikasi ini sendiri tidak menggerbangi apa
 * pun. Sebuah rute yang hanya menuntut autentikasi dijangkau token terbatas
 * milik seseorang persis seperti sesi peramban orang itu.
 *
 * Batas itu hanya jujur selama UKURANNYA diketahui. Uji ini mengukurnya dan
 * memaku angkanya (12 Sep 2026), jadi rute TULIS ke-30 yang tidak dijaga izin
 * apa pun memerahkan gerbang alih-alih diam-diam memperlebar apa yang bisa
 * dilakukan sebuah token "hanya baca".
 *
 * DIBATASI PADA APA YANG DIJAGANYA. Ia tidak memaku 852 nama rute — daftar
 * sepanjang itu akan merah setiap kali sebuah modul menambahkan endpoint yang
 * wajar (pelajaran 4). Yang dipaku adalah (a) tiga hitungan, dan (b) daftar
 * LITERAL rute TULIS tanpa gerbang izin, yang tiap barisnya adalah keputusan:
 * setiap satu di antaranya harus self-service (rekam pemanggil sendiri) atau
 * memeriksa izin DI DALAM controllernya.
 */
class UngatedApiRouteCensusTest extends ErpTestCase
{
    /**
     * Diukur `php artisan route:list --json` SESUDAH P-3d, 12 Sep 2026.
     *
     * Pada main 8438066 angkanya 852 / 637 / 215; P-3d menambah sepuluh rute —
     * tiga Profil › Token API dan tujuh Sistem › Webhook.
     */
    private const API_ROUTES = 862;

    private const WITH_PERMISSION_MIDDLEWARE = 644;

    private const WITHOUT_PERMISSION_MIDDLEWARE = 218;

    /**
     * 637 RUTE, 639 MIDDLEWARE — dan selisih dua itu adalah cara angka ini
     * pertama kali diukur SALAH.
     *
     * `php artisan route:list --json` memulangkan satu baris per rute dengan
     * DAFTAR middlewarenya, dan menghitung kemunculan `PermissionMiddleware`
     * di seluruh daftar itu memberi 639: `POST subcontract/subcontracts/
     * {subcontract}/advance-payout` dan `.../retention-release` masing-masing
     * membawa DUA (`scm.post` DAN `fin.approve` — satu klik di sana mencetak
     * tagihan AP yang sudah disetujui). Uji ini menghitung RUTE, dan itulah
     * angka yang berarti bagi sebuah token: 637 dijaga izin, 215 tidak.
     */
    private const PERMISSION_MIDDLEWARE_OCCURRENCES = 646;

    /**
     * Rute TULIS di bawah `api/` yang tidak membawa `permission:` sama sekali.
     *
     * Masing-masing sudah diperiksa satu per satu dan masuk salah satu dari dua
     * golongan, yang disebut di LAPORAN P-3d §5:
     *
     *   session-only  menyentuh kredensial atau identitas pemanggil, dan
     *                 karena itu ditolak untuk token pribadi oleh
     *                 `SessionOnly` — bukan oleh sebuah izin
     *   self-service  menyentuh rekam pemanggil sendiri (preferensi, kata
     *                 sandi, absensi sendiri, laporan tersimpan sendiri,
     *                 menandai notifikasi sendiri terbaca) atau pintu tanpa
     *                 sesi (login, lupa kata sandi)
     *   in-controller izinnya diturunkan dari DOKUMEN atau dari RESOURCE yang
     *                 disebut badan permintaan, jadi tidak bisa dituliskan
     *                 sebagai satu nama izin di rutenya (lampiran, impor master
     *                 data, impor dokumen, persetujuan eksternal, delegasi)
     *
     * @var list<string>
     */
    private const UNGATED_WRITES = [
        'DELETE api/core/approval-delegations/{approvalDelegation}',
        'DELETE api/core/attachments/{attachment}',
        'DELETE api/core/reports/saved/{savedReport}',
        // P-3d, dan keduanya dijaga Modules\Iam\Http\Middleware\SessionOnly:
        // sebuah token tidak boleh mencetak atau mencabut token.
        'DELETE api/iam/me/api-tokens/{token}',
        'PATCH api/core/attachments/{attachment}',
        'POST api/core/approval-delegations',
        'POST api/core/attachments',
        'POST api/core/attachments/upload',
        'POST api/core/document-import/{resource}/import',
        'POST api/core/document-import/{resource}/preview',
        'POST api/core/external-approvals',
        'POST api/core/external-approvals/record-physical',
        'POST api/core/external-approvals/{externalApproval}/revoke',
        'POST api/core/master-data/{resource}/import',
        'POST api/core/master-data/{resource}/preview',
        'POST api/core/notifications/read',
        'POST api/core/reports/run',
        'POST api/core/reports/saved',
        'POST api/core/reports/saved/{savedReport}/copy',
        'POST api/hr/attendances/me/clock-in',
        'POST api/hr/attendances/me/clock-out',
        'POST api/iam/auth/forgot-password',
        'POST api/iam/auth/login',
        'POST api/iam/auth/logout',
        'POST api/iam/auth/reset-password',
        'POST api/iam/me/api-tokens',
        'PUT api/core/me/preferences/{key}',
        'PUT api/core/reports/saved/{savedReport}',
        'PUT api/iam/me/onboarding',
        'PUT api/iam/me/password',
        'PUT api/iam/me/phone',
    ];

    /** @return list<array{method: string, uri: string, gated: bool}> */
    private function apiRoutes(): array
    {
        $rows = [];

        foreach (Route::getRoutes() as $route) {
            $uri = ltrim((string) $route->uri(), '/');

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $gated = false;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware)
                    && (str_starts_with($middleware, 'permission:') || str_starts_with($middleware, 'role_or_permission:'))) {
                    $gated = true;
                }
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $rows[] = ['method' => $method, 'uri' => $uri, 'gated' => $gated];
            }
        }

        return $rows;
    }

    public function test_the_measured_size_of_the_api_surface_has_not_drifted(): void
    {
        $rows = $this->apiRoutes();

        $this->assertCount(self::API_ROUTES, $rows, 'Jumlah rute api berubah — perbarui angka DAN LAPORAN P-3d §0.');

        $gated = array_filter($rows, static fn (array $row): bool => $row['gated']);

        $this->assertCount(self::WITH_PERMISSION_MIDDLEWARE, $gated);
        $this->assertCount(self::WITHOUT_PERMISSION_MIDDLEWARE, array_diff_key($rows, $gated));

        $occurrences = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with(ltrim((string) $route->uri(), '/'), 'api/')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $occurrences++;
                }
            }
        }

        $this->assertSame(self::PERMISSION_MIDDLEWARE_OCCURRENCES, $occurrences);
    }

    public function test_every_write_route_without_a_permission_gate_is_one_that_was_looked_at(): void
    {
        $writes = [];

        foreach ($this->apiRoutes() as $row) {
            if (! $row['gated'] && ! in_array($row['method'], ['GET', 'HEAD', 'OPTIONS'], true)) {
                $writes[] = $row['method'].' '.$row['uri'];
            }
        }

        sort($writes);

        $expected = self::UNGATED_WRITES;
        sort($expected);

        $this->assertSame(
            $expected,
            $writes,
            'Sebuah rute TULIS tanpa gerbang izin ditambahkan atau dihapus. Setiap baris di daftar ini adalah '
            .'keputusan: ia harus self-service atau memeriksa izin di dalam controllernya, karena ability token '
            .'tidak bisa mempersempit apa yang tidak dijaga izin. Periksa rutenya, lalu perbarui daftar ini dan '
            .'LAPORAN P-3d §5.',
        );
    }
}
