<?php

namespace Modules\Finance\Support;

use Modules\Finance\Models\Account;
use Modules\Finance\Models\BankAccount;

/**
 * Which PSAK 2 activity a cash movement belongs to, decided by the COUNTER
 * account of the journal that touched the cash pool — one declarative prefix
 * list, in the taste of DanglingDocuments and SettleableLiabilities.
 *
 * A prefix map on account CODES, deliberately NOT a new column on
 * fin_accounts: codes are the codebase's stable canon (settleInvoice's
 * '1-1300', WithholdingType::accountCode()), the map needs no migration in an
 * exhausted Finance migration block, and — the real point — an account the map
 * does NOT know fails VISIBLY into the 'lainnya' activity of the statement
 * instead of silently into a wrong one. CashFlowActivityMapTest walks every
 * postable account ChartOfAccountsSeeder ships and refuses a gap, so 'lainnya'
 * is reserved for accounts created AFTER this list, never for laziness today.
 *
 * LONGEST PREFIX WINS. '7-22' (pendanaan) must beat any broader '7-…' rule a
 * later maintainer adds, so matching is by descending prefix length and the
 * test pins that precedence.
 *
 * THE TWO PSAK 2 ELECTIVES live here and are PRINTED by the statement's
 * 'policy' array, because both classifications are permitted and the chosen
 * one must be visible, not archaeological:
 *
 *  - bunga diterima (7-11xx Pendapatan Bunga) => operasi
 *  - bunga pinjaman dibayar (7-2200 Beban Bunga Pinjaman) => pendanaan
 */
class CashFlowActivityMap
{
    public const OPERASI = 'operasi';

    public const INVESTASI = 'investasi';

    public const PENDANAAN = 'pendanaan';

    /**
     * prefix => activity. Complete against ChartOfAccountsSeeder: every
     * postable account outside the cash pool matches exactly one row.
     *
     * @var array<string, string>
     */
    public const PREFIXES = [
        // Modal kerja: piutang (1-1300/1350/1360), kasbon karyawan (1-1370),
        // persediaan (1-1400), uang muka proyek (1-1500), pajak dibayar dimuka
        // (1-1600 PPN Masukan, 1-1700 PPh) — the wapu receipt shape
        // (Dr Bank + Dr 1-1700 + Dr 2-1300 / Cr 1-1300) decomposes entirely
        // inside these rows plus 2-13 below.
        '1-13' => self::OPERASI,
        '1-14' => self::OPERASI,
        '1-15' => self::OPERASI,
        '1-16' => self::OPERASI,
        '1-17' => self::OPERASI,
        // Kewajiban usaha jangka pendek: hutang usaha/gaji/BPJS/GRIR (2-11xx),
        // hutang pajak (2-12xx), PPN Keluaran (2-1300), uang muka pelanggan &
        // liabilitas kontrak (2-14xx), retensi subkon (2-1500), beban YMH
        // dibayar (2-1600), provisi (2-1700).
        '2-11' => self::OPERASI,
        '2-12' => self::OPERASI,
        '2-13' => self::OPERASI,
        '2-14' => self::OPERASI,
        '2-15' => self::OPERASI,
        '2-16' => self::OPERASI,
        '2-17' => self::OPERASI,
        // Laba rugi: pendapatan (4-), HPP (5-), beban operasional (6-).
        '4-' => self::OPERASI,
        '5-' => self::OPERASI,
        '6-' => self::OPERASI,
        // Pendapatan/beban lain yang PSAK 2 tempatkan di operasi: bunga
        // diterima (7-11xx, ELEKTIF — dicetak di 'policy'), pendapatan
        // lain-lain (7-12xx), beban admin bank (7-21xx), beban pajak final
        // (7-23xx, potongan PP 9/2022 atas termin kita).
        '7-11' => self::OPERASI,
        '7-12' => self::OPERASI,
        '7-21' => self::OPERASI,
        '7-23' => self::OPERASI,
        // Beban denda & potongan lain-lain (7-24xx, temuan #15): pengurang
        // penerimaan termin — aktivitas operasi, bukan 'lainnya'.
        '7-24' => self::OPERASI,

        // Aset tetap 1-2xxx: tanah, bangunan, kendaraan, peralatan DAN akun
        // akumulasi penyusutannya — depreciation never touches the cash pool,
        // so an akumulasi row can only appear here through an asset disposal
        // journal, which IS investasi.
        '1-2' => self::INVESTASI,

        // Hutang bank jangka panjang (2-2100: drawdown masuk, angsuran pokok
        // keluar), ekuitas (3-: setoran modal, saldo awal migrasi data,
        // prive/dividen), dan bunga pinjaman dibayar (7-2200, ELEKTIF PSAK 2
        // — dicetak di 'policy').
        '2-2' => self::PENDANAAN,
        '3-' => self::PENDANAAN,
        '7-22' => self::PENDANAAN,
    ];

    /**
     * The activity a counter account belongs to, longest prefix first —
     * null for a code the map does not know, which the statement surfaces as
     * a 'lainnya' row instead of dropping.
     */
    public static function activityFor(string $code): ?string
    {
        $best = null;
        $bestLength = -1;

        foreach (self::PREFIXES as $prefix => $activity) {
            if (str_starts_with($code, $prefix) && strlen($prefix) > $bestLength) {
                $best = $activity;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * The CASH POOL: every account whose movement IS cash, so journals whose
     * lines all live here are 'mutasi antar rekening', not activity.
     *
     *  - Accounts referenced by fin_bank_accounts, INCLUDING soft-deleted bank
     *    rows: a statement over January must still recognise a bank account
     *    closed in March, or its history reclassifies itself. This rule earns
     *    its keep for bank accounts pointed at CUSTOM codes outside 1-1xxx.
     *  - Postable accounts under 1-11% — 1-1100 Kas where it is still a leaf,
     *    and every kas kecil drawer (1-1110, …) the fund CRUD creates, which
     *    join the pool automatically. Trashed accounts included, same
     *    history argument.
     *  - Postable accounts under 1-12% — the seeded bank leaves (1-1210 BCA,
     *    1-1220 Mandiri). A GL account named "Bank" IS cash even before the
     *    bank-account master claims it: on a fresh chart a hand-keyed transfer
     *    Dr 1-1220 / Cr 1-1210 must read as mutasi antar rekening, not as a
     *    fabricated 'lainnya' outflow. On the live demo both leaves already
     *    carry fin_bank_accounts rows, so this rule changes nothing there.
     *
     * 1-1300 Piutang Usaha and friends stay OUT: a receivable is a promise,
     * not cash, and PSAK 2 reports the moment the promise converts.
     *
     * @return array<int, int>
     */
    public static function cashAccountIds(): array
    {
        $bankIds = BankAccount::query()
            ->withTrashed()
            ->pluck('coa_account_id')
            ->map(fn ($id): int => (int) $id);

        $kasIds = Account::query()
            ->withTrashed()
            ->where('is_postable', true)
            ->where(function ($query) {
                $query->where('code', 'like', '1-11%')
                    ->orWhere('code', 'like', '1-12%');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        return $bankIds->merge($kasIds)->unique()->sort()->values()->all();
    }
}
