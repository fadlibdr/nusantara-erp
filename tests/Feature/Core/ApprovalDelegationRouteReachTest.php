<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Finance\Enums\PeriodStatus;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * DELEGASI HANYA MEMBUKA PINTU KEPUTUSAN DOKUMEN
 * (verifikasi F-1, 7 Sep 2026).
 *
 * F-1 menyaring nama ability dengan ketat — <awalan>.approve dan
 * <awalan>.approve-director, tidak pernah yang lain — dan tiga docblock plus
 * PANDUAN-ADMINISTRATOR §13 menyimpulkan dari situ bahwa delegasi "tidak
 * pernah membuat, mengubah, menghapus atau memposting apa pun". Kesimpulannya
 * salah: izin <awalan>.approve ITU SENDIRI menggerbangi 71 rute, dan 15 di
 * antaranya bukan keputusan atas sebuah dokumen.
 *
 * Yang paling tajam dari kelimabelasnya, dengan komentar rutenya sendiri:
 *   POST api/finance/journals/{journal}/post — "jurnal yang diketik tangan
 *       bisa mengkredit rekening bank persis seperti pembayaran keluar";
 *   POST api/finance/fiscal-periods/{fiscalPeriod}/reopen — "batasnya HARUS
 *       lebih tinggi daripada yang membukanya … siapa pun yang bisa memposting
 *       tidak boleh bisa membuka sendiri periode yang ingin diisinya";
 *   POST api/subcontract/subcontracts/{subcontract}/advance-payout dan
 *       .../retention-release — "satu klik di sini mencetak tagihan AP yang
 *       SUDAH DISETUJUI … maka orang yang id-nya mendarat di baris disetujui
 *       itu harus BENAR-BENAR MEMEGANG hak persetujuan AP".
 *
 * Sebuah delegasi membuat fin.approve persis TIDAK benar-benar dipegang.
 */
class ApprovalDelegationRouteReachTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userHolding(string $email, string ...$permissions): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function delegate(User $giver, User $delegate): ApprovalDelegation
    {
        $row = ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
            'reason' => 'Cuti tahunan',
        ]);

        ApprovalDelegations::flushMemo();

        return $row;
    }

    /**
     * @return array{decision: list<string>, other: list<string>} rute POST yang
     *                                                            digerbangi sebuah izin <awalan>.approve*
     */
    private function approveGatedRoutes(): array
    {
        $decision = [];
        $other = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }

            $gated = false;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, 11)) as $permission) {
                    if (preg_match('/^[a-z]+\.approve(-director)?$/', trim($permission)) === 1) {
                        $gated = true;
                    }
                }
            }

            if (! $gated) {
                continue;
            }

            $uri = '/'.ltrim($route->uri(), '/');

            if (preg_match('#/\{[^}]+\}/(approve|reject)$#', $uri) === 1) {
                $decision[] = $uri;
            } else {
                $other[] = $uri;
            }
        }

        sort($decision);
        sort($other);

        return ['decision' => $decision, 'other' => $other];
    }

    /**
     * DAFTARNYA DITULIS APA ADANYA. Sebuah uji yang menurunkan harapannya dari
     * kode yang sama dengan yang diujinya akan tetap hijau ketika rute ke-16
     * menyelinap masuk — dan justru itu yang dijaga di sini.
     */
    public function test_the_routes_a_bare_approve_permission_also_opens(): void
    {
        $this->assertSame([
            '/api/crm/contracts/{contract}/activate',
            '/api/engineering/drawing-submittals/{drawingSubmittal}/decision',
            '/api/engineering/material-submittals/{materialSubmittal}/decision',
            '/api/finance/fiscal-periods/{fiscalPeriod}/reopen',
            '/api/finance/journals/{journal}/post',
            // Pembatalan OVB (verifikasi F-2): digerbangi fin.approve karena
            // yang ditarik kembali adalah sebuah persetujuan — dan BUKAN sebuah
            // keputusan dokumen, jadi ia benar berada di daftar ini: sebuah
            // delegasi tidak boleh meminjamkan wewenang membatalkan anggaran
            // tahunan yang sudah berlaku.
            '/api/finance/overhead-budgets/{overheadBudget}/cancel',
            '/api/finance/tax-exports/e-bupot/numbers',
            '/api/projects/defects/{defect}/reopen',
            '/api/projects/defects/{defect}/verify',
            '/api/projects/defects/{defect}/waive',
            '/api/projects/safety-incidents/{safetyIncident}/close',
            '/api/projects/safety-incidents/{safetyIncident}/reopen',
            '/api/projects/{project}/close',
            '/api/quality/ncr/{ncr}/verify',
            '/api/subcontract/subcontracts/{subcontract}/advance-payout',
            '/api/subcontract/subcontracts/{subcontract}/retention-release',
        ], $this->approveGatedRoutes()['other']);
    }

    /** Tidak satu pun dari rute BUKAN-keputusan itu menghormati sebuah delegasi. */
    public function test_no_route_outside_a_document_decision_honours_a_delegation(): void
    {
        $routes = $this->approveGatedRoutes();

        $this->assertNotEmpty($routes['decision']);
        $this->assertNotEmpty($routes['other']);

        foreach ($routes['other'] as $uri) {
            $this->assertFalse(
                $this->honouredOn($uri),
                "a delegation would lend its approve permission to {$uri}, which is not a document decision",
            );
        }

        foreach ($routes['decision'] as $uri) {
            $this->assertTrue(
                $this->honouredOn($uri),
                "a delegation must be honoured on {$uri} — that is the whole feature",
            );
        }
    }

    private function honouredOn(string $uri): bool
    {
        $request = Request::create($uri, 'POST');
        $request->setRouteResolver(fn () => new \Illuminate\Routing\Route(['POST'], ltrim($uri, '/'), []));

        app()->instance('request', $request);

        try {
            return ApprovalDelegations::honouredOnThisRequest();
        } finally {
            app()->forgetInstance('request');
        }
    }

    /**
     * UJUNG KE UJUNG, pada rute yang diukur verifier: menutup periode fiskal
     * lalu membukanya kembali. 403 sebelum delegasi, dan 403 SESUDAHNYA.
     */
    public function test_a_delegation_does_not_reopen_a_fiscal_period(): void
    {
        $closer = $this->userHolding('pembukuan@t.local', 'fin.view', 'fin.post');
        $giver = $this->userHolding('direktur@t.local', 'fin.approve', 'fin.view');

        /** @var FiscalPeriod $period */
        $period = FiscalPeriod::query()->create([
            'year' => 2026,
            'month' => 6,
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
        ]);

        $this->actingAs($closer)->postJson("/api/finance/fiscal-periods/{$period->id}/reopen")->assertForbidden();

        $this->delegate($giver, $closer);

        $this->actingAs($closer->fresh())
            ->postJson("/api/finance/fiscal-periods/{$period->id}/reopen")
            ->assertForbidden();

        $this->assertSame(PeriodStatus::Closed, $period->fresh()->status);
    }
}
