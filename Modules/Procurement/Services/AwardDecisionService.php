<?php

namespace Modules\Procurement\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Procurement\Models\AwardDecision;
use Modules\Procurement\Models\NegotiationMinute;
use Modules\Procurement\Models\Rfq;

/**
 * Keputusan Pemenang / Award Decision (AWD) — P2, kriteria #4.
 *
 * Dua paruh kriteria #4 hidup di sini dan di PoService/SubcontractService:
 *
 *   1. AWARD DENGAN HARGA BERUBAH WAJIB PUNYA BA NEGOSIASI. Bila awarded_amount
 *      berbeda dari penawaran terakhir vendor pemenang, sebuah keputusan yang
 *      lebih murah/mahal dari yang ditawar hanya sah bila ada risalah negosiasi
 *      (BAN) untuk (RFQ, vendor). Ditegakkan saat SUBMIT — award tak bisa masuk
 *      jenjang persetujuan tanpa BAN-nya.
 *
 *   2. PO/SPK DARI RFQ TAK BISA DISETUJUI TANPA AWARD DISETUJUI. assertApproved
 *      Award() dipanggil PoService/SubcontractService::approve.
 *
 * Persetujuannya sendiri lewat AMBANG N-LEVEL (AwardDecision::approvalLadderKey),
 * ditegakkan di Core\Traits\Approvable::approve — service ini tidak menduplikasi
 * penghitungan tingkatnya, hanya memanggil approve().
 */
class AwardDecisionService
{
    public function create(array $data): AwardDecision
    {
        return DB::transaction(function () use ($data): AwardDecision {
            $rfq = Rfq::query()->findOrFail((int) $data['rfq_id']);
            $vendorId = (int) $data['vendor_id'];
            $this->assertInvited($rfq, $vendorId);

            $rab = round((float) ($data['rab_amount'] ?? 0), 2);
            $awarded = round((float) ($data['awarded_amount'] ?? 0), 2);
            $deviation = round($awarded - $rab, 2);

            $reason = trim((string) ($data['deviation_reason'] ?? ''));
            $this->assertDeviationReason($deviation, $reason);

            $award = new AwardDecision(Arr::except($data, ['code', 'status', 'deviation_amount']));
            $award->rab_amount = $rab;
            $award->awarded_amount = $awarded;
            $award->deviation_amount = $deviation;
            $award->deviation_reason = $reason === '' ? null : $reason;
            $award->status = DocumentStatus::Draft;
            $award->save(); // HasDocumentNumber mengisi kode AWD

            return $award->load('rfq', 'vendor');
        });
    }

    public function update(AwardDecision $award, array $data): AwardDecision
    {
        $this->assertEditable($award);

        return DB::transaction(function () use ($award, $data): AwardDecision {
            $award->fill(Arr::except($data, ['code', 'status', 'rfq_id', 'vendor_id', 'deviation_amount']));

            $rab = round((float) ($data['rab_amount'] ?? $award->rab_amount), 2);
            $awarded = round((float) ($data['awarded_amount'] ?? $award->awarded_amount), 2);
            $deviation = round($awarded - $rab, 2);

            $reason = trim((string) ($data['deviation_reason'] ?? $award->deviation_reason ?? ''));
            $this->assertDeviationReason($deviation, $reason);

            $award->rab_amount = $rab;
            $award->awarded_amount = $awarded;
            $award->deviation_amount = $deviation;
            $award->deviation_reason = $reason === '' ? null : $reason;
            $award->save();

            return $award->load('rfq', 'vendor');
        });
    }

    public function submit(AwardDecision $award, ?User $by = null): AwardDecision
    {
        // Kriteria #4, paruh 1: harga berubah menuntut BA negosiasi.
        $this->assertNegotiationMinuteIfPriceChanged($award);

        return $award->submit($by);
    }

    /**
     * Satu tingkat persetujuan. Ambang n-level (jumlah penyetuju berbeda,
     * direktur untuk tingkat 2+) ditegakkan di dalam Approvable::approve; di sini
     * hanya pagar kriteria #4 diulang agar BAN yang dihapus setelah submit tidak
     * lolos ke keputusan final.
     */
    public function approve(AwardDecision $award, User $by, ?string $note = null): AwardDecision
    {
        $this->assertNegotiationMinuteIfPriceChanged($award);

        return $award->approve($by, $note);
    }

    public function reject(AwardDecision $award, User $by, ?string $note = null): AwardDecision
    {
        return $award->reject($by, $note);
    }

    public function delete(AwardDecision $award): void
    {
        $this->assertEditable($award);

        $award->delete();
    }

    /**
     * KRITERIA #4, paruh 2 — dipanggil PoService/SubcontractService::approve.
     *
     * Sebuah PO/SPK yang lahir dari RFQ (punya rfq_id) tidak dapat disetujui
     * sebelum ada keputusan pemenang DISETUJUI untuk (RFQ, vendor)-nya. 422
     * menyebut RFQ mana yang keputusannya belum ada/­belum disetujui.
     *
     * @throws LogicException
     */
    public function assertApprovedAward(Model $document, ?int $rfqId, int $vendorId): void
    {
        if ($rfqId === null) {
            return; // dokumen bukan dari RFQ — tidak ada award untuk dipersyaratkan
        }

        $hasApproved = AwardDecision::query()
            ->where('rfq_id', $rfqId)
            ->where('vendor_id', $vendorId)
            ->where('status', DocumentStatus::Approved->value)
            ->exists();

        if ($hasApproved) {
            return;
        }

        $label = ApprovableDocuments::label($document);
        $code = (string) ($document->code ?? $document->getKey());
        $rfqCode = Rfq::query()->find($rfqId)?->code ?? "#{$rfqId}";

        throw new LogicException(sprintf(
            '%s %s berasal dari RFQ %s namun keputusan pemenang (award) untuk vendor ini belum ada atau belum '
            .'disetujui; terbitkan dan setujui keputusan pemenang dulu sebelum menyetujui %s.',
            $label,
            $code,
            $rfqCode,
            $label,
        ));
    }

    /**
     * Penawaran terakhir vendor pemenang: harga tercatat × qty, per baris yang
     * ditawar. Pembanding untuk mendeteksi "harga berubah" pada kriteria #4.
     */
    private function lastQuoteTotal(Rfq $rfq, int $vendorId): float
    {
        $total = 0.0;

        foreach ($rfq->items()->with(['quotes' => fn ($q) => $q->where('vendor_id', $vendorId)])->get() as $line) {
            $quote = $line->quotes->first();

            if ($quote !== null) {
                $total += round((float) $quote->unit_price * (float) $line->qty, 2);
            }
        }

        return round($total, 2);
    }

    /**
     * @throws LogicException
     */
    private function assertNegotiationMinuteIfPriceChanged(AwardDecision $award): void
    {
        $rfq = $award->rfq ?? Rfq::query()->find($award->rfq_id);

        if ($rfq === null) {
            return;
        }

        $lastQuote = $this->lastQuoteTotal($rfq, (int) $award->vendor_id);

        // Tidak ada penawaran tercatat (award atas RFQ tanpa harga terisi):
        // tidak ada "harga sebelumnya" untuk dibandingkan, jadi tidak ada nego
        // yang bisa ditagihkan. Biarkan lolos.
        if ($lastQuote <= 0.0) {
            return;
        }

        // Harga tidak berubah -> tidak ada negosiasi -> tidak perlu BAN.
        if (abs((float) $award->awarded_amount - $lastQuote) <= 0.005) {
            return;
        }

        $hasMinute = NegotiationMinute::query()
            ->where('rfq_id', $award->rfq_id)
            ->where('vendor_id', $award->vendor_id)
            ->exists();

        if ($hasMinute) {
            return;
        }

        throw new LogicException(sprintf(
            'Nilai keputusan (Rp %s) berbeda dari penawaran terakhir vendor (Rp %s), sehingga keputusan pemenang ini '
            .'WAJIB didasari Berita Acara Negosiasi (BAN) untuk RFQ %s; belum ada BAN untuk vendor ini — buat BAN-nya dulu.',
            number_format((float) $award->awarded_amount, 2, ',', '.'),
            number_format($lastQuote, 2, ',', '.'),
            $rfq->code,
        ));
    }

    /**
     * @throws LogicException
     */
    private function assertDeviationReason(float $deviation, string $reason): void
    {
        // WAJIB hanya bila memutuskan DI ATAS RAB — membayar lebih dari nilai
        // wajar menuntut alasan yang bisa diaudit. Di bawah RAB (deviation < 0)
        // adalah penghematan; tidak perlu dibela.
        if ($deviation > 0.005 && $reason === '') {
            throw new LogicException(
                'Nilai keputusan melampaui RAB; alasan deviasi (deviation_reason) wajib diisi karena memutuskan '
                .'di atas nilai wajar harus dapat dipertanggungjawabkan.'
            );
        }
    }

    private function assertInvited(Rfq $rfq, int $vendorId): void
    {
        if (! $rfq->vendors()->where('vendor_id', $vendorId)->exists()) {
            throw new LogicException(
                "Vendor #{$vendorId} tidak diundang pada RFQ {$rfq->code}; keputusan pemenang hanya untuk vendor yang diajak banding."
            );
        }
    }

    private function assertEditable(AwardDecision $award): void
    {
        if (! $award->status->isEditable()) {
            throw new LogicException(
                "Keputusan pemenang {$award->code} berstatus {$award->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
