<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\ReportRunner;
use Modules\Core\Support\ReportableResources;
use Modules\Core\Support\ReportDefinition;

/**
 * Laporan Bebas — katalog dan pelaksana (Fase 1 / P1-F).
 *
 * TANPA GERBANG IZIN DI RUTE, dengan sengaja. Izin sebuah laporan adalah izin
 * SUMBERnya (`{prefix}.view` layar daftarnya), dan itu baru diketahui setelah
 * permintaannya dibaca — sebuah `permission:` di rute harus memilih satu izin
 * untuk semua sumber, yang berarti menyembunyikan katalog dari orang yang sah
 * memegang satu modul saja. Pola yang sama dengan `core/search`,
 * `core/calendar` dan `core/deadlines`: registri menyaring dirinya sendiri.
 *
 * URUTAN PEMERIKSAAN — definisi dulu, izin sesudahnya. Membalikkannya membuat
 * endpoint ini oracle keberadaan: "403" untuk sumber yang ada dan "422" untuk
 * yang tidak akan memberi tahu siapa pun sumber mana yang ada tanpa ia boleh
 * melihat satu pun. Kunci sumber toh sudah ada di schema.js yang dikirim ke
 * setiap peramban; yang tidak boleh bocor adalah DATAnya, dan itu dijaga 403.
 */
class ReportController extends ApiController
{
    public function __construct(private readonly ReportRunner $runner) {}

    /**
     * Katalog yang boleh dibaca pemanggil — sumber, kolom, saringan, plafon.
     *
     * Kolom dikirim APA ADANYA termasuk yang ditolak (`why_not`), karena laci
     * pemilih kolom menuliskannya sebagai baris nonaktif di tempat orang
     * mencarinya. Katalog yang menyembunyikan bahwa ia tidak bisa menjawab
     * "sisa piutang" lebih buruk daripada katalog yang mengatakannya.
     */
    public function resources(Request $request): JsonResponse
    {
        $out = [];

        foreach (ReportableResources::for($request->user()) as $key => $entry) {
            $out[] = [
                'key' => $key,
                'label' => $entry['label'],
                'permission' => $entry['permission'],
                'date_column' => $entry['date_column'],
                'soft_deletes' => $entry['soft_deletes'],
                'why' => $entry['why'],
                'columns' => array_map(static fn (string $columnKey, array $column): array => [
                    'key' => $columnKey,
                    'label' => $column['label'],
                    'type' => $column['type'],
                    'lookup' => $column['lookup'] ?? null,
                    'enum' => $column['enum'] ?? null,
                    'dimension' => $column['dimension'],
                    'buckets' => $column['buckets'] ?? [],
                    'measure' => $column['measure'],
                    // Kalimat yang dibaca orangnya di pemilih kolom. Hanya ada
                    // pada kolom yang ditolak; null berarti kolomnya tersedia.
                    'why_not' => $column['why_not'] ?? null,
                ], array_keys($entry['columns']), $entry['columns']),
                'filters' => array_map(static fn (string $filterKey, array $filter): array => [
                    'key' => $filterKey,
                    'label' => $filter['label'],
                    'kind' => $filter['kind'],
                    'lookup' => $filter['lookup'] ?? null,
                    'enum' => $filter['enum'] ?? null,
                ], array_keys($entry['filters']), $entry['filters']),
            ];
        }

        return $this->ok($out, meta: ['limits' => self::limits()]);
    }

    public function run(Request $request): JsonResponse
    {
        try {
            $definition = ReportDefinition::validate($request->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['definition' => [$e->getMessage()]]);
        }

        $entry = ReportableResources::definition($definition['resource']);
        $denied = $this->denied($request, $entry, $definition['resource']);

        if ($denied !== null) {
            return $denied;
        }

        try {
            $data = $this->runner->run($definition);
        } catch (LogicException $e) {
            // Plafon adalah PENOLAKAN, bukan pemotongan: SUM atas 200 dari 340
            // kelompok adalah angka salah yang berpakaian angka benar.
            return $this->error($e->getMessage(), 422, ['limit' => [$e->getMessage()]]);
        }

        return $this->ok(
            $data + [
                'resource' => $definition['resource'],
                'resource_label' => $entry['label'],
                'definition' => $definition,
                'descriptors' => $this->descriptors($entry, $definition),
            ],
            meta: [
                'limits' => self::limits(),
                'queries' => $this->runner->queriesRun(),
                'soft_deleted_excluded' => $entry['soft_deletes'],
                'date_window' => $entry['date_column'] === null ? null : [
                    'column' => $entry['date_column'],
                    'from' => $definition['filters']['date_from'] ?? null,
                    'to' => $definition['filters']['date_to'] ?? null,
                ],
            ],
        );
    }

    /**
     * Deskriptor kolom schema.js APA ADANYA untuk setiap dimensi dan ukuran.
     *
     * Server tidak pernah menuliskan label nilai di jalur ini: SPA yang
     * memanggil labelFor()/enumLabel() — fungsi yang SAMA dengan yang dipakai
     * layar daftarnya. Itulah yang membuat pratinjau tidak bisa berbeda dari
     * layar yang diklaimnya cermin, dan CSV tidak bisa berbeda dari pratinjau.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function descriptors(array $entry, array $definition): array
    {
        $describe = static function (string $key) use ($entry): array {
            $column = $entry['columns'][$key];

            return [
                'key' => $key,
                'label' => $column['label'],
                'type' => $column['type'],
                'lookup' => $column['lookup'] ?? null,
                'enum' => $column['enum'] ?? null,
            ];
        };

        $out = [];

        foreach (($definition['columns'] ?? []) as $key) {
            $out['columns'][] = $describe($key);
        }

        if (isset($definition['row'])) {
            $out['row'] = $describe($definition['row']['column']) + ['bucket' => $definition['row']['bucket'] ?? null];
        }

        if (isset($definition['column'])) {
            $out['column'] = $describe($definition['column']['column']) + ['bucket' => $definition['column']['bucket'] ?? null];
        }

        if (isset($definition['measure'])) {
            $out['measure'] = [
                'agg' => $definition['measure']['agg'],
            ] + (isset($definition['measure']['column'])
                ? $describe($definition['measure']['column'])
                : ['key' => null, 'label' => 'Jumlah baris', 'type' => 'number', 'lookup' => null, 'enum' => null]);
        }

        return $out;
    }

    /**
     * 403 yang MENYEBUT izinnya, atau null bila boleh (pola AttachmentController).
     *
     * @param  array<string, mixed>  $entry
     */
    private function denied(Request $request, array $entry, string $resource): ?JsonResponse
    {
        $user = $request->user();

        if ($user !== null && ReportableResources::allows($user, $entry)) {
            return null;
        }

        return $this->error(sprintf(
            'Anda tidak memiliki hak akses %s untuk melaporkan "%s".',
            implode(' atau ', $entry['permission']),
            $resource,
        ), 403);
    }

    /** @return array{rows: int, groups: int} */
    private static function limits(): array
    {
        // DIUMUMKAN, tidak disalin ke SPA — aturan yang sama dengan
        // meta.sortable listing() dan meta.keys UserPreferences::describe().
        return ['rows' => ReportRunner::MAX_ROWS, 'groups' => ReportRunner::MAX_GROUPS];
    }
}
