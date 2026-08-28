<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Carbon;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Customer;
use Modules\Crm\Services\ContractChangeOrderService;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Kop PERPANJANGAN WAKTU I/II — P0-B: the two lines every project-banded house
 * form has always ruled for the pen, finally printed from the database.
 *
 * The composition rule, and what each test defends:
 *
 *  - the lines print the first two APPROVED addendum waktu of the project's
 *    contract, in change-date order — "+14 hari → 14 Agu 2027 (CCO/…)";
 *  - the paper has exactly two lines, so a THIRD approved addendum makes line
 *    II read "lihat register" — the sheet says where the rest is, it never
 *    silently truncates;
 *  - a draft or rejected addendum never reaches a letterhead, and a value CCO
 *    is not a time extension however approved it is;
 *  - a project whose contract has no approved addendum waktu prints
 *    BYTE-IDENTICALLY to the pre-P0-B renderer: both lines stay the ruled
 *    blanks three parties have always filled by hand.
 */
class FormPrintKopWaktuTest extends ErpTestCase
{
    use FinanceFixtures;

    /** Every CCO number below is issued under this clock: CCO/2026/VIII/000N. */
    private const TODAY = '2026-08-28';

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------- fixtures

    /**
     * A job running 1 Sep 2026 – 31 Jul 2027, so every extension below can be
     * read off the calendar: +14 hari on 31 Juli is 14 Agustus.
     */
    private function projectWithContract(): Project
    {
        $customer = Customer::query()->create([
            'name' => 'PT Angkasa Pura I (Persero)',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $contract = Contract::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Pengembangan Bandar Udara Sultan Hasanudin',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'contract_number_customer' => 'SPK/AP1/2026/VIII/0142',
            'sign_date' => '2026-08-18',
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
            'status' => 'approved',
        ]);

        return Project::query()->create([
            'code' => 'PRJ-2026-002',
            'name' => 'Pengembangan Bandar Udara Sultan Hasanudin - Makassar',
            'contract_id' => $contract->id,
            'customer_id' => $customer->id,
            'type' => 'construction',
            'status' => 'active',
            'city' => 'Makassar',
            'province' => 'Sulawesi Selatan',
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
            'contract_value' => 48_500_000_000,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'consultant_name' => 'PT Jaya CM',
        ]);
    }

    private function timeAddendum(Contract $contract, int $days, string $changeDate): ContractChangeOrder
    {
        return app(ContractChangeOrderService::class)->create([
            'contract_id' => $contract->id,
            'change_date' => $changeDate,
            'title' => 'Perpanjangan waktu — curah hujan ekstrem',
            'change_type' => 'waktu',
            'days_change' => $days,
            'value_change' => 0,
            'reason' => 'kondisi_lapangan',
        ]);
    }

    /** Maker-checker: submitted by one person, approved by another. */
    private function approvedAddendum(Contract $contract, int $days, string $changeDate): ContractChangeOrder
    {
        $order = $this->timeAddendum($contract, $days, $changeDate);
        $order->submit($this->financeUser());

        return app(ContractChangeOrderService::class)->approve($order->refresh(), $this->financeApprover());
    }

    /**
     * The two kop lines by label, off the assembled header — the same array
     * every project-banded sheet prints its identity block from.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function kopLines(Project $project): array
    {
        $identity = collect($this->forms->header($project->refresh())['identity']);

        return [
            $identity->firstWhere('label', 'PERPANJANGAN WAKTU I')['value'],
            $identity->firstWhere('label', 'PERPANJANGAN WAKTU II')['value'],
        ];
    }

    // ------------------------------------------------------------- the lines

    /**
     * Change-date order, not id order and not approval order: the register
     * reads chronologically. The June addendum is CREATED first (so it holds
     * the lower CCO number) and the May one approved first (so the May row's
     * stamped new_end_date is the earlier date) — either wrong ordering fails.
     */
    public function test_the_kop_prints_the_first_two_approved_addenda_in_change_date_order(): void
    {
        $project = $this->projectWithContract();
        $contract = $project->contract;

        $june = $this->timeAddendum($contract, 7, '2027-06-01');        // CCO/2026/VIII/0001
        $may = $this->timeAddendum($contract->refresh(), 14, '2027-05-01'); // CCO/2026/VIII/0002

        $may->submit($this->financeUser());
        app(ContractChangeOrderService::class)->approve($may->refresh(), $this->financeApprover());
        $june->refresh()->submit($this->financeUser());
        app(ContractChangeOrderService::class)->approve($june->refresh(), $this->financeApprover());

        [$first, $second] = $this->kopLines($project);

        $this->assertSame('+14 hari → 14 Agu 2027 (CCO/2026/VIII/0002)', $first);
        $this->assertSame('+7 hari → 21 Agu 2027 (CCO/2026/VIII/0001)', $second);

        // And on the printed sheet itself, exactly as the header composed it.
        $html = $this->forms->html('data-proyek', ['id' => $project->id]);

        $this->assertStringContainsString('+14 hari → 14 Agu 2027 (CCO/2026/VIII/0002)', $html);
        $this->assertStringContainsString('+7 hari → 21 Agu 2027 (CCO/2026/VIII/0001)', $html);
    }

    /** One approved addendum fills line I; line II stays the pen's ruled blank. */
    public function test_a_single_addendum_fills_line_one_and_rules_line_two(): void
    {
        $project = $this->projectWithContract();
        $this->approvedAddendum($project->contract, 14, '2027-05-01');

        [$first, $second] = $this->kopLines($project);

        $this->assertSame('+14 hari → 14 Agu 2027 (CCO/2026/VIII/0001)', $first);
        $this->assertNull($second);

        // Dated inside the execution window every counter is sourced, so the
        // ONE ruled line left on the sheet is PERPANJANGAN WAKTU II.
        $html = $this->forms->html('data-proyek', ['id' => $project->id, 'date' => '2026-10-01']);

        $this->assertSame(1, substr_count($html, '<span class="fill-line"></span>'));
    }

    /** Pengurangan waktu is as real as perpanjangan: the minus prints. */
    public function test_a_time_reduction_prints_its_minus(): void
    {
        $project = $this->projectWithContract();
        $this->approvedAddendum($project->contract, -14, '2027-05-01');

        [$first] = $this->kopLines($project);

        $this->assertSame('-14 hari → 17 Jul 2027 (CCO/2026/VIII/0001)', $first);
    }

    /**
     * THE HONESTY RULE ON TWO RULED LINES. The paper has room for two
     * extensions; a third and later exist all the same, so line II names the
     * register instead of quietly showing only the second — a kop that
     * printed I and II and dropped III would be read as "two extensions,
     * total +21 hari" by every party signing under it.
     */
    public function test_a_third_addendum_makes_line_two_read_lihat_register(): void
    {
        $project = $this->projectWithContract();
        $contract = $project->contract;

        $this->approvedAddendum($contract, 14, '2027-03-01');
        $this->approvedAddendum($contract->refresh(), 7, '2027-04-01');
        $this->approvedAddendum($contract->refresh(), 3, '2027-05-01');

        [$first, $second] = $this->kopLines($project);

        $this->assertSame('+14 hari → 14 Agu 2027 (CCO/2026/VIII/0001)', $first);
        $this->assertSame('lihat register', $second);

        // Neither the second nor the third extension leaks onto the sheet —
        // "lihat register" replaces them, it does not summarise them.
        $html = $this->forms->html('data-proyek', ['id' => $project->id]);

        $this->assertStringContainsString('lihat register', $html);
        $this->assertStringNotContainsString('+7 hari', $html);
        $this->assertStringNotContainsString('+3 hari', $html);
        $this->assertStringNotContainsString('21 Agu 2027', $html);
        $this->assertStringNotContainsString('24 Agu 2027', $html);
    }

    /**
     * A draft promises nothing, a rejection was refused, and a VALUE change
     * order is not a time extension however approved it is. None of the three
     * reaches a letterhead: both lines stay ruled.
     */
    public function test_drafts_rejections_and_value_ccos_never_reach_the_kop(): void
    {
        $project = $this->projectWithContract();
        $contract = $project->contract;
        $service = app(ContractChangeOrderService::class);

        // A draft addendum waktu.
        $this->timeAddendum($contract, 14, '2027-05-01');

        // A submitted-then-rejected one.
        $rejected = $this->timeAddendum($contract->refresh(), 30, '2027-04-01');
        $rejected->submit($this->financeUser());
        $service->reject($rejected->refresh(), $this->financeApprover(), 'Belum disepakati pelanggan');

        // An APPROVED tambah-kurang — money, not time.
        $value = $service->create([
            'contract_id' => $contract->refresh()->id,
            'change_date' => '2027-03-01',
            'title' => 'Penambahan pekerjaan ME',
            'change_type' => 'tambah_kurang',
            'value_change' => 250_000_000,
        ]);
        $value->submit($this->financeUser());
        $service->approve($value->refresh(), $this->financeApprover());

        $this->assertSame([null, null], $this->kopLines($project));

        $html = $this->forms->html('data-proyek', ['id' => $project->id, 'date' => '2026-10-01']);

        $this->assertSame(
            2,
            substr_count($html, '<span class="fill-line"></span>'),
            'both perpanjangan lines stay ruled blanks for the pen',
        );
    }

    /**
     * The paket's own DoD names F/LH: the kop is composed ONCE in header() for
     * every project-banded sheet, and the laporan harian — the form the site
     * prints daily — is where an approved extension is actually read. One
     * render proves the shared header reaches it.
     */
    public function test_the_laporan_harian_kop_shows_the_approved_extension(): void
    {
        $project = $this->projectWithContract();
        $this->approvedAddendum($project->contract, 14, '2027-05-01');

        $report = DailyReport::query()->create([
            'project_id' => $project->id,
            'report_date' => '2026-10-01',
            'weather_am' => 'cerah',
            'manpower_count' => 42,
            'activities' => 'Pekerjaan struktur lantai 2.',
        ]);

        $html = $this->forms->html('laporan-harian', ['id' => $report->id]);

        $this->assertStringContainsString('+14 hari → 14 Agu 2027 (CCO/2026/VIII/0001)', $html);
        $this->assertStringContainsString('PERPANJANGAN WAKTU II', $html);
    }

    // --------------------------------------------------------- the diff proof

    /**
     * THE COMPAT PROOF, the P0-A idiom: a project whose contract has no
     * approved addendum waktu prints BYTE-IDENTICALLY to the pre-P0-B
     * renderer.
     *
     * tests/fixtures/data-proyek-pra-p0b.html was captured before the kop was
     * wired, by rendering exactly the project built above and normalising the
     * one wall-clock string on the sheet — the "Dicetak …" footer. If this
     * test fails, the wired kop has changed a sheet it had no addendum to
     * print on: every existing project keeps its ruled blanks, byte for byte.
     */
    public function test_a_project_without_addenda_prints_byte_identically_to_the_pre_p0b_renderer(): void
    {
        $fixture = base_path('tests/fixtures/data-proyek-pra-p0b.html');
        $this->assertFileExists($fixture, 'The golden fixture is part of this paket; it must not be regenerated from post-P0-B code.');

        $project = $this->projectWithContract();

        $html = preg_replace(
            '/Dicetak .* — Nusantara ERP/u',
            'Dicetak [dinormalisasi] — Nusantara ERP',
            $this->forms->html('data-proyek', ['id' => $project->id]),
        );

        $this->assertSame(file_get_contents($fixture), $html);
    }
}
