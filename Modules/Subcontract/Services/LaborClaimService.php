<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Models\Kasbon;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Models\LaborContract;
use Modules\Subcontract\Models\LaborContractItem;

/**
 * Opname mandor (P4) — matematika per SP3:
 *
 *   gross        = Σ (qty_this × unit_rate baris kontrak)
 *   ppn          = gross × ppn_rate/100      (0 kecuali mandor PKP)
 *   pph          = gross × pph_rate/100      (PPh final UMKM atas gross penuh)
 *   kasbon       = potongan kasbon (≤ sisa kasbon, ≤ gross + ppn − pph)
 *   net_payable  = gross + ppn − pph − kasbon
 *
 * PLAFON ADALAH INTI SERVICE INI, per baris dan dalam VOLUME:
 *
 *   Σ qty_this (klaim APPROVED) + qty_this klaim ini  ≤  qty baris SP3
 *
 * Roll-forward dikunci pada labor_contract_item_id — id baris. Pelajaran P3
 * (MeasurementService: id baris BOQ mati saat copyVersion, riwayat lenyap,
 * volume tertagih dua kali) DITERAPKAN DENGAN JAWABAN BERBEDA di sini, dan
 * alasannya tertulis panjang di migrasi scm_labor_contracts: baris SP3 tidak
 * punya jalur regenerasi apa pun setelah approved, jadi id barisnya justru
 * kunci yang paling jujur.
 *
 * Guard basi berjalan ulang pada data HIDUP saat approve (pola ClaimService
 * subkon): qty_prev yang tersimpan saat opname disusun harus masih sama
 * dengan jumlah approved hidupnya, dan sisa kasbonnya masih mencukupi —
 * kalau tidak, klaim diedit dan diajukan ulang, bukan disetujui basi.
 *
 * KASBON: klaim hanya MEMERIKSA dan MENCATAT niat potong. Fakta akuntansi
 * potongannya terjadi saat tagihan AP disetujui — ApBillService mengkredit
 * 1-1370 dan KasbonService::offsetAgainstWageBill (seam terdokumentasi
 * Finance) mencatat offset pada kasbonnya. Modul ini MEMBACA fin_kasbons
 * lewat model Kasbon (lintas-modul baca boleh) dan tidak pernah menulisnya.
 */
class LaborClaimService
{
    private const QTY_TOLERANCE = 0.0005;

    public function createClaim(array $data): LaborClaim
    {
        return DB::transaction(function () use ($data): LaborClaim {
            $items = Arr::pull($data, 'items', []);

            /** @var LaborContract $contract */
            $contract = LaborContract::query()
                ->whereKey($data['labor_contract_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($contract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "SP3 {$contract->code} berstatus {$contract->status->value}; opname mandor hanya "
                    .'dapat dibuat atas SP3 yang sudah disetujui.'
                );
            }

            $claim = new LaborClaim([
                'labor_contract_id' => $contract->id,
                'claim_no' => $this->nextClaimNo($contract),
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'kasbon_id' => $data['kasbon_id'] ?? null,
                'kasbon_deduction_amount' => round((float) ($data['kasbon_deduction_amount'] ?? 0), 2),
                'notes' => $data['notes'] ?? null,
            ]);
            $claim->status = DocumentStatus::Draft;
            $claim->save(); // HasDocumentNumber mengisi kode OPM

            $this->syncItems($claim, $contract, $items);
            $this->recalcTotals($claim, $contract);

            return $claim->load('items.laborContractItem', 'laborContract');
        });
    }

    public function updateClaim(LaborClaim $claim, array $data): LaborClaim
    {
        return DB::transaction(function () use ($claim, $data): LaborClaim {
            // Editability diputuskan pada BACA-ULANG di dalam transaksi, bukan
            // pada instance route binding — alasan yang sama dengan
            // ClaimService::updateClaim (approve yang mendarat di antaranya).
            /** @var LaborClaim $claim */
            $claim = LaborClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($claim);

            $items = Arr::pull($data, 'items');

            $claim->fill(Arr::only($data, [
                'period_start', 'period_end', 'notes', 'kasbon_id', 'kasbon_deduction_amount',
            ]))->save();

            $contract = $claim->laborContract;

            if (is_array($items)) {
                $this->syncItems($claim, $contract, $items); // baris diganti seutuhnya
            }

            $this->recalcTotals($claim, $contract);

            return $claim->load('items.laborContractItem', 'laborContract');
        });
    }

    /**
     * Semua guard berjalan ulang pada data HIDUP di dalam transaksi terkunci:
     * plafon volume per baris, kesegaran qty_prev, dan sisa kasbon.
     */
    public function approve(LaborClaim $claim, User $by, ?string $note = null): LaborClaim
    {
        return DB::transaction(function () use ($claim, $by, $note): LaborClaim {
            /** @var LaborClaim $claim */
            $claim = LaborClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            /** @var LaborContract $contract */
            $contract = LaborContract::query()
                ->whereKey($claim->labor_contract_id)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($claim->items as $line) {
                /** @var LaborContractItem $item */
                $item = LaborContractItem::query()
                    ->whereKey($line->labor_contract_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $livePrev = $this->approvedQtyFor($contract, (int) $item->id, $claim->id);

                // Volume approved bergeser sejak opname ini disusun (opname
                // lain disetujui di antaranya): sisa yang tercetak di klaim
                // sudah kedaluwarsa — susun ulang, jangan setujui yang basi.
                if (abs($livePrev - (float) $line->qty_prev) > self::QTY_TOLERANCE) {
                    throw new LogicException(
                        "Volume approved baris \"{$item->description}\" kini {$livePrev} {$item->unit}, "
                        ."bukan {$line->qty_prev} seperti saat opname {$claim->code} disusun; "
                        .'ubah dan ajukan ulang opname ini.'
                    );
                }

                $this->assertWithinItemQty($item, $livePrev, (float) $line->qty_this);
            }

            // Sisa kasbon diperiksa ulang hidup-hidup: tagihan upah lain yang
            // disetujui di antaranya bisa sudah memotong kasbon yang sama.
            $this->assertKasbonDeductible(
                $claim->kasbon_id !== null ? (int) $claim->kasbon_id : null,
                round((float) $claim->kasbon_deduction_amount, 2),
                $contract,
                (float) $claim->gross_amount + (float) $claim->ppn_amount - (float) $claim->pph_amount,
            );

            $claim->approve($by, $note); // Approvable: submitted -> approved (maker-checker di dalamnya)

            return $claim->load('items.laborContractItem', 'laborContract');
        });
    }

    public function delete(LaborClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
            /** @var LaborClaim $claim */
            $claim = LaborClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($claim);

            $claim->items()->delete();
            $claim->delete();
        });
    }

    // ---------------------------------------------------------------- internals

    private function syncItems(LaborClaim $claim, LaborContract $contract, array $items): void
    {
        $claim->items()->delete();

        foreach ($items as $input) {
            /** @var ?LaborContractItem $item */
            $item = $contract->items()
                ->whereKey($input['labor_contract_item_id'])
                ->first();

            if ($item === null) {
                throw new LogicException(
                    "Baris {$input['labor_contract_item_id']} bukan milik SP3 {$contract->code}."
                );
            }

            $qtyThis = round((float) $input['qty_this'], 3);

            if ($qtyThis <= 0) {
                throw new LogicException(
                    "Volume periode ini pada baris \"{$item->description}\" harus lebih dari nol."
                );
            }

            $prev = $this->approvedQtyFor($contract, (int) $item->id, $claim->id);

            $this->assertWithinItemQty($item, $prev, $qtyThis);

            $claim->items()->create([
                'labor_contract_item_id' => $item->id,
                'qty_prev' => $prev,
                'qty_this' => $qtyThis,
                'amount' => round($qtyThis * (float) $item->unit_rate, 2),
            ]);
        }
    }

    private function recalcTotals(LaborClaim $claim, LaborContract $contract): void
    {
        $gross = round((float) $claim->items()->sum('amount'), 2);
        $ppn = round($gross * (float) $contract->ppn_rate / 100, 2);
        // PPh final UMKM atas gross PENUH — potongan kasbon adalah cara
        // membayar, bukan pengurang penghasilan bruto si mandor.
        $pph = round($gross * (float) $contract->pph_rate / 100, 2);
        $deduction = round((float) $claim->kasbon_deduction_amount, 2);

        $this->assertKasbonDeductible(
            $claim->kasbon_id !== null ? (int) $claim->kasbon_id : null,
            $deduction,
            $contract,
            round($gross + $ppn - $pph, 2),
        );

        $claim->forceFill([
            'gross_amount' => $gross,
            'ppn_amount' => $ppn,
            'pph_amount' => $pph,
            'net_payable' => round($gross + $ppn - $pph - $deduction, 2),
        ])->save();
    }

    /**
     * Σ qty_this baris ini pada klaim APPROVED lain — dikunci pada id baris
     * kontrak (aman DI SINI: lihat docblock kelas dan migrasi
     * scm_labor_contracts untuk mengapa ini bukan footgun P3).
     */
    private function approvedQtyFor(LaborContract $contract, int $contractItemId, ?int $exceptClaimId): float
    {
        return round((float) DB::table('scm_labor_claim_items as line')
            ->join('scm_labor_claims as claim', 'claim.id', '=', 'line.labor_claim_id')
            ->where('claim.labor_contract_id', $contract->id)
            ->whereNull('claim.deleted_at')
            ->where('claim.status', DocumentStatus::Approved->value)
            ->when($exceptClaimId !== null, fn ($query) => $query->where('claim.id', '!=', $exceptClaimId))
            ->where('line.labor_contract_item_id', $contractItemId)
            ->sum('line.qty_this'), 3);
    }

    private function assertWithinItemQty(LaborContractItem $item, float $prev, float $qtyThis): void
    {
        $sisa = round((float) $item->qty - $prev, 3);

        if ($qtyThis > $sisa + self::QTY_TOLERANCE) {
            throw new LogicException(
                "Volume {$qtyThis} {$item->unit} pada baris \"{$item->description}\" melebihi sisa "
                ."SP3 {$sisa} {$item->unit} (qty kontrak {$item->qty}, sudah di-opname {$prev})."
            );
        }
    }

    /**
     * Empat fakta yang membuat sebuah potongan kasbon boleh dicatat — dan
     * pesan 422 yang menyebut fakta mana yang gagal:
     *
     *   1. potongan > 0 harus menunjuk kasbonnya;
     *   2. kasbon berstatus ISSUED (draft belum cair, settled sudah selesai);
     *   3. kasbon milik PROYEK yang sama dengan SP3-nya — uang muka site A
     *      tidak dipulihkan dari upah proyek B; keputusan P4 yang
     *      didokumentasikan (roadmap hanya berkata "tautan fin_kasbons");
     *   4. potongan ≤ sisa kasbon DAN ≤ yang terbayarkan (gross+ppn−pph) —
     *      memotong lebih dari upahnya berarti net minus, tagihan yang tak
     *      bisa dibayar.
     */
    private function assertKasbonDeductible(
        ?int $kasbonId,
        float $deduction,
        LaborContract $contract,
        float $payableBeforeDeduction,
    ): void {
        if ($deduction < 0) {
            throw new LogicException('Potongan kasbon tidak boleh negatif.');
        }

        if ($deduction === 0.0) {
            return;
        }

        if ($kasbonId === null) {
            throw new LogicException('Potongan kasbon harus menunjuk kasbon yang dipotong; pilih kasbonnya.');
        }

        /** @var ?Kasbon $kasbon */
        $kasbon = Kasbon::query()->find($kasbonId);

        if ($kasbon === null) {
            throw new LogicException('Kasbon yang dipilih tidak ditemukan.');
        }

        if ($kasbon->status !== KasbonStatus::Issued) {
            throw new LogicException(
                "Kasbon {$kasbon->code} berstatus {$kasbon->status->value}; hanya kasbon yang sudah "
                .'cair dan belum diselesaikan yang dapat dipotong dari upah mandor.'
            );
        }

        if ($kasbon->project_id !== null && (int) $kasbon->project_id !== (int) $contract->project_id) {
            throw new LogicException(
                "Kasbon {$kasbon->code} milik proyek lain; potongan upah hanya untuk kasbon "
                .'proyek SP3 ini.'
            );
        }

        $outstanding = $kasbon->outstandingAmount();

        if ($deduction > $outstanding + 0.01) {
            throw new LogicException(
                "Potongan kasbon {$deduction} melebihi sisa kasbon {$kasbon->code} ({$outstanding})."
            );
        }

        if ($deduction > $payableBeforeDeduction + 0.01) {
            throw new LogicException(
                "Potongan kasbon {$deduction} melebihi upah yang terbayarkan pada opname ini "
                ."({$payableBeforeDeduction}); netto tidak boleh minus — sisanya dipotong pada "
                .'opname berikutnya.'
            );
        }
    }

    private function assertEditable(LaborClaim $claim): void
    {
        if (! $claim->status->isEditable()) {
            throw new LogicException(
                "Opname mandor {$claim->code} berstatus {$claim->status->value} dan tidak dapat diubah lagi."
            );
        }
    }

    private function nextClaimNo(LaborContract $contract): int
    {
        return (int) $contract->claims()->withTrashed()->max('claim_no') + 1;
    }
}
