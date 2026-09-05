<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\ArInvoiceService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Surat penagihan ke-1 / ke-2 / ke-3 as house forms (T3.7).
 *
 * Production, 4 Sep 2026 (ANALISIS-PROSES-BISNIS-2026-09 §2 row Q2C, §3 gap
 * A2): INV/2026/VIII/0004 Rp 15,42 M is approved, falls due 22 Sep, and the
 * only thing the system will ever do about it is name it in the
 * ar_invoice_due alarm — "diawasi tetapi tanpa tindakan". This file pins the
 * action that closes that gap and the three things it must never do:
 *
 *   - a letter is ISSUED before it is printed (POST {id}/dunning moves the
 *     level and writes the audit row; the sheet of a level not reached is
 *     refused by name), so "printed" and "recorded" cannot drift apart;
 *   - a letter is never re-dated: only the current level prints, dated by
 *     last_dunning_at, and every "N hari" on it counts to that date;
 *   - the letters escalate, and the third is the last.
 *
 * The fixture is the production invoice's shape, ten days into October so it
 * is 18 days past due.
 */
class DunningLetterTest extends ErpTestCase
{
    private const TODAY = '2026-10-10';

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

    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang '.implode(' ', $permissions),
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** INV/2026/VIII/0004's live shape: approved, Rp 15,42 M, due 22 Sep 2026, nothing received. */
    private function invoice(array $overrides = []): ArInvoice
    {
        // One contract per invoice, numbered on: crm_contracts.code is unique
        // and a test that builds four invoices builds four contracts.
        static $contracts = 0;
        $contracts++;

        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'billing_address' => 'Graha Sentosa Tower Lt. 12, Jl. Jenderal Sudirman Kav. 52-53, Jakarta Selatan',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $contract = Contract::query()->create([
            'code' => 'CTR/2026/I/'.str_pad((string) $contracts, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'ppn_rate' => 11.0,
            'status' => 'approved',
        ]);

        return ArInvoice::query()->create(array_merge([
            'code' => 'INV/2026/VIII/0004',
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-08-23',
            'due_date' => '2026-09-22',
            'description' => 'Penagihan termin 2 — Pembangunan Gedung Kantor Graha Sentosa (CTR/2026/I/0001)',
            'dpp' => 13_891_891_892,
            'ppn_rate' => 11.0,
            'ppn_amount' => 1_528_108_108,
            'total' => 15_420_000_000,
            'amount_paid' => 0,
            'terbilang' => 'Lima belas miliar empat ratus dua puluh juta rupiah',
            'status' => 'approved',
        ], $overrides));
    }

    private function issue(ArInvoice $invoice, string $on): ArInvoice
    {
        Carbon::setTestNow($on.' 09:00:00');

        return app(ArInvoiceService::class)->issueDunningLetter($invoice);
    }

    // ----------------------------------------------------------- the action

    public function test_the_first_letter_is_issued_by_post_and_written_to_the_audit_log(): void
    {
        $collector = $this->userWith('fin.update', 'fin.view');
        $invoice = $this->invoice();

        $this->actingAs($collector)
            ->postJson("/api/finance/ar-invoices/{$invoice->id}/dunning")
            ->assertOk()
            ->assertJsonPath('data.dunning_level', 1)
            ->assertJsonPath('data.dunning_next_level', 2)
            ->assertJsonPath('message', 'Surat penagihan ke-1 INV/2026/VIII/0004 diterbitkan.');

        $invoice->refresh();
        $this->assertSame(1, $invoice->dunning_level);
        $this->assertSame('2026-10-10 09:00:00', $invoice->last_dunning_at?->format('Y-m-d H:i:s'));

        // Audit-logged, explicitly: fin_ar_invoices is not an observed model.
        $log = AuditLog::query()->where('auditable_type', ArInvoice::class)->sole();
        $this->assertSame('dunning', $log->event);
        $this->assertSame($invoice->id, (int) $log->auditable_id);
        $this->assertSame('INV/2026/VIII/0004', $log->auditable_label);
        $this->assertSame($collector->name, $log->user_name);
        // MySQL stores a JSON object in its own key order (shorter keys
        // first: to, from); SQLite keeps the text as written. Compare the
        // pair, not the order.
        $this->assertSame(['from' => 0, 'to' => 1], $this->fromTo($log->changes['dunning_level']));
        $this->assertSame(['from' => null, 'to' => '2026-10-10 09:00:00'], $this->fromTo($log->changes['last_dunning_at']));
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{from: mixed, to: mixed}
     */
    private function fromTo(array $change): array
    {
        return ['from' => $change['from'], 'to' => $change['to']];
    }

    public function test_the_level_moves_one_letter_per_press_and_stops_at_the_third(): void
    {
        $collector = $this->userWith('fin.update');
        $invoice = $this->invoice();

        foreach ([1, 2, 3] as $level) {
            $this->actingAs($collector)
                ->postJson("/api/finance/ar-invoices/{$invoice->id}/dunning")
                ->assertOk()
                ->assertJsonPath('data.dunning_level', $level)
                ->assertJsonPath('data.dunning_next_level', $level === 3 ? null : $level + 1);
        }

        $this->actingAs($collector)
            ->postJson("/api/finance/ar-invoices/{$invoice->id}/dunning")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invoice INV/2026/VIII/0004 sudah pada surat penagihan ke-3 (terakhir); penyelesaian selanjutnya mengikuti ketentuan kontrak, bukan surat lagi.');

        $this->assertSame(3, $invoice->refresh()->dunning_level);
        $this->assertSame(3, AuditLog::query()->where('auditable_type', ArInvoice::class)->count());
    }

    /**
     * The refused half, one sentence each — the same sentence the button's
     * absence stands for (dunning_next_level null), so a curl caller learns
     * what the screen already knew.
     */
    public function test_a_draft_paid_cancelled_or_not_yet_due_invoice_is_refused_by_name(): void
    {
        $collector = $this->userWith('fin.update');

        $cases = [
            [['status' => 'draft'], 'Invoice INV/2026/VIII/0004 belum disetujui (Draf); surat penagihan hanya untuk invoice yang sudah disetujui.'],
            [['amount_paid' => 15_420_000_000, 'paid_at' => '2026-10-01'], 'Invoice INV/2026/VIII/0004 sudah lunas; tidak ada sisa tagihan yang perlu disurati.'],
            [['status' => 'cancelled', 'cancelled_at' => '2026-10-01 10:00:00'], 'Invoice INV/2026/VIII/0004 sudah dibatalkan; tidak ada tagihan yang perlu disurati.'],
            [['due_date' => '2026-10-20'], 'Invoice INV/2026/VIII/0004 belum jatuh tempo (20 Oktober 2026); surat penagihan dicetak setelah tanggal jatuh temponya.'],
        ];

        foreach ($cases as [$overrides, $sentence]) {
            $invoice = $this->invoice($overrides);

            $this->actingAs($collector)
                ->postJson("/api/finance/ar-invoices/{$invoice->id}/dunning")
                ->assertStatus(422)
                ->assertJsonPath('message', $sentence);

            $this->assertSame(0, $invoice->refresh()->dunning_level, $sentence);
            $this->assertNull($invoice->dunningNextLevel(), $sentence);

            // Same number next case: fin_ar_invoices.code is unique, soft-deleted rows included.
            $invoice->forceDelete();
        }

        $this->assertSame(0, AuditLog::query()->where('auditable_type', ArInvoice::class)->count());
    }

    /** Due TODAY is due: the watcher's LEWAT tier names it this morning, and so may the first letter. */
    public function test_an_invoice_due_today_may_get_its_first_letter(): void
    {
        $invoice = $this->invoice(['due_date' => self::TODAY]);

        $this->assertSame(1, $invoice->dunningNextLevel());
    }

    public function test_fin_view_alone_can_reprint_but_not_issue(): void
    {
        $reader = $this->userWith('fin.view');
        $invoice = $this->invoice();

        $this->actingAs($reader)
            ->postJson("/api/finance/ar-invoices/{$invoice->id}/dunning")
            ->assertForbidden();

        $this->issue($invoice, self::TODAY);

        $this->actingAs($reader)
            ->get("/api/core/print/forms/surat-penagihan-1/{$invoice->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_the_resource_carries_the_level_and_which_letter_comes_next(): void
    {
        $admin = $this->adminUser();
        $overdue = $this->invoice();
        $notYetDue = $this->invoice(['code' => 'INV/2026/IX/0005', 'due_date' => '2026-11-01']);

        $this->actingAs($admin)
            ->getJson("/api/finance/ar-invoices/{$overdue->id}")
            ->assertOk()
            ->assertJsonPath('data.dunning_level', 0)
            ->assertJsonPath('data.last_dunning_at', null)
            ->assertJsonPath('data.dunning_next_level', 1);

        $this->actingAs($admin)
            ->getJson("/api/finance/ar-invoices/{$notYetDue->id}")
            ->assertOk()
            ->assertJsonPath('data.dunning_next_level', null);

        $this->issue($overdue, self::TODAY);

        $this->actingAs($admin)
            ->getJson("/api/finance/ar-invoices/{$overdue->id}")
            ->assertOk()
            ->assertJsonPath('data.dunning_level', 1)
            ->assertJsonPath('data.dunning_next_level', 2)
            ->assertJsonPath('data.last_dunning_at', '2026-10-10T09:00:00+07:00');
    }

    // ----------------------------------------------------------- the letters

    /**
     * The three sheets, each rendered at the moment it was issued — 10, 20
     * and 30 October — so the days past due on each are 18, 28 and 38 and
     * stay so on any reprint.
     */
    public function test_the_three_letters_render_and_escalate_in_tone(): void
    {
        $invoice = $this->invoice();

        $this->issue($invoice, '2026-10-10');
        $first = $this->forms->html('surat-penagihan-1', ['id' => $invoice->id]);

        $this->issue($invoice, '2026-10-20');
        $second = $this->forms->html('surat-penagihan-2', ['id' => $invoice->id]);

        $this->issue($invoice, '2026-10-30');
        $third = $this->forms->html('surat-penagihan-3', ['id' => $invoice->id]);

        // The house sheet, addressed, with the invoice number as the subject.
        foreach ([$first, $second, $third] as $html) {
            $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
            $this->assertStringContainsString('PT GRAHA SENTOSA PROPERTINDO', $html);
            $this->assertStringContainsString('INV/2026/VIII/0004', $html);
            $this->assertStringContainsString('Rp 15.420.000.000,00', $html);
            $this->assertStringContainsString('22 September 2026', $html);
            $this->assertStringContainsString($invoice->contract->code, $html);
            $this->assertStringContainsString('Lima belas miliar empat ratus dua puluh juta rupiah', $html);
            $this->assertStringContainsString('Hormat kami,', $html);
            $this->assertStringContainsString('Direktur', $html);
            $this->assertStringNotContainsString('null', $html);
        }

        // Ke-1: a reminder, dated the day it went out, 18 days past due.
        $this->assertStringContainsString('SURAT PENAGIHAN PERTAMA', $first);
        $this->assertStringContainsString('Form F/SP-1', $first);
        $this->assertStringContainsString('Jakarta Timur, 10 Oktober 2026', $first);
        $this->assertMatchesRegularExpression($this->identityCell('LEWAT JATUH TEMPO', '18 hari'), $first);
        $this->assertStringContainsString('kami mengingatkan bahwa invoice INV/2026/VIII/0004', $first);
        $this->assertStringContainsString('mohon Bapak/Ibu berkenan', $first);
        $this->assertStringNotContainsString('BATAS PEMBAYARAN', $first);
        $this->assertStringNotContainsString('terakhir', $first);

        // Ke-2: a demand by a date the collector writes in, 28 days past due.
        $this->assertStringContainsString('SURAT PENAGIHAN KEDUA', $second);
        $this->assertStringContainsString('Form F/SP-2', $second);
        $this->assertStringContainsString('Jakarta Timur, 20 Oktober 2026', $second);
        $this->assertMatchesRegularExpression($this->identityCell('LEWAT JATUH TEMPO', '28 hari'), $second);
        $this->assertStringContainsString('Merujuk Surat Penagihan Pertama kami atas invoice INV/2026/VIII/0004', $second);
        $this->assertStringContainsString('selama 28 hari', $second);
        $this->assertStringContainsString('selambat-lambatnya pada tanggal yang tercantum pada baris BATAS PEMBAYARAN', $second);
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('BATAS PEMBAYARAN'), $second);
        $this->assertStringNotContainsString('terakhir', $second);

        // Ke-3: the last one, naming what follows — the contract, not a threat.
        $this->assertStringContainsString('SURAT PENAGIHAN KETIGA (TERAKHIR)', $third);
        $this->assertStringContainsString('Form F/SP-3', $third);
        $this->assertStringContainsString('Jakarta Timur, 30 Oktober 2026', $third);
        $this->assertMatchesRegularExpression($this->identityCell('LEWAT JATUH TEMPO', '38 hari'), $third);
        $this->assertStringContainsString('surat penagihan ketiga dan terakhir atas invoice INV/2026/VIII/0004', $third);
        $this->assertStringContainsString('selama 38 hari', $third);
        $this->assertStringContainsString("sesuai ketentuan kontrak {$invoice->contract->code}", $third);
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('BATAS PEMBAYARAN'), $third);

        // Paragraphs, not a grid: the letter body prints as prose.
        $this->assertSame(5, substr_count($first, '<p class="alinea"'));
        $this->assertStringContainsString('>Dengan hormat,</p>', $first);
    }

    /** A reprint of the current letter is the same letter — same date, same days — however late it is printed. */
    public function test_a_reprint_keeps_the_letters_own_date(): void
    {
        $invoice = $this->invoice();
        $this->issue($invoice, '2026-10-10');

        Carbon::setTestNow('2026-12-24 15:00:00');
        $html = $this->forms->html('surat-penagihan-1', ['id' => $invoice->id]);

        $this->assertStringContainsString('Jakarta Timur, 10 Oktober 2026', $html);
        $this->assertMatchesRegularExpression($this->identityCell('LEWAT JATUH TEMPO', '18 hari'), $html);
        $this->assertStringNotContainsString('Jakarta Timur, 24 Desember 2026', $html);
        $this->assertStringNotContainsString('93 hari', $html);
        // The foot still says honestly WHEN this copy came off the printer.
        $this->assertStringContainsString('Dicetak 24 Desember 2026', $html);
    }

    public function test_a_letter_the_invoice_has_not_reached_is_refused_by_name(): void
    {
        $admin = $this->adminUser();
        $invoice = $this->invoice();

        $this->actingAs($admin)
            ->get("/api/core/print/forms/surat-penagihan-1/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Surat penagihan ke-1 INV/2026/VIII/0004 belum diterbitkan — belum ada surat penagihan yang diterbitkan. Terbitkan lewat tombol "Cetak surat penagihan ke-1" pada invoice itu.');

        $this->issue($invoice, self::TODAY);

        $this->actingAs($admin)
            ->get("/api/core/print/forms/surat-penagihan-3/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Surat penagihan ke-3 INV/2026/VIII/0004 belum diterbitkan — tingkat penagihannya masih ke-1. Terbitkan lewat tombol "Cetak surat penagihan ke-2" pada invoice itu.');
    }

    public function test_a_superseded_letter_is_not_reprinted_with_another_date(): void
    {
        $admin = $this->adminUser();
        $invoice = $this->invoice();
        $this->issue($invoice, '2026-10-10');
        $this->issue($invoice, '2026-10-20');

        $this->actingAs($admin)
            ->get("/api/core/print/forms/surat-penagihan-1/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Surat penagihan ke-1 INV/2026/VIII/0004 sudah digantikan surat ke-2; tanggal terbitnya tidak tersimpan, sehingga tidak dicetak ulang dengan tanggal lain — cetak surat ke-2.');

        $this->actingAs($admin)
            ->get("/api/core/print/forms/surat-penagihan-2/{$invoice->id}")
            ->assertOk()
            ->assertSee('SURAT PENAGIHAN KEDUA');
    }

    // ------------------------------------------------------------ the screen

    /** The catalogue draws each letter's reprint button only at its own level. */
    public function test_the_catalogue_offers_each_letter_only_at_its_level(): void
    {
        $rows = collect($this->actingAs($this->adminUser())
            ->getJson('/api/core/print/forms')->assertOk()->json('data'))->keyBy('slug');

        foreach ([1, 2, 3] as $level) {
            $row = $rows["surat-penagihan-{$level}"];
            $this->assertSame('finance/ar-invoices', $row['resource']);
            $this->assertSame("Surat Penagihan ke-{$level}", $row['label']);
            $this->assertSame(['field' => 'dunning_level', 'equals' => $level], $row['onlyWhen']);
        }

        // Every other entry keeps drawing on every row.
        $this->assertNull($rows['tagihan-vendor']['onlyWhen']);
        $this->assertNull($rows['laporan-harian']['onlyWhen']);
    }

    /**
     * No JS runner on this host — the served text is pinned the way
     * ApBillPaymentButtonTest pins its button: three actions, one per level,
     * gated on the server's dunning_next_level, printing the sheet AFTER the
     * POST through a tab opened on the click; and the Cetak ▾ menu filtering
     * on onlyWhen so the two other letters are not dead items.
     */
    public function test_the_screen_offers_only_the_next_letter_and_prints_after_the_post(): void
    {
        $schema = $this->spa('schema.js');
        $start = strpos($schema, "'finance/ar-invoices': {");
        $end = strpos($schema, "'finance/ap-bills': {");
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($schema, $start, $end - $start);

        $this->assertStringContainsString(
            "key: `dunning-\${level}`, label: `Cetak surat penagihan ke-\${level}`, path: '{id}/dunning', method: 'POST'",
            $block,
        );
        $this->assertStringContainsString("perm: 'fin.update', when: (row) => row.dunning_next_level === level", $block);
        $this->assertStringContainsString('printForm: `surat-penagihan-${level}`', $block);
        $this->assertStringContainsString('toast: (code) => `Surat penagihan ke-${level} ${code} diterbitkan.`', $block);

        $actions = $this->spa('views/actions.js');
        $this->assertStringContainsString('const printTab = action.printForm && row ? openPrintTab() : null;', $actions);
        $this->assertStringContainsString('showPrintable(printTab, `core/print/forms/${action.printForm}/', $actions);
        $this->assertStringContainsString("typeof action.confirm === 'function' ? action.confirm(row) : action.confirm", $actions);

        $this->assertStringContainsString('export function printableFor(form, row)', $this->spa('printcatalog.js'));
        $this->assertStringContainsString('.filter((form) => printableFor(form, record))', $this->spa('views/detail.js'));
        $this->assertStringContainsString('.filter((form) => printableFor(form, row))', $this->spa('views/list.js'));
        $this->assertStringContainsString('export function openPrintTab()', $this->spa('print.js'));
    }

    // --------------------------------------------------------------- helpers

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }

    /** One identity line with a printed value (the same cell shape PrintRegistryTest reads). */
    private function identityCell(string $label, string $value): string
    {
        return '~<td class="k">'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*'.preg_quote($value, '~').'\s*</td>~';
    }

    private function ruledIdentityCell(string $label): string
    {
        return '~<td class="k">'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*<span class="fill-line"></span>\s*</td>~';
    }
}
