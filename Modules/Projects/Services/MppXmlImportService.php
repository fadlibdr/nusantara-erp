<?php

namespace Modules\Projects\Services;

use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Models\WbsTask;

/**
 * Impor MS Project XML → prj_wbs_tasks + baseline (P8, kriteria #8).
 *
 * XML, bukan .mpp biner: format biner Microsoft tidak terdokumentasi dan
 * membacanya berarti dependensi baru — keduanya dilarang. Yang dibaca adalah
 * subset kecil skema http://schemas.microsoft.com/project dan hanya itu:
 * Tasks > Task { UID, Name, OutlineNumber, OutlineLevel, Start, Finish }.
 * PredecessorLink, Duration, Cost, Calendars dan Assignments SENGAJA
 * diabaikan — prj_wbs_tasks tidak punya kolom dependensi, dan biaya milik
 * RAP, bukan milik jadwal (lihat resolveBac di BaselineService).
 *
 * TIGA KEPUTUSAN YANG MENANGGUNG BEBAN:
 *
 * HANYA PROYEK TANPA WBS. Impor ini menulis seluruh pohon sekaligus, dan
 * menimpa WBS hidup berarti menghapus tugas yang sudah dipakai bon gudang,
 * laporan harian dan baseline yang ada — generateWbsFromBoq boleh melakukannya
 * karena BOQ-nya milik proyek itu sendiri; sebuah berkas dari luar tidak.
 * Proyek yang sudah ber-WBS ditolak dengan menyebut apa yang ada; menggantinya
 * adalah keputusan yang diambil di layar WBS, bukan efek samping unggahan.
 *
 * BOBOT = PORSI DURASI. MS Project tidak mengenal bobot; baseline menuntut
 * bobot daun berjumlah 100%. Satu-satunya angka yang benar-benar dibawa
 * berkasnya adalah rentang tanggal tiap tugas, maka bobot daun = durasi
 * inklusifnya dibagi jumlah durasi seluruh daun — konvensi yang
 * didokumentasikan, bukan tebakan per sel; daun terakhir menerima sisa
 * pembulatan supaya jumlahnya persis 100,0000 (pola generateWbsFromBoq).
 * Kolom Cost pada XML tidak dipakai untuk bobot: nilainya jarang terisi jujur
 * di berkas lapangan, dan BAC di sini memang bukan urusan jadwal.
 *
 * BASELINE LEWAT MESIN YANG ADA. Setelah pohon mendarat, baseline dibekukan
 * oleh BaselineService::snapshot — bukan implementasi kedua — sehingga titik
 * kurva S impor keluar dari PlannedCurve yang sama yang digambar EvmService,
 * dan aturan lamanya ikut berlaku utuh: tanpa RAP dan tanpa bac_override,
 * snapshot menolak dengan kalimatnya sendiri dan SELURUH impor batal (satu
 * transaksi; tidak ada pohon setengah-berbaseline).
 */
class MppXmlImportService
{
    private const NAMESPACE = 'http://schemas.microsoft.com/project';

    public function __construct(private readonly BaselineService $baselines) {}

    /**
     * @param  array{baseline?: bool, bac_override?: float|null, by?: User|null}  $options
     * @return array{tasks: int, baseline: ?ProjectBaseline}
     */
    public function import(Project $project, string $filename, string $content, array $options = []): array
    {
        $project->assertOperational('impor jadwal MPP-XML');
        $this->assertWbsEmpty($project);

        $tasks = $this->parse($filename, $content);
        $this->weigh($tasks, $filename);

        return DB::transaction(function () use ($project, $filename, $tasks, $options): array {
            $written = $this->write($project, $tasks);

            $baseline = null;

            if ($options['baseline'] ?? true) {
                $baseline = $this->baselines->snapshot($project, [
                    'reason' => "Impor jadwal MS Project XML: {$filename}.",
                    'reference_type' => 'mpp_xml',
                    'reference_no' => mb_substr($filename, 0, 100),
                    'bac_override' => $options['bac_override'] ?? null,
                ], $options['by'] ?? null);
            }

            return ['tasks' => $written, 'baseline' => $baseline];
        });
    }

    // ----------------------------------------------------------------- parse

    /**
     * The XML into a flat outline list, refused loudly where it cannot be one.
     *
     * @return array<int, array{uid: string, code: string, name: string, level: int,
     *                          start: ?string, finish: ?string, children: int, weight: float, duration: int}>
     */
    private function parse(string $filename, string $content): array
    {
        $document = new DOMDocument;
        $loaded = @$document->loadXML($content);

        $root = $loaded ? $document->documentElement : null;

        // Terima namespace resmi maupun berkas yang kehilangan namespacenya
        // (beberapa alat ekspor pihak ketiga menanggalkannya), tetapi elemen
        // akarnya harus <Project> — segala hal lain bukan jadwal.
        if ($root === null || $root->localName !== 'Project') {
            throw new LogicException(
                "Berkas {$filename} bukan XML MS Project yang dapat dibaca; "
                .'ekspor jadwal dari Microsoft Project sebagai XML (File > Save As > XML Format).'
            );
        }

        $tasks = [];

        foreach ($this->elements($root, 'Task') as $element) {
            $level = (int) $this->text($element, 'OutlineLevel');
            $uid = $this->text($element, 'UID') ?? '?';

            // Baris ringkasan proyek bawaan MS Project, bukan pekerjaan.
            if ($level === 0 || $uid === '0') {
                continue;
            }

            $name = trim((string) $this->text($element, 'Name'));

            if ($name === '') {
                throw new LogicException("Task UID {$uid} pada {$filename} tidak punya Name; setiap tugas harus bernama.");
            }

            $code = trim((string) ($this->text($element, 'OutlineNumber') ?? ''));

            if ($code === '') {
                $code = (string) (count($tasks) + 1);
            }

            if (mb_strlen($code) > 20) {
                throw new LogicException(
                    "Tugas \"{$name}\" bernomor outline {$code} — lebih dari 20 karakter, "
                    .'melebihi kolom wbs_code; sederhanakan penomoran jadwalnya.'
                );
            }

            $tasks[] = [
                'uid' => $uid,
                'code' => $code,
                'name' => mb_substr($name, 0, 500),
                'level' => $level,
                'start' => $this->date($element, 'Start'),
                'finish' => $this->date($element, 'Finish'),
                'children' => 0,
                'weight' => 0.0,
                'duration' => 0,
            ];
        }

        if ($tasks === []) {
            throw new LogicException("Berkas {$filename} tidak memuat satu pun Task; tidak ada yang bisa diimpor.");
        }

        return $this->link($tasks, $filename);
    }

    /**
     * Resolve the outline levels into parent pointers, the way a person reads
     * the indentation: a task at level N belongs to the nearest task above it
     * at level N-1. A level that jumps deeper by more than one step has no
     * possible parent and refuses the file rather than adopting a guess.
     *
     * @return array<int, array<string, mixed>>
     */
    private function link(array $tasks, string $filename): array
    {
        $stack = []; // level => index of the last task seen at that level

        foreach ($tasks as $index => $task) {
            $level = $task['level'];

            if ($level > 1 && ! isset($stack[$level - 1])) {
                throw new LogicException(sprintf(
                    'Tugas "%s" berada di OutlineLevel %d tanpa induk di level %d; struktur outline %s rusak.',
                    $task['name'],
                    $level,
                    $level - 1,
                    $filename,
                ));
            }

            $tasks[$index]['parent'] = $level > 1 ? $stack[$level - 1] : null;

            if ($level > 1) {
                $tasks[$stack[$level - 1]]['children']++;
            }

            $stack[$level] = $index;
            // Sebuah level yang lebih dalam dari level ini tidak lagi punya
            // induk yang sah begitu kita bergerak maju.
            foreach (array_keys($stack) as $deeper) {
                if ($deeper > $level) {
                    unset($stack[$deeper]);
                }
            }
        }

        return $tasks;
    }

    // ---------------------------------------------------------------- weights

    /**
     * Leaf weights from inclusive duration share; the last leaf absorbs the
     * rounding remainder so the total is exactly 100.0000 — the same closing
     * move ProjectService::generateWbsFromBoq makes with BOQ amounts.
     */
    private function weigh(array &$tasks, string $filename): void
    {
        $totalDays = 0;
        $leaves = [];

        foreach ($tasks as $index => $task) {
            if ($task['children'] > 0) {
                continue;
            }

            if ($task['start'] === null || $task['finish'] === null) {
                throw new LogicException(sprintf(
                    'Tugas "%s" tidak punya Start/Finish; kurva rencana tidak dapat dibentuk tanpa keduanya.',
                    $task['name'],
                ));
            }

            $start = Carbon::parse($task['start']);
            $finish = Carbon::parse($task['finish']);

            if ($finish->lt($start)) {
                throw new LogicException(sprintf(
                    'Tugas "%s" selesai sebelum mulai (%s → %s) pada %s.',
                    $task['name'],
                    $task['start'],
                    $task['finish'],
                    $filename,
                ));
            }

            $days = (int) $start->diffInDays($finish) + 1; // inklusif dua ujung
            $tasks[$index]['duration'] = $days;
            $totalDays += $days;
            $leaves[] = $index;
        }

        $allocated = 0.0;

        foreach ($leaves as $ordinal => $index) {
            $weight = $ordinal === count($leaves) - 1
                ? round(100 - $allocated, 4)
                : round($tasks[$index]['duration'] / $totalDays * 100, 4);

            $allocated = round($allocated + $weight, 4);
            $tasks[$index]['weight'] = $weight;
        }

        // Induk = jumlah bobot anak-anaknya, dijalankan dari daun ke akar
        // (indeks anak selalu lebih besar dari induknya dalam urutan outline).
        foreach (array_reverse(array_keys($tasks)) as $index) {
            $parent = $tasks[$index]['parent'];

            if ($parent !== null) {
                $tasks[$parent]['weight'] = round($tasks[$parent]['weight'] + $tasks[$index]['weight'], 4);
            }
        }
    }

    // ----------------------------------------------------------------- write

    private function write(Project $project, array $tasks): int
    {
        $ids = [];

        foreach ($tasks as $index => $task) {
            $row = $project->wbsTasks()->create([
                'parent_id' => $task['parent'] === null ? null : $ids[$task['parent']],
                'wbs_code' => $task['code'],
                'name' => $task['name'],
                'weight_pct' => $task['weight'],
                'planned_start' => $task['start'],
                'planned_end' => $task['finish'],
                'progress_pct' => 0,
                'sort_order' => $index + 1,
            ]);

            $ids[$index] = $row->id;
        }

        return count($ids);
    }

    // ---------------------------------------------------------------- guards

    private function assertWbsEmpty(Project $project): void
    {
        $existing = $project->wbsTasks()->orderBy('sort_order')->orderBy('wbs_code')->get();

        if ($existing->isEmpty()) {
            return;
        }

        $sample = $existing->take(3)->map(
            fn (WbsTask $task): string => "{$task->wbs_code} {$task->name}",
        )->implode(', ');

        throw new LogicException(sprintf(
            'Proyek %s sudah memiliki %d tugas WBS (%s%s); impor MPP-XML hanya menata proyek yang belum '
            .'ber-WBS. Kosongkan WBS dari layarnya sendiri lebih dulu bila jadwal memang akan diganti.',
            $project->code,
            $existing->count(),
            $sample,
            $existing->count() > 3 ? ', …' : '',
        ));
    }

    // ------------------------------------------------------------------- xml

    /** @return array<int, DOMElement> */
    private function elements(DOMElement $root, string $name): array
    {
        $list = $root->getElementsByTagNameNS(self::NAMESPACE, $name);

        if ($list->length === 0) {
            $list = $root->getElementsByTagName($name);
        }

        return iterator_to_array($list);
    }

    private function text(DOMElement $task, string $name): ?string
    {
        foreach ($task->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child->textContent;
            }
        }

        return null;
    }

    private function date(DOMElement $task, string $name): ?string
    {
        $value = $this->text($task, $name);

        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            // 2026-02-01T08:00:00 → tanggalnya saja; jam kerja milik kalender
            // MS Project dan tidak punya kolom di prj_wbs_tasks.
            return Carbon::parse(trim($value))->toDateString();
        } catch (\Throwable) {
            throw new LogicException("Nilai {$name} \"{$value}\" bukan tanggal yang dapat dibaca.");
        }
    }
}
