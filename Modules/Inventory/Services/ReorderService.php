<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Services\PurchaseRequisitionService;

/**
 * Dari kekurangan stok ke PR DRAF — dan tidak selangkah lebih jauh.
 *
 * ============================== APA YANG TIDAK DILAKUKAN ==============================
 * Layanan ini TIDAK PERNAH mengajukan dan TIDAK PERNAH menyetujui. Ia membuat
 * dokumen berstatus Draf lewat PurchaseRequisitionService yang sudah ada — yang
 * memang selalu menyimpan Draf — dan berhenti. Alasannya sama dengan alasan
 * usulan rekap absensi (F-4) berhenti di isian formulir: sebuah ambang yang
 * salah ketik satu digit akan mengubah dirinya menjadi pesanan pembelian, dan
 * PO adalah uang perusahaan yang keluar. Yang menekan Ajukan tetap manusia,
 * lewat izin prc.update yang sudah ada, di endpoint yang sudah ada.
 * =====================================================================================
 *
 * ================================== IDEMPOTENSI ======================================
 * Menjalankan usulan dua kali TIDAK BOLEH menghasilkan dua PR draf untuk
 * kekurangan yang sama, dan aturannya DINYATAKAN, bukan disimpulkan:
 *
 *   Sebuah item DILEWATI bila ia sudah menjadi baris pada PR TERBUKA —
 *   berstatus draft, submitted atau approved, belum dibuang — yang gudangnya
 *   adalah gudang kekurangan ini ATAU yang tidak menyebut gudang sama sekali.
 *
 * Ditolak (rejected), selesai (closed) dan dibatalkan (cancelled) BUKAN
 * terbuka: PR yang ditolak adalah permintaan yang seseorang tolak, dan
 * mengusulkannya lagi justru yang benar.
 *
 * Lengan "tidak menyebut gudang" adalah pilihan yang sengaja dibuat ke arah
 * yang lebih sepi. prc_purchase_requisitions.warehouse_id nullable; sebuah PR
 * tanpa gudang bisa saja memang untuk gudang ini. Melewatinya berarti kadang
 * TIDAK mengusulkan sesuatu yang benar-benar kurang; tidak melewatinya berarti
 * kadang mengusulkan barang yang sudah dipesan. Yang pertama terlihat di layar
 * — setiap item yang dilewati ditulis beserta kode PR yang menutupinya, jadi
 * orangnya bisa memeriksa dan membuat PR sendiri — sementara yang kedua hanya
 * terlihat setelah barangnya datang dua kali.
 * =====================================================================================
 */
class ReorderService
{
    /** Status PR yang dianggap MASIH BERJALAN untuk keperluan melewati item. */
    private const OPEN_STATUSES = [
        DocumentStatus::Draft,
        DocumentStatus::Submitted,
        DocumentStatus::Approved,
    ];

    public function __construct(
        private readonly StockService $stock,
        private readonly PurchaseRequisitionService $requisitions,
    ) {}

    /**
     * Apa yang AKAN diusulkan, dan apa yang tidak — beserta alasan tiap
     * pengecualian. Baca saja; tidak ada yang tersimpan.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     warehouses: list<array<string, mixed>>,
     *     rules: array{proposable: int, skipped: int},
     *     why_skipped: string
     * }
     */
    public function proposal(?int $warehouseId = null): array
    {
        $shortages = $this->stock->lowStockAlerts($warehouseId);
        $covered = $this->openRequisitionLines($shortages->pluck('item_id')->unique()->all());

        $rows = $shortages->map(function (object $shortage) use ($covered): array {
            $blocking = $this->blockingRequisition($covered, (int) $shortage->item_id, (int) $shortage->warehouse_id);

            return [
                'warehouse_id' => (int) $shortage->warehouse_id,
                'warehouse_code' => $shortage->warehouse_code,
                'warehouse_name' => $shortage->warehouse_name,
                'item_id' => (int) $shortage->item_id,
                'item_code' => $shortage->item_code,
                'item_name' => $shortage->item_name,
                'unit' => $shortage->unit,
                'qty' => (float) $shortage->qty,
                'reorder_point' => (float) $shortage->reorder_point,
                'min_stock' => (float) $shortage->min_stock,
                'threshold_source' => $shortage->threshold_source,
                'threshold_source_label' => $shortage->threshold_source_label,
                'shortage_qty' => (float) $shortage->shortage_qty,
                'suggested_qty' => (float) $shortage->suggested_qty,
                'reorder_qty' => $shortage->reorder_qty === null ? null : (float) $shortage->reorder_qty,
                'skipped' => $blocking !== null,
                // Kalimatnya menyebut KODE PR-nya. "Sudah ada di PR terbuka"
                // tanpa kode adalah kabar yang tidak bisa ditindaklanjuti siapa
                // pun — yang dicari orangnya adalah dokumen itu.
                'skipped_reason' => $blocking === null ? null : sprintf(
                    'Sudah diminta pada %s (%s)%s.',
                    $blocking['code'],
                    $blocking['status_label'],
                    $blocking['warehouse_id'] === null ? ' yang tidak menyebut gudang' : '',
                ),
                'skipped_requisition_code' => $blocking['code'] ?? null,
            ];
        })->values()->all();

        $proposable = array_values(array_filter($rows, fn (array $row) => ! $row['skipped']));

        return [
            'rows' => $rows,
            'warehouses' => $this->warehouseSummaries($proposable),
            'rules' => [
                'proposable' => count($proposable),
                'skipped' => count($rows) - count($proposable),
            ],
            'why_skipped' => 'Item yang sudah menjadi baris pada PR terbuka (draf, diajukan atau disetujui) '
                .'untuk gudang yang sama — atau pada PR yang tidak menyebut gudang sama sekali — dilewati, '
                .'supaya menjalankan usulan dua kali tidak menghasilkan dua permintaan untuk kekurangan yang '
                .'sama. PR yang ditolak, selesai atau dibatalkan tidak menahan apa pun.',
        ];
    }

    /**
     * Buat PR DRAF: satu per gudang, satu baris per item yang kurang.
     *
     * Satu per GUDANG karena PR punya satu warehouse_id, dan satu PR yang
     * memuat barang untuk tiga gudang adalah dokumen yang tidak bisa dikirim
     * ke mana pun.
     *
     * @return list<PurchaseRequisition>
     */
    public function createDraftRequisitions(?int $warehouseId, ?User $requester, ?string $neededDate = null): array
    {
        $proposal = $this->proposal($warehouseId);

        if ($proposal['warehouses'] === []) {
            return [];
        }

        return DB::transaction(function () use ($proposal, $requester, $neededDate): array {
            $created = [];

            foreach ($proposal['warehouses'] as $group) {
                $warehouse = Warehouse::withTrashed()->find($group['warehouse_id']);

                $created[] = $this->requisitions->create([
                    'warehouse_id' => $group['warehouse_id'],
                    // Proyeknya DITURUNKAN dari gudangnya, bukan ditebak: gudang
                    // site membawa project_id-nya sendiri, gudang pusat tidak
                    // punya satu pun dan barisnya tetap kosong.
                    'project_id' => $warehouse?->project_id,
                    'requested_by' => $requester?->id,
                    'needed_date' => $neededDate,
                    'purpose' => sprintf(
                        'Usulan dari aturan reorder: %d item di bawah titik pesan ulang di gudang %s per %s.',
                        count($group['items']),
                        $group['warehouse_name'],
                        Carbon::now()->format('d-m-Y'),
                    ),
                    'items' => array_map(fn (array $row): array => [
                        'item_id' => $row['item_id'],
                        // Barisnya membawa angkanya sendiri ke atas kertas PR:
                        // yang menandatangani PR ini harus bisa melihat DARI
                        // MANA jumlah itu datang tanpa membuka layar stok.
                        'description' => sprintf(
                            '%s — stok %s dari titik pesan ulang %s (%s)',
                            $row['item_name'],
                            $this->number($row['qty']),
                            $this->number($row['reorder_point']),
                            $row['threshold_source_label'],
                        ),
                        'qty' => $row['suggested_qty'],
                        'unit' => $row['unit'],
                        // Harga terakhir yang BENAR-BENAR dibayar untuk barang
                        // ini, dari kartu itemnya. Tidak ada taksiran yang
                        // dikarang di sini; item yang belum pernah dibeli
                        // membawa 0, dan 0 di kolom taksiran adalah "belum
                        // pernah dibeli", bukan "gratis".
                        'estimated_price' => (float) ($row['last_price'] ?? 0),
                    ], $group['items']),
                ]);
            }

            return $created;
        });
    }

    /**
     * Baris PR terbuka untuk item-item ini, dikelompokkan per item.
     *
     * Satu kueri untuk seluruh daftar, bukan satu per baris kekurangan: layar
     * gudang pusat dengan 200 item di bawah ambang akan menjalankan 200 kueri.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<array{code: string, status_label: string, warehouse_id: ?int}>>
     */
    private function openRequisitionLines(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = DB::table('prc_purchase_requisition_items as l')
            ->join('prc_purchase_requisitions as p', 'p.id', '=', 'l.purchase_requisition_id')
            ->whereNull('p.deleted_at')
            ->whereIn('p.status', array_map(fn (DocumentStatus $status) => $status->value, self::OPEN_STATUSES))
            ->whereIn('l.item_id', $itemIds)
            ->orderBy('p.id')
            ->get(['l.item_id', 'p.code', 'p.status', 'p.warehouse_id']);

        $byItem = [];

        foreach ($rows as $row) {
            $byItem[(int) $row->item_id][] = [
                'code' => $row->code,
                'status_label' => (DocumentStatus::tryFrom($row->status)?->label() ?? $row->status),
                'warehouse_id' => $row->warehouse_id === null ? null : (int) $row->warehouse_id,
            ];
        }

        return $byItem;
    }

    /**
     * @param  array<int, list<array{code: string, status_label: string, warehouse_id: ?int}>>  $covered
     * @return array{code: string, status_label: string, warehouse_id: ?int}|null
     */
    private function blockingRequisition(array $covered, int $itemId, int $warehouseId): ?array
    {
        foreach ($covered[$itemId] ?? [] as $line) {
            if ($line['warehouse_id'] === null || $line['warehouse_id'] === $warehouseId) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Kekurangan yang bisa diusulkan, dikelompokkan per gudang, dengan harga
     * terakhir tiap item ditempelkan dalam SATU kueri.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{warehouse_id: int, warehouse_code: string, warehouse_name: string, items: list<array<string, mixed>>}>
     */
    private function warehouseSummaries(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $prices = DB::table('inv_items')
            ->whereIn('id', array_column($rows, 'item_id'))
            ->pluck('last_price', 'id');

        $groups = [];

        foreach ($rows as $row) {
            $row['last_price'] = (float) ($prices[$row['item_id']] ?? 0);
            $key = $row['warehouse_id'];

            $groups[$key] ??= [
                'warehouse_id' => $row['warehouse_id'],
                'warehouse_code' => $row['warehouse_code'],
                'warehouse_name' => $row['warehouse_name'],
                'items' => [],
            ];

            $groups[$key]['items'][] = $row;
        }

        return array_values($groups);
    }

    /** Angka kuantitas untuk kalimat baris PR — koma desimal, tanpa nol ekor yang tak berarti. */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }
}
