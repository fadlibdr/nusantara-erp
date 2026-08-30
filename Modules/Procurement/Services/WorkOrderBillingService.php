<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Assets\Enums\RateBasis;
use Modules\Assets\Models\EquipmentLog;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\WorkOrder;
use Modules\Procurement\Models\WorkOrderBilling;
use Modules\Procurement\Models\WorkOrderItem;

/**
 * Tagihan per periode atas PPK (P5, deviasi 3.10) — kuantitas DITURUNKAN,
 * tidak pernah diketik:
 *
 *   per_jam        delta hour-meter DI DALAM periode, dijumlahkan PER SEGMEN
 *                  MOBILISASI: untuk tiap mobilisasi alat baris itu ke PROYEK
 *                  PPK ini, pembacaan terakhir minus pembacaan pertama yang
 *                  bertanggal di dalam [period_start, period_end] DAN di dalam
 *                  jendela mobilisasi itu; kuantitasnya jumlah delta
 *                  segmen-segmen. Jam yang tercatat pada mobilisasi ke proyek
 *                  LAIN tidak pernah ikut — alat yang ulang-alik antar proyek
 *                  di tengah periode hanya membawa pulang jam segmennya
 *                  sendiri (hoursInPeriod).
 *   per_bulan      kalender: bulan kalender utuh dalam periode (periode
 *                  wajib mulai tanggal 1 dan berakhir di akhir bulan).
 *   per_hari_8jam  kalender: hari inklusif kedua ujung periode.
 *
 * ATURAN BATAS per_jam — DALAM-PERIODE SAJA, dipin di sini dan diuji di
 * WorkOrderBillingTest: pembacaan 1.200,0 → 1.207,5 → 1.213,0 di dalam
 * periode = 13,0 jam, dan pembacaan 1.195,0 SEBELUM periode tidak mengubah
 * apa pun. Alternatifnya ("pembacaan pertama dalam periode minus pembacaan
 * terakhir sebelum periode") membocorkan jam yang berjalan SEBELUM periode ke
 * dalam tagihan periode ini — 1.213,0 − 1.195,0 = 18 jam, 5 di antaranya
 * bukan milik periode. Konsekuensi jujur dari aturan ini: jam yang berjalan
 * di antara pembacaan terakhir pra-periode dan pembacaan pertama dalam
 * periode tidak tertagih di mana pun — itu jam yang tidak terukur oleh
 * register, dan menagih jam yang tidak terukur berarti mengarang angka.
 * Situs yang membaca meter di batas periode tidak kehilangan apa-apa.
 *
 * Bersama periode yang saling lepas (guard tumpang-tindih di bawah), aturan
 * per-segmen, dan guard berjam lintas-PPK (assertHoursFreeAcrossWorkOrders),
 * setiap pasangan pembacaan berurutan jatuh di paling banyak SATU tagihan —
 * argumen anti-tagih-ganda lengkapnya di migrasi 000869.
 *
 * Basis kalender BOLEH menagih di muka (sewa dibayar di muka lazim; sewa
 * terhutang untuk periodenya, dipakai atau tidak); per_jam dengan sendirinya
 * hanya bisa menagih jam yang sudah tercatat.
 */
class WorkOrderBillingService
{
    private const QTY_TOLERANCE = 0.0005;

    public function create(WorkOrder $workOrder, array $data): WorkOrderBilling
    {
        $periodStart = Carbon::parse($data['period_start'])->startOfDay();
        $periodEnd = Carbon::parse($data['period_end'])->startOfDay();

        if ($periodEnd->lt($periodStart)) {
            throw new LogicException(
                "Periode tagihan terbalik: {$periodStart->toDateString()} sampai {$periodEnd->toDateString()}."
            );
        }

        return DB::transaction(function () use ($workOrder, $data, $periodStart, $periodEnd): WorkOrderBilling {
            // Baca-ulang terkunci — titik serialisasi anti-tagih-ganda: dua
            // penyusun billing yang balapan atas PPK yang sama antre di baris
            // ini, sehingga guard tumpang-tindih di bawah selalu melihat
            // billing yang menang lebih dulu.
            /** @var WorkOrder $workOrder */
            $workOrder = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($workOrder->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "PPK {$workOrder->code} berstatus {$workOrder->status->value}; tagihan periode hanya "
                    .'dapat dibuat atas PPK yang sudah disetujui.'
                );
            }

            $this->assertPeriodFree($workOrder, $periodStart, $periodEnd);
            $this->lockPerJamAssets($workOrder);

            $billing = new WorkOrderBilling([
                'work_order_id' => $workOrder->id,
                'billing_no' => (int) $workOrder->billings()->withTrashed()->max('billing_no') + 1,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);
            $billing->save(); // HasDocumentNumber mengisi kode PPKB

            $total = 0.0;
            $linesWritten = 0;

            foreach ($workOrder->items as $item) {
                [$qty, $meterStart, $meterEnd] = $this->quantityFor($workOrder, $item, $periodStart, $periodEnd);

                if ($qty <= 0.0) {
                    continue; // tidak ada kuantitas terukur pada baris ini periode ini
                }

                if ($item->rate_basis === RateBasis::PerJam) {
                    $this->assertHoursFreeAcrossWorkOrders($workOrder, $item, $periodStart, $periodEnd);
                }

                $this->assertWithinCap($item, $qty, $billing->id);

                $amount = round($qty * (float) $item->rate, 2);

                $billing->lines()->create([
                    'work_order_item_id' => $item->id,
                    'qty' => $qty,
                    'amount' => $amount,
                    'meter_start' => $meterStart,
                    'meter_end' => $meterEnd,
                ]);

                $total += $amount;
                $linesWritten++;
            }

            if ($linesWritten === 0) {
                throw new LogicException(
                    "Pada periode {$periodStart->toDateString()} s.d. {$periodEnd->toDateString()} tidak ada "
                    .'kuantitas yang dapat ditagih: baris per_jam butuh minimal dua pembacaan hour-meter di '
                    .'dalam periode, dan tidak ada baris kalender yang menagih. Tagihan kosong tidak dibuat.'
                );
            }

            $billing->forceFill(['total_amount' => round($total, 2)])->save();

            return $billing->load('lines.workOrderItem', 'workOrder');
        });
    }

    /**
     * Hapus billing yang belum sampai ke uang: selama belum ada tagihan AP
     * hidup yang menunjuknya. Billing yang tagihannya DIBATALKAN boleh
     * dihapus — pembatalan membalik jurnal, dan menghapus billing-nya
     * membebaskan periodenya untuk disusun ulang.
     */
    public function delete(WorkOrderBilling $billing): void
    {
        DB::transaction(function () use ($billing): void {
            /** @var WorkOrderBilling $billing */
            $billing = WorkOrderBilling::query()->whereKey($billing->id)->lockForUpdate()->firstOrFail();

            $apBill = $this->liveApBill($billing);

            if ($apBill !== null) {
                throw new LogicException(
                    "Tagihan periode {$billing->code} sudah ditagihkan ke AP lewat tagihan {$apBill->code}; "
                    .'batalkan tagihan AP itu lebih dulu sebelum menghapus billing ini.'
                );
            }

            $billing->lines()->delete();
            $billing->delete();
        });
    }

    /**
     * Rekap Tagihan Alat (deviasi 3.10): billing per periode per PPK per
     * vendor di dalam jendela, dengan tagihan AP-nya BILA ADA — kolom
     * ap_bill_code jujur kosong (null) selama belum ada tagihan hidup,
     * bukan "tercatat".
     */
    public function recap(string $from, string $to, ?int $vendorId = null, ?int $projectId = null): array
    {
        $windowStart = Carbon::parse($from)->startOfDay();
        $windowEnd = Carbon::parse($to)->startOfDay();

        $billings = WorkOrderBilling::query()
            ->with('workOrder.vendor', 'lines.workOrderItem')
            ->whereDate('period_start', '<=', $windowEnd->toDateString())
            ->whereDate('period_end', '>=', $windowStart->toDateString())
            ->when($vendorId !== null, fn ($query) => $query
                ->whereHas('workOrder', fn ($wo) => $wo->where('vendor_id', $vendorId)))
            ->when($projectId !== null, fn ($query) => $query
                ->whereHas('workOrder', fn ($wo) => $wo->where('project_id', $projectId)))
            ->orderBy('period_start')
            ->get();

        $rows = [];
        $byVendor = [];

        foreach ($billings as $billing) {
            $apBill = $this->liveApBill($billing);
            $vendorName = $billing->workOrder?->vendor?->name;

            $rows[] = [
                'billing_id' => $billing->id,
                'billing_code' => $billing->code,
                'work_order_code' => $billing->workOrder?->code,
                'vendor_id' => $billing->workOrder?->vendor_id,
                'vendor_name' => $vendorName,
                'project_id' => $billing->workOrder?->project_id,
                'period_start' => $billing->period_start->toDateString(),
                'period_end' => $billing->period_end->toDateString(),
                'total_amount' => (float) $billing->total_amount,
                'ap_bill_code' => $apBill?->code,
                'ap_bill_status' => $apBill?->status,
                'lines' => $billing->lines->map(fn ($line) => [
                    'description' => $line->workOrderItem?->description,
                    'rate_basis' => $line->workOrderItem?->rate_basis?->value,
                    'qty' => (float) $line->qty,
                    'rate' => (float) ($line->workOrderItem?->rate ?? 0),
                    'amount' => (float) $line->amount,
                    'meter_start' => $line->meter_start !== null ? (float) $line->meter_start : null,
                    'meter_end' => $line->meter_end !== null ? (float) $line->meter_end : null,
                ])->values()->all(),
            ];

            $key = $billing->workOrder?->vendor_id ?? 0;
            $byVendor[$key] ??= ['vendor_id' => $key, 'vendor_name' => $vendorName, 'total_amount' => 0.0];
            $byVendor[$key]['total_amount'] = round($byVendor[$key]['total_amount'] + (float) $billing->total_amount, 2);
        }

        return [
            'period' => ['from' => $windowStart->toDateString(), 'to' => $windowEnd->toDateString()],
            'rows' => $rows,
            'summary_by_vendor' => array_values($byVendor),
            'totals' => [
                'total_amount' => round(array_sum(array_column($rows, 'total_amount')), 2),
            ],
        ];
    }

    // ---------------------------------------------------------------- internals

    /**
     * @return array{0: float, 1: ?float, 2: ?float} [qty, meter_start, meter_end]
     */
    private function quantityFor(WorkOrder $workOrder, WorkOrderItem $item, Carbon $periodStart, Carbon $periodEnd): array
    {
        return match ($item->rate_basis) {
            RateBasis::PerJam => $this->hoursInPeriod($workOrder, $item, $periodStart, $periodEnd),
            RateBasis::PerBulan => [$this->wholeCalendarMonths($item, $periodStart, $periodEnd), null, null],
            RateBasis::PerHari8Jam => [(float) ((int) $periodStart->diffInDays($periodEnd) + 1), null, null],
        };
    }

    /**
     * Delta hour-meter DALAM periode, dijumlahkan PER SEGMEN MOBILISASI alat
     * baris ini ke PROYEK PPK ini: di dalam tiap mobilisasi, pembacaan
     * terakhir minus pembacaan pertama yang jatuh di dalam periode DAN di
     * dalam jendela mobilisasi itu (aturan batas dalam-periode di docblock
     * kelas berlaku per segmen). Last-minus-first LINTAS mobilisasi dulu
     * membocorkan jam: alat yang ditarik ke proyek lain di tengah periode
     * lalu kembali membawa serta delta yang berjalan DI proyek lain — dan
     * PPK proyek sana menagihnya lagi. Jam antar segmen (di proyek lain atau
     * di perjalanan) bukan milik tagihan ini; jam segmen proyek lain milik
     * PPK proyek itu. Lewat mobilisasi HIDUP saja, sikap yang sama dengan
     * Asset::equipmentLogs.
     *
     * Snapshot meter_start/meter_end hanya diisi bila TEPAT SATU segmen
     * terukur menyumbang: "1.200,0 → 1.213,0 = 13,0 jam" hanya benar tanpa
     * selingan. Lebih dari satu segmen membuat pasangan angka tunggal mana
     * pun berbohong tentang qty (ujung-ke-ujung menyiratkan delta yang bukan
     * kuantitasnya), jadi keduanya jujur kosong — pola ap_bill_code di rekap.
     *
     * @return array{0: float, 1: ?float, 2: ?float}
     */
    private function hoursInPeriod(WorkOrder $workOrder, WorkOrderItem $item, Carbon $periodStart, Carbon $periodEnd): array
    {
        $segments = EquipmentLog::query()
            ->with('deployment')
            ->whereHas('deployment', fn ($query) => $query
                ->where('asset_id', $item->asset_id)
                ->where('project_id', $workOrder->project_id))
            ->whereNotNull('hour_meter')
            ->whereDate('log_date', '>=', $periodStart->toDateString())
            ->whereDate('log_date', '<=', $periodEnd->toDateString())
            ->orderBy('log_date')->orderBy('id')
            ->get()
            // Sabuk pengaman di atas guard register (EquipmentLogService sudah
            // menolak log di luar jendela mobilisasinya): hanya pembacaan yang
            // bertanggal saat alat ADA di lokasi yang membentuk segmen.
            ->filter(fn (EquipmentLog $log) => $log->deployment !== null
                && $log->deployment->wasOnSiteOn($log->log_date))
            ->groupBy('deployment_id');

        $total = 0.0;
        $measuredSegments = 0;
        $meterStart = null;
        $meterEnd = null;

        foreach ($segments as $readings) {
            if ($readings->count() < 2) {
                continue; // segmen tanpa delta terukur — bukan nol jam terpakai, melainkan tak terukur
            }

            $first = (float) $readings->first()->hour_meter;
            $last = (float) $readings->last()->hour_meter;
            $delta = round($last - $first, 3);

            if ($delta < 0) {
                // Register per mobilisasi monoton (EquipmentLogService);
                // angka mundur di dalam satu segmen berarti data yang tidak
                // bisa ditagih.
                throw new LogicException(sprintf(
                    'Pembacaan hour-meter baris "%s" mundur di dalam periode pada mobilisasi %s (%s → %s); '
                    .'periksa registernya sebelum menagih.',
                    $item->description,
                    $readings->first()->deployment?->code,
                    number_format($first, 1, ',', '.'),
                    number_format($last, 1, ',', '.'),
                ));
            }

            $total = round($total + $delta, 3);
            $measuredSegments++;
            $meterStart = $first;
            $meterEnd = $last;
        }

        if ($measuredSegments !== 1) {
            $meterStart = null;
            $meterEnd = null;
        }

        return [$total, $meterStart, $meterEnd];
    }

    /**
     * per_bulan menagih bulan kalender utuh: periode wajib mulai tanggal 1
     * dan berakhir pada akhir bulan. Prorata harian atas tarif bulanan adalah
     * angka yang tidak pernah disepakati siapa pun — kalau sewa berhenti di
     * tengah bulan, itulah negosiasi, bukan pembagian.
     */
    private function wholeCalendarMonths(WorkOrderItem $item, Carbon $periodStart, Carbon $periodEnd): float
    {
        if ($periodStart->day !== 1 || ! $periodEnd->isSameDay($periodEnd->copy()->endOfMonth())) {
            throw new LogicException(sprintf(
                'Baris per_bulan "%s" menagih bulan kalender utuh; periode %s s.d. %s bukan bulan '
                .'kalender utuh (mulai tanggal 1, berakhir di akhir bulan).',
                $item->description,
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
            ));
        }

        return (float) ((int) $periodStart->diffInMonths($periodEnd->copy()->addDay()));
    }

    /**
     * Tumpang-tindih dua arah ditolak: existing.start <= new.end AND
     * existing.end >= new.start menangkap periode identik, periode baru yang
     * menjorok ke dalam yang lama, DAN periode baru yang membungkus yang
     * lama. Hanya billing HIDUP yang menahan — billing yang dihapus (setelah
     * tagihan AP-nya batal) membebaskan periodenya.
     */
    private function assertPeriodFree(WorkOrder $workOrder, Carbon $periodStart, Carbon $periodEnd): void
    {
        $clash = $workOrder->billings()
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            ->first();

        if ($clash !== null) {
            throw new LogicException(sprintf(
                'Periode %s s.d. %s tumpang-tindih dengan tagihan %s (%s s.d. %s) pada PPK %s — '
                .'satu periode hanya ditagih sekali.',
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
                $clash->code,
                $clash->period_start->toDateString(),
                $clash->period_end->toDateString(),
                $workOrder->code,
            ));
        }
    }

    /**
     * Titik serialisasi LINTAS-PPK untuk jam alat: lock baris ast_assets
     * setiap alat baris per_jam, diambil terurut naik pada id supaya dua
     * penyusun yang menyentuh himpunan alat yang sama tidak saling menunggu
     * bersilangan. Lock baris PPK di create() hanya menserialisasi penyusun
     * SATU PPK; dua PPK berbeda atas alat yang sama butuh titik temu yang
     * sama-sama mereka lewati — baris alatnya — supaya
     * assertHoursFreeAcrossWorkOrders selalu melihat billing pemenang yang
     * lebih dulu, bukan potret basi.
     */
    private function lockPerJamAssets(WorkOrder $workOrder): void
    {
        $assetIds = $workOrder->items
            ->filter(fn (WorkOrderItem $item) => $item->rate_basis === RateBasis::PerJam && $item->asset_id !== null)
            ->pluck('asset_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($assetIds === []) {
            return;
        }

        DB::table('ast_assets')->whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * Guard tagih-ganda LINTAS PPK (kasus PPK lama tertinggal approved setelah
     * renegosiasi tarif melahirkan PPK baru atas alat+proyek yang sama).
     *
     * PREDIKAT YANG DITOLAK — irisan periode berjam yang SUDAH TERTAGIH, bukan
     * sekadar adanya PPK lain: ada billing HIDUP milik PPK LAIN (mana pun
     * statusnya kini — jam yang telanjur tertagih lewat PPK yang kemudian
     * ditutup tetap tertagih) yang (a) memuat BARIS berbasis per_jam atas
     * asset_id DAN project_id yang sama, dan (b) periodenya beririsan dengan
     * periode billing yang sedang disusun. Dua PPK yang menagih paruh periode
     * BERBEDA lolos — periodenya saling lepas. Billing PPK lain yang beririsan
     * tetapi TIDAK menulis baris jam alat ini juga lolos: tidak ada jam yang
     * tertagih di sana, dan menolaknya membuat jam terukur tidak tertagih di
     * mana pun. Konservatif pada irisan parsial: irisan periode dengan baris
     * berjam ditolak utuh walau pembacaan di irisan itu belum tentu terhitung
     * dua kali — memilah jam per tanggal di dalam irisan berarti menebak
     * register; menolak dengan menyebut PPK lawannya membiarkan manusia
     * memilih periode yang saling lepas.
     */
    private function assertHoursFreeAcrossWorkOrders(WorkOrder $workOrder, WorkOrderItem $item, Carbon $periodStart, Carbon $periodEnd): void
    {
        $clash = DB::table('prc_work_order_billing_lines as line')
            ->join('prc_work_order_billings as billing', 'billing.id', '=', 'line.work_order_billing_id')
            ->join('prc_work_order_items as item', 'item.id', '=', 'line.work_order_item_id')
            ->join('prc_work_orders as wo', 'wo.id', '=', 'billing.work_order_id')
            ->whereNull('billing.deleted_at')
            ->whereNull('wo.deleted_at')
            ->where('wo.id', '!=', $workOrder->id)
            ->where('wo.project_id', $workOrder->project_id)
            ->where('item.rate_basis', RateBasis::PerJam->value)
            ->where('item.asset_id', $item->asset_id)
            ->whereDate('billing.period_start', '<=', $periodEnd->toDateString())
            ->whereDate('billing.period_end', '>=', $periodStart->toDateString())
            ->orderBy('billing.id')
            ->first(['wo.code as work_order_code', 'billing.code as billing_code', 'billing.period_start', 'billing.period_end']);

        if ($clash !== null) {
            throw new LogicException(sprintf(
                'Jam alat baris "%s" pada periode %s s.d. %s sudah tertagih lewat PPK lain: tagihan %s '
                .'milik PPK %s menagih jam alat yang sama di proyek yang sama untuk %s s.d. %s — satu jam '
                .'meter jatuh di maksimal satu tagihan. Pilih periode yang saling lepas, atau bereskan PPK '
                .'yang seharusnya tidak lagi menagih.',
                $item->description,
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
                $clash->billing_code,
                $clash->work_order_code,
                Carbon::parse($clash->period_start)->toDateString(),
                Carbon::parse($clash->period_end)->toDateString(),
            ));
        }
    }

    /**
     * Plafon qty_periods per baris, roll-forward pada id baris PPK — kunci
     * yang stabil dengan argumen migrasi 000868. Menolak, bukan memangkas:
     * memangkas jam terukur menjadi sisa plafon berarti menagih angka yang
     * bukan angka register; kelebihan komitmen diselesaikan dengan PPK baru
     * atau perubahan kontrak, bukan diam-diam.
     */
    private function assertWithinCap(WorkOrderItem $item, float $qty, int $exceptBillingId): void
    {
        $billed = round((float) DB::table('prc_work_order_billing_lines as line')
            ->join('prc_work_order_billings as billing', 'billing.id', '=', 'line.work_order_billing_id')
            ->whereNull('billing.deleted_at')
            ->where('billing.id', '!=', $exceptBillingId)
            ->where('line.work_order_item_id', $item->id)
            ->sum('line.qty'), 3);

        $remaining = round((float) $item->qty_periods - $billed, 3);

        if ($qty > $remaining + self::QTY_TOLERANCE) {
            throw new LogicException(sprintf(
                'Kuantitas %s %s pada baris "%s" melebihi sisa plafon PPK %s %s '
                .'(plafon %s, sudah tertagih %s).',
                rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ','),
                $item->rate_basis->unit(),
                $item->description,
                rtrim(rtrim(number_format($remaining, 3, ',', '.'), '0'), ','),
                $item->rate_basis->unit(),
                rtrim(rtrim(number_format((float) $item->qty_periods, 3, ',', '.'), '0'), ','),
                rtrim(rtrim(number_format($billed, 3, ',', '.'), '0'), ','),
            ));
        }
    }

    /**
     * Tagihan AP hidup (non-cancelled) atas billing ini, dibaca polos dari
     * tabel Finance dengan penjagaan hasTable — pola LaborContractService::
     * boqLine untuk baca lintas-modul tanpa menyeret service Finance ke sini.
     */
    private function liveApBill(WorkOrderBilling $billing): ?object
    {
        if (! Schema::hasTable('fin_ap_bills')) {
            return null;
        }

        return DB::table('fin_ap_bills')
            ->where('work_order_billing_id', $billing->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', DocumentStatus::Cancelled->value)
            ->orderByDesc('id')
            ->first(['id', 'code', 'status', 'total_payable']);
    }
}
