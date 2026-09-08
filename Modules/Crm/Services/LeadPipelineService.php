<?php

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Enums\LeadMove;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadStatusChange;
use Modules\Crm\Models\Quotation;

/**
 * SATU PINTU untuk tahap prospek (F-3 / T3.5).
 *
 * Aturannya sendiri hidup di LeadStatus::canMoveTo(); yang hidup di sini adalah
 * penegakannya dan KALIMATNYA — dan keduanya harus di satu tempat karena empat
 * permukaan menanyakan hal yang sama: layar dokumen, daftar, papan kanban, dan
 * API. Sampai paket ini, status prospek adalah kolom biasa di formulir: satu
 * PUT dengan {"status":"won"} memenangkan sebuah prospek tanpa penawaran, tanpa
 * nilai, tanpa tanggal keputusan — dan win-rate per sales dihitung dari kolom
 * itu.
 *
 * Karena itu pula PUT prospek kini MENOLAK field `status`
 * (LeadUpdateRequest): sebuah pintu kedua yang tidak memeriksa apa-apa membuat
 * pintu pertama sekadar saran.
 *
 * Kalimat penolakan menyebut JALAN YANG BENAR, bukan sekadar "tidak boleh":
 * yang membacanya adalah orang yang sedang mencoba menyelesaikan pekerjaannya,
 * dan sebuah "ditolak" tanpa alamat hanya membuat orang mencari jalan memutar.
 */
class LeadPipelineService
{
    /** Panjang minimum alasan mundur — satu kata seperti "salah" bukan alasan. */
    public const REASON_MIN = 5;

    /**
     * Pindahkan tahap sebuah prospek.
     *
     * @param  string|null  $reason  wajib untuk perpindahan mundur, tersimpan di riwayat
     */
    public function move(Lead $lead, LeadStatus $to, ?string $reason = null, ?User $actor = null): Lead
    {
        $from = $lead->status ?? LeadStatus::New;
        $verdict = $from->canMoveTo($to);

        $reason = is_string($reason) ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        match ($verdict) {
            LeadMove::Sama => $this->refuseSame($lead, $to),
            LeadMove::LewatPenawaran => $this->refuseOutcome($lead, $to),
            LeadMove::Terkunci => $this->refuseClosed($lead, $from),
            LeadMove::Mundur => $this->assertReason($lead, $from, $to, $reason),
            LeadMove::Maju => null,
        };

        return DB::transaction(function () use ($lead, $from, $to, $verdict, $reason, $actor): Lead {
            $lead->forceFill(['status' => $to])->save();

            $this->record($lead, $from, $to, $verdict->value, 'pipeline', $reason, null, $actor?->id);

            return $lead->refresh();
        });
    }

    /**
     * Keputusan penawaran menyeret prospeknya (temuan #58) — SATU-SATUNYA jalan
     * menuju Menang/Kalah, dan ia lewat sini supaya riwayatnya ikut tercatat.
     *
     * Prospek yang SUDAH menang tidak diturunkan oleh penawaran kedua yang
     * kalah: hubungan pelanggannya sudah ada. Aturan itu lahir bersama temuan
     * #58 dan tetap dipegang di sini, sekarang dengan jejaknya.
     */
    public function decideByQuotation(Lead $lead, LeadStatus $to, Quotation $quotation): Lead
    {
        if (! $to->isClosed()) {
            throw new \LogicException('decideByQuotation hanya untuk hasil menang/kalah.');
        }

        $from = $lead->status ?? LeadStatus::New;

        if ($from === LeadStatus::Won && $to === LeadStatus::Lost) {
            return $lead;
        }

        if ($from === $to) {
            return $lead;
        }

        $lead->forceFill(['status' => $to])->save();

        $this->record($lead, $from, $to, 'penawaran', 'quotation', null, $quotation->code, null);

        return $lead->refresh();
    }

    // ------------------------------------------------------------- penolakan

    private function refuseSame(Lead $lead, LeadStatus $to): never
    {
        throw ValidationException::withMessages([
            'status' => ["{$this->name($lead)} sudah berada di tahap {$to->label()}."],
        ]);
    }

    /**
     * Menang/Kalah lewat penawaran — dan kalimatnya menyebut penawaran MANA,
     * atau mengakui bahwa belum ada satu pun.
     *
     * "MANA" berarti yang tombolnya sungguh-sungguh ada di sana (verifikasi
     * F-3, 8 Sep 2026). "Tandai Menang" hanya lahir pada penawaran yang SUDAH
     * DISETUJUI — QuotationService::markWon melempar untuk status lain, dan
     * schema.js pun tidak menggambar tombolnya — sedangkan "Tandai Kalah" ada
     * pada setiap penawaran yang belum diputuskan. Sampai perbaikan ini
     * kalimatnya selalu menyebut penawaran TERBUKA TERBARU: seorang sales
     * dengan QTN/2026/IX/0001 (disetujui) dan QTN/2026/IX/0002 (draf) dikirim
     * ke draf-nya, menekan tombol yang tidak ada di sana, dan penawaran yang
     * sebenarnya bisa dimenangkan tidak pernah disebut. Sebuah penolakan yang
     * menyebut alamat yang salah lebih buruk daripada penolakan tanpa alamat:
     * yang kedua membuat orang bertanya, yang pertama membuatnya yakin
     * aplikasinya rusak.
     */
    private function refuseOutcome(Lead $lead, LeadStatus $to): never
    {
        $action = $to === LeadStatus::Won ? 'Tandai Menang' : 'Tandai Kalah';

        $undecided = $lead->quotations()
            ->whereNull('won_at')
            ->whereNull('lost_at')
            ->orderByDesc('id')
            ->get();

        $markable = $to === LeadStatus::Won
            ? $undecided->first(static fn (Quotation $quotation): bool => $quotation->status === DocumentStatus::Approved)
            : $undecided->first();

        $where = match (true) {
            // Jalan yang benar-benar bisa ditempuh sekarang.
            $markable !== null => "buka penawaran {$markable->code} lalu tekan \"{$action}\"",
            // Ada penawaran terbuka, tetapi belum disetujui: langkah yang
            // KURANG disebutkan, bukan disembunyikan.
            /*
             * Langkah yang KURANG, disebut setepat keadaannya: penawaran yang
             * sudah `submitted` hanya menunggu PERSETUJUAN, dan tombol "Ajukan"
             * memang tidak digambar untuknya (schema.js menggambarnya hanya pada
             * draft/rejected). Kalimat lama menyuruh menekan tombol yang tidak
             * ada di layar itu (verifikasi F-3 putaran 2, 8 Sep 2026).
             */
            $undecided->isNotEmpty() => sprintf(
                'penawaran %s masih %s — %s, lalu tekan "%s" di penawaran itu',
                $undecided->first()->code,
                mb_strtolower($undecided->first()->status?->label() ?? 'terbuka'),
                $undecided->first()->status === DocumentStatus::Submitted
                    ? 'setujui dulu'
                    : 'ajukan dan setujui dulu',
                $action,
            ),
            // Punya penawaran, semuanya sudah diputuskan (mis. prospek yang
            // sudah Menang lalu penawaran keduanya kalah).
            $lead->quotations()->exists() => 'seluruh penawaran prospek ini sudah diputuskan — '
                ."buat penawaran baru lebih dulu, lalu tekan \"{$action}\" di penawaran itu",
            default => 'prospek ini belum punya penawaran — buat penawarannya lebih dulu, '
                ."lalu tekan \"{$action}\" di penawaran itu",
        };

        throw ValidationException::withMessages([
            'status' => ["{$this->name($lead)} tidak bisa dipindahkan ke {$to->label()} lewat tahap: "
                .'status Menang/Kalah hanya lahir dari keputusan penawaran, bersama nilai dan tanggal '
                ."keputusannya. Jalannya: {$where}."],
        ]);
    }

    private function refuseClosed(Lead $lead, LeadStatus $from): never
    {
        throw ValidationException::withMessages([
            'status' => ["{$this->name($lead)} sudah {$from->label()} dan tahapnya mengikuti penawarannya, "
                .'jadi tidak bisa dikembalikan ke tahap mana pun. Bila pekerjaannya berlanjut dengan lingkup '
                .'baru, buat prospek baru — riwayat yang lama tetap utuh.'],
        ]);
    }

    /**
     * Mundur menuntut alasan. Kunci galatnya `reason`, dan itu bukan detail:
     * SPA menjawab 422 berkunci-`reason` dengan satu isian wajib lalu mencoba
     * lagi (actions.js confirmResubmit) — jadi seretan mundur di papan menjadi
     * satu dialog alasan, bukan penolakan buntu.
     */
    private function assertReason(Lead $lead, LeadStatus $from, LeadStatus $to, ?string $reason): void
    {
        if ($reason !== null && mb_strlen($reason) >= self::REASON_MIN) {
            return;
        }

        throw ValidationException::withMessages([
            'reason' => ["{$this->name($lead)} mundur dari {$from->label()} ke {$to->label()}: "
                .'sebutkan alasannya (minimal '.self::REASON_MIN.' karakter). '
                .'Alasan ini tersimpan di riwayat tahap prospek dan dibaca saat corong ditinjau.'],
        ]);
    }

    private function name(Lead $lead): string
    {
        return 'Prospek '.($lead->code ?? "#{$lead->id}");
    }

    private function record(
        Lead $lead,
        LeadStatus $from,
        LeadStatus $to,
        string $direction,
        string $source,
        ?string $reason,
        ?string $documentCode,
        ?int $userId,
    ): void {
        LeadStatusChange::query()->create([
            'lead_id' => $lead->id,
            'from_status' => $from,
            'to_status' => $to,
            'direction' => $direction,
            'source' => $source,
            'reason' => $reason,
            'document_code' => $documentCode,
            'user_id' => $userId,
        ]);
    }
}
