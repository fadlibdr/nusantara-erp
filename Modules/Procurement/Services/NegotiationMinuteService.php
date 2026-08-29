<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Procurement\Models\NegotiationMinute;
use Modules\Procurement\Models\Rfq;

/**
 * Berita Acara Negosiasi (BAN) — P2, DAN 31.
 *
 * Risalah pertemuan negosiasi harga: siapa hadir, harga awal → harga nego per
 * baris. Menjadi prasyarat kriteria #4 — AwardDecisionService menolak award
 * yang nilainya berbeda dari penawaran terakhir bila BAN untuk (RFQ, vendor)
 * belum ada.
 */
class NegotiationMinuteService
{
    public function create(array $data): NegotiationMinute
    {
        return DB::transaction(function () use ($data): NegotiationMinute {
            $items = Arr::pull($data, 'items', []);

            $rfq = Rfq::query()->findOrFail((int) $data['rfq_id']);
            $this->assertInvited($rfq, (int) $data['vendor_id']);

            $minute = new NegotiationMinute(Arr::except($data, ['code']));
            $minute->save(); // HasDocumentNumber mengisi kode BAN

            $this->syncItems($minute, $items);

            return $minute->load('items', 'vendor', 'rfq');
        });
    }

    public function update(NegotiationMinute $minute, array $data): NegotiationMinute
    {
        return DB::transaction(function () use ($minute, $data): NegotiationMinute {
            $items = Arr::pull($data, 'items');

            $minute->fill(Arr::except($data, ['code', 'rfq_id', 'vendor_id']));
            $minute->save();

            if (is_array($items)) {
                $this->syncItems($minute, $items);
            }

            return $minute->load('items', 'vendor', 'rfq');
        });
    }

    public function delete(NegotiationMinute $minute): void
    {
        $minute->delete();
    }

    private function assertInvited(Rfq $rfq, int $vendorId): void
    {
        if (! $rfq->vendors()->where('vendor_id', $vendorId)->exists()) {
            throw new LogicException(
                "Vendor #{$vendorId} tidak diundang pada RFQ {$rfq->code}; negosiasi hanya dicatat untuk vendor yang diajak banding."
            );
        }
    }

    private function syncItems(NegotiationMinute $minute, array $items): void
    {
        $minute->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $minute->items()->create([
                'line_no' => ++$lineNo,
                'rfq_item_id' => $item['rfq_item_id'] ?? null,
                'description' => $item['description'],
                'qty' => isset($item['qty']) ? round((float) $item['qty'], 3) : null,
                'unit' => $item['unit'] ?? null,
                'harga_awal' => round((float) ($item['harga_awal'] ?? 0), 2),
                'harga_nego' => round((float) ($item['harga_nego'] ?? 0), 2),
            ]);
        }
    }
}
