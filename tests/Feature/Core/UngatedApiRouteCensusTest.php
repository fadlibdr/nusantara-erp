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
 * Batas itu hanya jujur selama setiap rute TULIS tanpa gerbang izin adalah
 * rute yang SESEORANG SUDAH MELIHATNYA. Itulah — dan hanya itulah — yang
 * dipaku di sini: daftar literal, yang tiap barisnya sebuah keputusan.
 *
 * DIBATASI PADA APA YANG DIJAGANYA (pelajaran 4, V-OPENAPI-6). Versi pertama
 * uji ini juga memaku EMPAT total seluruh aplikasi (862 rute api, 644
 * bergerbang, 218 tidak, 646 kemunculan middleware). Sebuah rute baru yang
 * sepenuhnya wajar DAN dijaga izin — `GET crm/customers/{customer}/
 * ringkasan-piutang` dengan `permission:crm.view` — memerahkan gerbang P-3d
 * dengan pesan yang menyuruh penulisnya menyunting berkas uji P-3d dan laporan
 * P-3d, untuk sesuatu yang tidak ada hubungannya dengan token maupun webhook.
 * Paku yang merah tanpa sebab adalah paku yang akan dimatikan orang, dan yang
 * ikut mati adalah daftar di bawah — satu-satunya bagian yang berharga.
 *
 * Jumlah berjalannya diukur dengan `php artisan route:list --json` ketika ada
 * yang ingin tahu; ia tidak dipaku di mana pun, karena ia tumbuh.
 */
class UngatedApiRouteCensusTest extends ErpTestCase
{
    /**
     * Rute TULIS di bawah `api/` yang tidak membawa `permission:` sama sekali.
     *
     * Masing-masing sudah diperiksa satu per satu dan masuk salah satu dari dua
     * golongan, yang disebut di LAPORAN P-3d §12.1:
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
            .'LAPORAN P-3d §12.1.',
        );
    }
}
