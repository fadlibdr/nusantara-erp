<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Papan kanban: blok `board:` menunjuk aksi yang SUDAH ADA, dan tidak satu pun
 * di antaranya memposting (Fase 1 / P1-G).
 *
 * ROADMAP mengikat paket ini pada tiga janji, dan ketiganya bisa dilanggar
 * tanpa satu galat pun di layar:
 *
 *  1. **0 endpoint baru.** Setiap perpindahan adalah aksi yang sudah ada di
 *     blok `actions:` resource itu. Sebuah `moves` yang menunjuk kunci aksi
 *     yang tidak ada membuat papan menolak SETIAP drop ke kolom itu dengan
 *     kalimat yang terdengar seperti soal izin — dan tidak ada yang tahu bahwa
 *     yang salah adalah papannya.
 *  2. **Aksi `post` (jurnal/stok) tidak pernah di papan.** Aturan itu tentang
 *     AKIBAT, bukan tentang kunci: lima resource menyembunyikan posting ke buku
 *     besar atau ke stok di balik kunci bernama `approve`/`acknowledge`. Maka
 *     yang dilarang di sini adalah RESOURCE-nya, disebut namanya.
 *  3. **Drop lewat `runAction()`.** Yang bisa dipaku uji grep adalah dua
 *     syaratnya: aksi tujuan punya `path` (yang `opens` tidak punya, dan
 *     `runAction` akan POST ke `.../undefined`), dan tidak berpindah halaman
 *     sesudahnya (`navigateTo`/`navigateToResult` melewati `onDone`, sehingga
 *     papan tidak pernah tahu perpindahannya berhasil).
 */
class BoardWiringTest extends ErpTestCase
{
    /**
     * Resource yang TIDAK BOLEH punya papan karena salah satu aksinya menulis
     * ke buku besar atau ke stok — meski kuncinya tidak bernama `post`.
     *
     * @var list<string>
     */
    private const LEDGER_OR_STOCK = [
        'inventory/stock-adjustments',
        'finance/ar-invoices',
        'finance/ap-bills',
        'hr/payroll-runs',
        'servicedesk/field-reports',
    ];

    public function test_at_least_one_board_exists_and_each_is_well_formed(): void
    {
        $boards = $this->boards();

        // Penjaga anti-no-op: parser yang berhenti cocok akan membuat setiap
        // uji di bawah lolos atas daftar kosong.
        $this->assertGreaterThanOrEqual(2, count($boards),
            'Kurang dari dua papan terbaca dari schema.js — parser blok board: tidak lagi cocok.');

        foreach ($boards as $key => $board) {
            $this->assertNotSame([], $board['lanes'], "Papan [{$key}] tanpa kolom.");
            $this->assertNotSame([], $board['moves'], "Papan [{$key}] tanpa satu pun perpindahan.");
            $this->assertNotSame('', $board['enum'], "Papan [{$key}] tidak menyebut enum statusnya.");
        }
    }

    /** Setiap kolom papan adalah nilai enum statusnya yang sungguhan. */
    public function test_every_lane_is_a_real_value_of_its_status_enum(): void
    {
        foreach ($this->boards() as $key => $board) {
            $values = $this->enumValues($board['enum']);

            $this->assertNotSame([], $values, "Enum [{$board['enum']}] papan [{$key}] tidak ada di enums.js.");

            foreach ($board['lanes'] as $lane) {
                $this->assertContains($lane, $values,
                    "Kolom [{$lane}] papan [{$key}] bukan nilai enum {$board['enum']} — kolomnya tidak akan pernah terisi.");
            }
        }
    }

    /**
     * Setiap tujuan `moves` adalah kolom papan itu DAN kunci aksi yang ada.
     *
     * Inilah janji "0 endpoint baru", dinyatakan sebagai uji.
     */
    public function test_every_move_targets_a_lane_and_an_existing_action(): void
    {
        $checked = 0;

        foreach ($this->boards() as $key => $board) {
            $actions = $this->actionKeys($key);

            $this->assertNotSame([], $actions, "Blok actions resource [{$key}] tidak terbaca.");

            foreach ($board['moves'] as $lane => $actionKey) {
                $checked++;

                $this->assertContains($lane, $board['lanes'],
                    "Perpindahan papan [{$key}] menuju kolom [{$lane}] yang tidak ada di papan itu.");
                $this->assertContains($actionKey, $actions,
                    "Perpindahan papan [{$key}] ke [{$lane}] menunjuk aksi [{$actionKey}] yang tidak ada di blok "
                    .'actions resource itu — papan akan menolak setiap drop ke kolom itu dengan kalimat yang '
                    .'terdengar seperti soal izin.');
            }
        }

        $this->assertGreaterThan(4, $checked, 'Terlalu sedikit perpindahan yang diperiksa.');
    }

    /**
     * Aksi tujuan punya `path`, dan tidak berpindah halaman sesudahnya.
     *
     * `opens` (pembuka formulir) tidak punya `path` sama sekali;
     * `navigateTo`/`navigateToResult` melewati `onDone`, yang adalah SATU-
     * SATUNYA cara papan mengetahui perpindahannya berhasil — kartunya akan
     * dikembalikan meski servernya menerima.
     */
    public function test_no_move_points_at_a_form_opener_or_a_navigating_action(): void
    {
        foreach ($this->boards() as $key => $board) {
            foreach ($board['moves'] as $lane => $actionKey) {
                $action = $this->actionBlock($key, $actionKey);

                $this->assertNotSame('', $action, "Aksi [{$actionKey}] resource [{$key}] tidak terbaca.");
                $this->assertStringContainsString('path:', $action,
                    "Perpindahan papan [{$key}] ke [{$lane}] memakai aksi [{$actionKey}] yang tidak punya path — "
                    .'aksi pembuka formulir (`opens`) tidak pernah POST ke server.');
                $this->assertStringNotContainsString('navigateTo', $action,
                    "Perpindahan papan [{$key}] ke [{$lane}] memakai aksi [{$actionKey}] yang berpindah halaman: "
                    .'onDone tidak pernah menyala, jadi papan mengembalikan kartunya meski server menerimanya.');
            }
        }
    }

    /** Tidak ada papan atas resource yang aksinya memposting jurnal atau stok. */
    public function test_no_board_sits_on_a_resource_that_posts_to_the_ledger_or_stock(): void
    {
        $boards = array_keys($this->boards());

        foreach (self::LEDGER_OR_STOCK as $forbidden) {
            $this->assertNotContains($forbidden, $boards,
                "Resource [{$forbidden}] memposting ke buku besar atau ke stok di balik salah satu aksinya; "
                .'ROADMAP melarang aksi seperti itu di papan, dan larangannya tentang AKIBAT — bukan tentang '
                .'kunci yang kebetulan bernama post.');
        }

        // …dan tidak ada perpindahan yang kuncinya `post`, untuk resource mana pun.
        foreach ($this->boards() as $key => $board) {
            $this->assertNotContains('post', array_values($board['moves']),
                "Papan [{$key}] memetakan sebuah kolom ke aksi `post`.");
        }
    }

    /** Papan memakai runAction, dan tidak menulis status lewat jalur lain. */
    public function test_the_board_moves_through_the_same_path_as_the_buttons(): void
    {
        $source = (string) file_get_contents(public_path('app/js/views/board.js'));

        $this->assertStringContainsString("import { runAction, inlineNote } from './actions.js';", $source,
            'Papan tidak lagi memakai runAction — catatan inline, maker-checker dan confirm-resubmit hilang bersamanya.');
        $this->assertStringContainsString('runAction(action, row, ctx.def', $source);

        // Tidak ada jalur tulis kedua: papan tidak boleh PUT/POST sendiri.
        foreach (['api.put(', 'api.post(', 'api.del('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source,
                "board.js memanggil {$forbidden} sendiri; setiap perpindahan harus lewat runAction, jalur tombol yang sama.");
        }

        // Predikat penolakan diambil dari actionButtons, dalam urutan yang sama.
        $this->assertStringContainsString('session.can(action.perm)', $source);
        $this->assertStringContainsString('action.when && !action.when(row)', $source);
    }

    /**
     * Kartu yang ditolak DIKEMBALIKAN, dan itu pekerjaan tangan.
     *
     * SortableJS tidak punya API batal: onEnd menyala setelah DOM dipindahkan.
     * Tanpa kedua baris ini sebuah drop yang ditolak meninggalkan kartu di
     * kolom yang bukan statusnya — papan yang berbohong tentang keadaan
     * dokumen, yang justru satu-satunya hal yang dijualnya.
     */
    public function test_a_refused_drop_puts_the_card_back(): void
    {
        $source = (string) file_get_contents(public_path('app/js/views/board.js'));

        $this->assertStringContainsString('from.insertBefore(item, successor)', $source);
        $this->assertStringContainsString('from.appendChild(item)', $source);
        // Tetangga disimpan SEBELUM apa pun yang bisa gagal.
        $this->assertStringContainsString('const successor = from.children[oldIndex]', $source);
        // Drop di tempat tidak pernah mengirim aksi.
        $this->assertStringContainsString('if (from === to) return;', $source);
    }

    /**
     * KARTU YANG BISA DIFOKUS PUNYA PERAN, DAN SPASI MEMBUKANYA.
     *
     * Kartu papan memasang tabindex="0" sejak P1-G, tetapi tanpa role dan
     * tanpa penangan Spasi. Diukur 8 Sep 2026 (fokus di kartu pertama, tombol
     * ditekan berurutan): ArrowRight/ArrowDown tidak memindahkan apa pun,
     * Spasi menggulirkan halaman (scrollY 0 → 827 → 1614) dan kartunya tetap
     * di kolomnya. Papan ini memang tidak punya jalan keyboard untuk
     * MEMINDAHKAN kartu — jalan yang ada adalah membuka dokumennya dan memakai
     * tombol aksinya — jadi yang wajib: kartunya mengumumkan dirinya sebagai
     * tombol, Spasi berperilaku seperti pada tombol (tanpa menggulir), dan
     * kalimat pengantar papan MENYEBUT jalan itu alih-alih membiarkan orang
     * yang tidak bisa menyeret menyimpulkan sendiri.
     */
    public function test_a_board_card_behaves_like_a_button_and_says_the_way_that_exists(): void
    {
        $source = (string) file_get_contents(public_path('app/js/views/board.js'));

        $this->assertStringContainsString("tabindex: '0'", $source);
        $this->assertStringContainsString("role: 'button'", $source,
            'kartu bisa difokus tanpa peran: pembaca layar mengumumkannya sebagai teks yang entah kenapa bisa difokus');
        $this->assertStringContainsString("'aria-label'", $source);
        $this->assertStringContainsString("event.key !== ' '", $source,
            'Spasi tidak ditangani: ia menggulirkan halaman alih-alih membuka kartunya');
        $this->assertStringContainsString('event.preventDefault();', $source,
            'Spasi ditangani tanpa menahan guliran bawaannya');
        $this->assertStringContainsString('Tanpa menyeret:', $source,
            'papan tidak menyebutkan jalan yang ADA bagi orang yang tidak bisa menyeret');
    }

    /**
     * PAPAN YANG DICETAK TIDAK BOLEH MEMOTONG KOLOM DIAM-DIAM.
     *
     * Blok @media print rumah ini mengembalikan setiap pembungkus gulir menjadi
     * `overflow: visible` (.chart-scroll, .table-wrap — "kertas tidak
     * menggulung, jadi seluruh baris harus mengalir"); .board-grid terlewat.
     * Diukur dengan emulate_media('print') pada 794 px (A4 potret @96 dpi):
     * scrollWidth 1500 px, 3 dari 6 kolom papan prospek utuh di halaman, tiga
     * sisanya hilang tanpa satu kalimat pun yang mengaku.
     */
    public function test_the_print_stylesheet_does_not_cut_the_board(): void
    {
        $css = (string) file_get_contents(public_path('app/app.css'));

        $blocks = [];
        $offset = 0;
        while (($at = strpos($css, '@media print', $offset)) !== false) {
            $offset = $at + 12;
            $end = strpos($css, "\n}", $at);
            $blocks[] = substr($css, $at, $end === false ? 4000 : $end - $at);
        }

        $this->assertNotSame([], $blocks, 'tidak ada satu pun blok @media print terbaca');
        $printed = implode("\n", $blocks);

        $this->assertMatchesRegularExpression('/\.board-grid\s*\{[^}]*overflow:\s*visible/', $printed,
            'papan tetap menggulir mendatar di kertas — kolom yang tidak muat hilang tanpa mengaku');
    }

    /* ------------------------------------------------------------ perkakas */

    /** @return array<string, array{enum: string, lanes: list<string>, moves: array<string, string>}> */
    private function boards(): array
    {
        $source = $this->schema();
        $out = [];
        $offset = 0;

        while (($at = strpos($source, "\n    board: {", $offset)) !== false) {
            $offset = $at + 1;

            // Kunci resource-nya: entri terdekat SEBELUM blok ini.
            $before = substr($source, 0, $at);
            if (preg_match_all("/^  '([^']+)': \{$/m", $before, $keys) === 0) {
                continue;
            }
            $key = end($keys[1]);

            $block = substr($source, $at, (int) strpos($source, "\n    },", $at) - $at);

            preg_match("/enum: '([^']+)'/", $block, $enum);
            preg_match("/lanes: \[([^\]]*)\]/", $block, $lanes);
            preg_match("/moves: \{([^}]*)\}/", $block, $moves);

            preg_match_all("/'([^']+)'/", $lanes[1] ?? '', $laneValues);
            preg_match_all("/([a-z_]+): '([^']+)'/", $moves[1] ?? '', $movePairs, PREG_SET_ORDER);

            $out[$key] = [
                'enum' => $enum[1] ?? '',
                'lanes' => $laneValues[1] ?? [],
                'moves' => array_combine(
                    array_column($movePairs, 1),
                    array_column($movePairs, 2),
                ) ?: [],
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function enumValues(string $enum): array
    {
        $source = (string) file_get_contents(public_path('app/js/enums.js'));

        if (preg_match("/^  {$enum}: opts\(\[(.*?)\]\),$/ms", $source, $match) !== 1) {
            return [];
        }

        preg_match_all("/\['([^']*)'/", $match[1], $values);

        return $values[1];
    }

    /** @return list<string> Kunci aksi resource ini, termasuk yang datang dari approvalActions(). */
    private function actionKeys(string $resource): array
    {
        $block = $this->actionsBlock($resource);
        preg_match_all("/key: '([^']+)'/", $block, $keys);
        $out = $keys[1];

        // approvalActions('<prefix>') menyumbang trio submit/approve/reject.
        if (str_contains($block, 'approvalActions(')) {
            $out = array_merge($out, ['submit', 'approve', 'reject']);
        }

        return array_values(array_unique($out));
    }

    /** Potongan sumber satu aksi, atau '' bila datang dari approvalActions(). */
    private function actionBlock(string $resource, string $actionKey): string
    {
        $block = $this->actionsBlock($resource);
        $at = strpos($block, "key: '{$actionKey}'");

        if ($at === false) {
            /*
             * Trio approvalActions() hidup di helper bersama, bukan di entri
             * resource-nya — dibaca dari sana supaya uji ini tetap memeriksa
             * path dan navigateTo yang sesungguhnya.
             */
            $helper = $this->schema();
            $start = strpos($helper, 'function approvalActions(');
            $helperBlock = substr($helper, $start, (int) strpos($helper, "\n}", $start) - $start);
            $keyAt = strpos($helperBlock, "key: '{$actionKey}'");

            return $keyAt === false ? '' : substr($helperBlock, $keyAt, 320);
        }

        return substr($block, $at, 320);
    }

    private function actionsBlock(string $resource): string
    {
        $source = $this->schema();
        $at = strpos($source, "  '{$resource}': {");

        if ($at === false) {
            return '';
        }

        $entry = substr($source, $at, (int) strpos($source, "\n  },", $at) - $at);
        $actionsAt = strpos($entry, 'actions: [');

        return $actionsAt === false ? '' : substr($entry, $actionsAt);
    }

    private function schema(): string
    {
        return (string) file_get_contents(public_path('app/js/schema.js'));
    }
}
