<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * The dashboard fetches seventeen sources in parallel and must survive any one
 * of them failing. It used to survive them the wrong way: safe() ended in
 * `.catch(() => [])`, so a source that FAILED and a source that was genuinely
 * EMPTY produced the identical value, and every tile drawn only when `.length`
 * is truthy simply stopped existing.
 *
 * That is not hypothetical. Under SQLite lock contention the Kalender card and
 * the "Termin siap ditagih" tile disappeared intermittently while the rest of
 * the dashboard looked perfectly healthy — and "Termin siap ditagih" is the tile
 * carrying Rp 14,55 miliar of work already earned and not yet invoiced. A reader
 * who cannot see the tile concludes there is nothing to bill.
 *
 * The rule locked here is deliberately narrow and mechanical: a catch in
 * dashboard.js must at least RECEIVE its error. `.catch(() => ...)` takes no
 * argument, so it cannot log the cause, cannot tag the value, and cannot tell
 * any tile that its number is unknown — it is the swallow, in source form. A
 * catch that takes (error) may still choose to be quiet, but the choice is then
 * visible to a reviewer reading the two lines beneath it.
 *
 * The check is a grep on purpose, for the same reason NavRouteRegistryTest is:
 * there is no JS runtime on this host, and a grep over the file a reviewer would
 * read cannot drift out of date the way a hand-kept list would.
 */
class DashboardTileFailureTest extends ErpTestCase
{
    /**
     * Sources whose card is drawn only when the list is non-empty, plus the
     * money tiles whose value is a reduce(). Both shapes read a failed fetch as
     * "nothing here" unless something branches on the failure first: the former
     * vanishes, the latter prints a confident Rp 0.
     *
     * @var list<string>
     */
    private const SOURCES_THAT_CAN_VANISH = [
        'projects',      // kartu "Progres proyek" (ubin uangnya kini dari `summary`)
        'arInvoices',    // kartu "Piutang jatuh tempo terdekat"
        // Sejak Temuan 79 ketiga ubin uang (Proyek berjalan, Piutang, Hutang)
        // membaca core/dashboard/summary yang menjumlah di SQL — gagalnya SATU
        // fetch itu harus menjatuhkan KETIGA ubin ke failedStat(), bukan Rp 0.
        // `apBills` keluar dari daftar ini bersamaan: ia tak lagi memberi makan
        // ubin atau kartu ber-`.length` mana pun — satu-satunya pembacanya
        // adalah antrean persetujuan, yang menyebut sumber gagalnya lewat
        // inboxFailed ("Gagal dimuat: Tagihan vendor. Daftar ini belum
        // lengkap."), diperiksa per sumber di inboxSources, bukan per nama.
        'summary',       // ubin "Proyek berjalan" + "Piutang" + "Hutang"
        'tickets',       // ubin "Tiket aktif" + kartu "Tiket layanan aktif"
        'lowStock',      // kartu "Stok di bawah minimum"
        'bankBalances',  // ubin "Saldo bank"
        'billingReady',  // ubin "Termin siap ditagih"
        'agenda',        // kartu "Kalender Acara"
    ];

    /** No catch in the dashboard may discard the error it was handed. */
    public function test_the_dashboard_never_turns_a_failed_fetch_into_a_silent_empty_value(): void
    {
        // Code only: dashboard.js's own docblock quotes `.catch(() => [])` by
        // name as the bug it fixed, and a test that cannot tell prose from code
        // would fail on the comment explaining why it must not fail.
        $source = $this->codeOnly($this->dashboard());

        // A regex that quietly stopped matching would make this test a no-op
        // that still reports PASS, so prove the file has catches to inspect.
        $this->assertGreaterThan(
            0,
            preg_match_all('/\.catch\(/', $source),
            'No .catch( was found in dashboard.js. Either the file moved or this test is no longer reading it.',
        );

        $this->assertFalse(
            $this->swallowsFailures($source),
            'public/app/js/views/dashboard.js contains a `.catch(() => [])`-shaped handler. A catch that does not '
            .'receive its error cannot tell the tile that its number is unknown, so the tile silently disappears or '
            .'prints Rp 0 — the exact failure that made the Kalender card and "Termin siap ditagih" vanish under '
            .'SQLite lock contention. Take the error, log it, and tag the value the way safe() now does.',
        );
    }

    /**
     * The refused half: prove the detector says yes to the shape that shipped.
     * Without it the works-test above would pass for every possible input.
     */
    public function test_a_catch_that_discards_its_error_is_reported(): void
    {
        // The three literal forms of the swallow, two of which were in this
        // very file before the fix.
        $this->assertTrue($this->swallowsFailures(
            'const safe = (path, params) => api.get(path, params).then((rows) => rows || []).catch(() => []);',
        ));
        $this->assertTrue($this->swallowsFailures("api.list('core/calendar').catch(() => null),"));
        $this->assertTrue($this->swallowsFailures('load().catch( ( ) => { } )'));

        // ...while a catch that takes its error is accepted, so the detector is
        // not simply refusing every catch in sight.
        $this->assertFalse($this->swallowsFailures(
            ".catch((error) => { console.error('x', error); return Object.assign([], { loadFailure: error }); })",
        ));

        // And prose naming the forbidden shape stays prose. Without codeOnly()
        // this very test would be unfixable: the comment in dashboard.js that
        // explains the swallow would itself be read as the swallow.
        $this->assertFalse($this->swallowsFailures(
            $this->codeOnly('/* dulu `.catch(() => [])`, sekarang tidak lagi */'),
        ));
        $this->assertFalse($this->swallowsFailures(
            $this->codeOnly("// jangan pernah menulis .catch(() => null) di sini\n"),
        ));
    }

    /** Every source that can vanish is branched on before its tile is drawn. */
    public function test_every_source_that_can_vanish_has_a_visible_failure_branch(): void
    {
        // Code only, for the same reason as above: a branch that exists only in
        // a comment protects nothing.
        $source = $this->codeOnly($this->dashboard());

        $this->assertStringContainsString(
            'loadFailure',
            $source,
            'safe() no longer tags a failed fetch, so failure() can never be true and every branch below it is dead.',
        );

        foreach (self::SOURCES_THAT_CAN_VANISH as $name) {
            $this->assertTrue(
                $this->branchesOnFailure($source, $name),
                sprintf(
                    'Dashboard source [%s] is never passed to failure(), so a failed fetch of it is indistinguishable '
                    .'from an empty result: the tile it feeds either disappears or reports zero. Add a failure(%s) '
                    .'branch rendering failedStat()/failedBody() beside the normal one.',
                    $name,
                    $name,
                ),
            );
        }
    }

    /**
     * The refused half of the branch check: a name nothing branches on must be
     * reported, or the loop above passes for any list of names at all.
     */
    public function test_a_source_with_no_failure_branch_is_reported(): void
    {
        $source = $this->codeOnly($this->dashboard());

        $this->assertFalse($this->branchesOnFailure($source, 'sumberYangTidakPernahDiperiksa'));
        // `submitted` is a real identifier in the file that is deliberately NOT
        // a source — proof the matcher reads failure(), not any mention of it.
        $this->assertFalse($this->branchesOnFailure($source, 'submitted'));

        $this->assertTrue($this->branchesOnFailure($source, 'billingReady'));
    }

    /** True when a catch discards the error it was given. */
    private function swallowsFailures(string $source): bool
    {
        // `() =>` with nothing between the parentheses: the handler is not even
        // handed the cause, so nothing downstream can be told about it.
        return preg_match('/\.catch\(\s*\(\s*\)\s*=>/', $source) === 1;
    }

    /**
     * Strip comments and string bodies so the scan reads CODE. One pass over
     * both comment forms and all three quote characters, the same shape the
     * balance checker uses, so a `//` inside a string can never open a comment
     * and a quote inside a comment can never open a string. dashboard.js
     * contains no regular-expression literals (the one construct this simple
     * scanner cannot tell from division); if one is ever added here, the
     * assertGreaterThan guard above fires before any silence can set in,
     * because a desynced scan destroys the `.catch(` occurrences it counts.
     */
    private function codeOnly(string $source): string
    {
        $out = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : '';

            if ($char === '/' && $next === '/') {
                while ($i < $length && $source[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $out .= ' ';

                continue;
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                $i++;
                while ($i < $length && $source[$i] !== $char) {
                    if ($source[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $out .= "''";

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    private function branchesOnFailure(string $source, string $name): bool
    {
        return str_contains($source, "failure({$name})");
    }

    private function dashboard(): string
    {
        return (string) file_get_contents(public_path('app/js/views/dashboard.js'));
    }
}
