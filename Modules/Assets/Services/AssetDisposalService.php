<?php

namespace Modules\Assets\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Enums\DepreciationRunStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\DepreciationRun;
use Modules\Finance\Services\JournalService;

/**
 * Penghapusbukuan / penjualan aset — the single path to status "disposed".
 *
 * Before this service existed the ordinary update could flip an asset to
 * disposed with no accounting at all: the tool that was sold, lost or scrapped
 * stayed on the balance sheet at cost plus its accumulated depreciation
 * forever, the gain or loss on the sale was never recognised, and the asset
 * register could never again be tied to the GL in an audit. The update side
 * door is now refused (AssetUpdateRequest); this posts the derecognition:
 *
 *   Dr {category accumulated account}   accumulated depreciation to date
 *   Dr 1-1300 Piutang Usaha             disposal_value (only when sold)
 *   Dr/Cr 7-1200 Pendapatan Lain-lain   loss / gain  (disposal_value − book value)
 *       Cr {category asset account}     acquisition cost
 *
 * Mirrors DepreciationService::postJournal: accounts come from the category
 * hints, a category without them is refused (not guessed), and the journal
 * goes through JournalService::autoPost so the open-period guard holds.
 */
class AssetDisposalService
{
    /**
     * Laba/rugi pelepasan. The seeded chart has no dedicated "Laba/Rugi
     * Pelepasan Aset" account and creating COA rows is out of scope for this
     * module, so both directions land on 7-1200 Pendapatan Lain-lain — a gain
     * as a credit, a loss as a debit (negative other income). The P&L nets
     * them correctly because the account sits in the "other" section either
     * way; what matters is that the difference is recognised at all.
     */
    private const GAIN_LOSS_ACCOUNT = '7-1200';

    /**
     * Debit leg for the sale proceeds. The chart has no "Piutang Lain-lain",
     * so the closest existing account is 1-1300 Piutang Usaha: the buyer owes
     * us money and the receipt is booked against this balance by JV manual
     * (Dr Bank, Cr 1-1300) — the same manual step the money needed before,
     * except the receivable now exists to be settled instead of the whole sale
     * living outside the books. NOT a bank account (nothing says the cash has
     * arrived on disposal day) and NOT trade-AR-by-invoice: the AR aging is
     * invoice-driven, so this balance shows in the GL, not in the aging — a
     * known, documented wart until the chart grows a Piutang Lain-lain.
     *
     * The same gap trips the period-close checklist: subledger_tied compares
     * GL 1-1300 against the invoice subledger, so the month of a
     * with-proceeds disposal FAILS that item until the JV manual settles the
     * balance. It is WARN severity — the close can proceed with a written
     * override — but the first closer after an asset sale should hear it from
     * here rather than from an unexplained tie-out failure.
     */
    private const PROCEEDS_ACCOUNT = '1-1300';

    /**
     * Dispose an asset: post the derecognition journal, then stamp
     * status/disposal fields in the same transaction.
     *
     * $data = [disposal_date, disposal_value, reason]
     */
    public function dispose(Asset $asset, array $data): Asset
    {
        return DB::transaction(function () use ($asset, $data): Asset {
            /*
             * Re-read under lock, same as JournalService::update(): the
             * route-bound instance was read before the permission check, and
             * a deploy or a second dispose committed inside that window would
             * otherwise pass the status guards below on a stale copy —
             * double-derecognising the same excavator posts the cost credit
             * twice and drives 1-2400 negative.
             */
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->status === AssetStatus::Disposed) {
                throw new LogicException("Aset {$asset->code} sudah dihapusbukukan pada {$asset->disposal_date?->toDateString()}.");
            }

            /*
             * P5 — alat SEWA tidak punya apa pun di neraca untuk dilepas:
             * harga perolehan NULL, akumulasi 0. Tanpa guard ini postJournal
             * membukukan Dr 1-1300 sebesar nilai pelepasan lawan "laba" penuh
             * di 7-1200 — piutang dan laba atas penjualan mesin yang bukan
             * milik kita. Mengakhiri sebuah sewa adalah mengembalikan
             * mobilisasinya lalu menonaktifkan masternya, bukan pelepasan.
             */
            if ($asset->isRented()) {
                throw new LogicException(
                    'Alat sewa tidak dihapusbukukan — alat milik vendor rental dikembalikan ke pemiliknya, '
                    .'bukan dilepas dari neraca. Akhiri mobilisasinya lalu nonaktifkan masternya.'
                );
            }

            if ($asset->status === AssetStatus::Deployed) {
                throw new LogicException("Aset {$asset->code} sedang termobilisasi; kembalikan dari proyek sebelum dihapusbukukan.");
            }

            /*
             * Run penyusutan DRAF yang memegang entri aset ini adalah beban
             * yang sudah dihitung tapi belum terbukukan: melepas asetnya
             * sekarang lalu memposting run itu nanti menaikkan akumulasi pada
             * aset yang sudah keluar dari neraca — kredit GL tanpa aset yang
             * menjelaskannya. post() juga melewati aset disposed (sabuk
             * kedua), tetapi menolak DI SINI memberi operator pilihan sadar:
             * posting dulu bulan berjalannya, atau hapus entrinya.
             */
            $draftRun = DepreciationRun::query()
                ->where('status', DepreciationRunStatus::Draft)
                ->whereHas('entries', fn ($q) => $q->where('asset_id', $asset->id))
                ->first();

            if ($draftRun !== null) {
                throw new LogicException(
                    "Run penyusutan {$draftRun->code} ({$draftRun->periodLabel()}) masih draf dan memuat entri aset {$asset->code}; "
                    .'posting atau hapus run itu lebih dulu supaya beban bulan berjalan tidak menimpa aset yang sudah dilepas.'
                );
            }

            $this->postJournal($asset, $data);

            $asset->forceFill([
                'status' => AssetStatus::Disposed,
                'disposal_date' => $data['disposal_date'],
                'disposal_value' => round((float) ($data['disposal_value'] ?? 0), 2),
                'disposal_reason' => $data['reason'],
            ])->save();

            /*
             * acquisition_cost / accumulated_depreciation / book_value are
             * deliberately left as they stood: they document WHAT was
             * derecognised. Disposed assets are excluded by status from
             * depreciation runs and from any live-register reading, so the
             * record does not double-count against the GL.
             */

            return $asset->load('category');
        });
    }

    /**
     * The derecognition, in the general ledger.
     */
    private function postJournal(Asset $asset, array $data): void
    {
        $category = $asset->category;
        $costAccount = $category?->asset_account_hint;
        $accumAccount = $category?->accum_account_hint;

        // Refused, not guessed — crediting the wrong asset-cost account
        // misstates two asset classes at once, invisibly, because they all sit
        // under 1-2000 on the face of the balance sheet.
        if (blank($costAccount) || blank($accumAccount)) {
            throw new LogicException(sprintf(
                'Kategori aset %s belum memiliki akun harga perolehan/akumulasi. '
                .'Lengkapi di Master Data › Kategori Aset sebelum menghapusbukukan.',
                $category?->name ?? $asset->code,
            ));
        }

        $cost = round((float) $asset->acquisition_cost, 2);
        $accumulated = round((float) $asset->accumulated_depreciation, 2);
        $proceeds = round((float) ($data['disposal_value'] ?? 0), 2);
        $bookValue = round($cost - $accumulated, 2);
        $gainLoss = round($proceeds - $bookValue, 2);

        // An asset that never carried value (cost 0, nothing received) has no
        // GL presence to derecognise; autoPost would refuse an all-zero
        // journal, so the status change stands alone.
        if ($cost === 0.0 && $proceeds === 0.0) {
            return;
        }

        $lines = [
            [
                'account_code' => $accumAccount,
                'debit' => $accumulated,
                'description' => "Penghapusan akumulasi penyusutan {$asset->code}",
            ],
            [
                'account_code' => self::PROCEEDS_ACCOUNT,
                'debit' => $proceeds,
                'description' => "Piutang hasil pelepasan {$asset->code}",
            ],
            [
                'account_code' => $costAccount,
                'credit' => $cost,
                'description' => "Penghapusan harga perolehan {$asset->code}",
            ],
            [
                'account_code' => self::GAIN_LOSS_ACCOUNT,
                // Loss: proceeds fall short of book value, the shortfall is
                // debited; gain: the excess is credited. Zero legs are dropped
                // by autoPost.
                'debit' => $gainLoss < 0 ? -$gainLoss : 0,
                'credit' => $gainLoss > 0 ? $gainLoss : 0,
                'description' => ($gainLoss < 0 ? 'Rugi' : 'Laba')." pelepasan aset {$asset->code}",
            ],
        ];

        // assertPeriodOpen runs inside autoPost against disposal_date — a
        // disposal into a closed month is refused, not silently re-dated.
        app(JournalService::class)->autoPost(
            'asset_disposal',
            (int) $asset->id,
            $lines,
            $data['disposal_date'],
            "Pelepasan aset {$asset->code} — {$data['reason']}",
        );
    }
}
