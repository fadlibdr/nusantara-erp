<?php

namespace Modules\Finance\Enums;

/**
 * What the customer keeps back out of a termin payment instead of transferring
 * it to us. Every kind reduces the cash and none reduces the receivable — the
 * invoice is settled in full; for the tax kinds the counterparty pays part of
 * it to the state on our behalf, for OtherDeduction we simply bear the cost.
 *
 * TIGA JENIS PAJAK, KARENA PERUSAHAAN INI MENJUAL DUA HAL. Jasa konstruksi
 * dipotong PPh final Pasal 4(2) (PP 9/2022); jasa integrasi sistem —
 * instalasi, perawatan, konsultasi teknis — dipotong PPh Pasal 23 sebesar 2%
 * dari nilai jasa (UU PPh Pasal 23 ayat 1 huruf c). Sebelum Pph23 ada,
 * satu-satunya jalan mencatat potongan 2% itu adalah menyamarkannya sebagai
 * PPh final, yang mencampur pajak FINAL (habis di situ) dengan KREDIT PAJAK
 * yang dikurangkan dari PPh Badan akhir tahun — dua hal yang tidak boleh
 * berada di satu saldo. Jenis keempat, OtherDeduction, bukan pajak sama
 * sekali — lihat catatannya sendiri.
 */
enum WithholdingType: string
{
    /**
     * PPh final jasa konstruksi (PP 9/2022) withheld by a badan-usaha owner.
     * An asset: it is our own income tax, prepaid, and creditable in the annual
     * return — hence 1-1700 Pajak Dibayar Dimuka PPh.
     */
    case PphFinal = 'pph_final';

    /**
     * PPN dipungut pemungut (wapu): a BUMN/government owner deposits our output
     * VAT itself (PMK 231/2019). We already credited 2-1300 PPN Keluaran when
     * the invoice was approved; because someone else settles that liability,
     * debiting 2-1300 here is what discharges it. Booking it anywhere else
     * would leave us owing DJP money the owner has already paid.
     */
    case PpnWapu = 'ppn_wapu';

    /**
     * PPh Pasal 23 atas jasa yang dipotong pelanggan badan usaha — 2% dari
     * nilai jasa (bruto tanpa PPN) untuk penyedia ber-NPWP. Ini pendapatan
     * integrator sistem, bukan jasa konstruksi: pemasangan jaringan,
     * pemeliharaan perangkat, konsultasi teknis.
     *
     * NOT 1-1700 Pajak Dibayar Dimuka PPh, and this is the whole point of the
     * separate account. PPh final 4(2) is FINAL — it discharges the tax on that
     * income and never meets the annual computation again — while PPh 23 is a
     * KREDIT PAJAK that is subtracted from the year's PPh Badan. Parked in one
     * balance the two are indistinguishable, and the SPT Tahunan either claims
     * a credit that does not exist or forfeits one that does. Hence 1-1710
     * Pajak Dibayar Dimuka PPh 23, alongside its payable twin 2-1220 Hutang
     * PPh 23 that ApBillService already credits when WE withhold from a vendor.
     */
    case Pph23 = 'pph_23';

    /**
     * Potongan lain-lain yang dipotong pemberi kerja dari pembayaran termin —
     * paling sering denda keterlambatan (1‰ per hari, plafon 5%, klausul baku
     * kontrak konstruksi Indonesia), kadang klaim kerusakan yang dibebankan
     * balik (temuan #15). BUKAN pajak: tidak ada bukti potong dan tidak ada
     * kredit pajak — yang wajib justru ALASAN tertulis, karena tanpa alasan
     * baris ini adalah selisih tanpa cerita yang tidak bisa dijelaskan siapa
     * memotong dan atas dasar apa.
     *
     * Dr 7-2400 Beban Denda & Potongan Lain-lain. Chart tersemai tidak punya
     * akun denda sama sekali (grep 'denda' di seluruh COA: nol), jadi akunnya
     * dibuat lewat migrasi 2026_08_08_001121 dan dicermin di
     * ChartOfAccountsSeeder. Keluarga 7-2xxx dipilih karena potongan
     * non-operasional atas termin kita sudah tinggal di rak itu — 7-2300
     * Beban Pajak Final adalah potongan PP 9/2022 dengan mekanik serupa —
     * bukan 6-xxxx (denda kontraktual bukan overhead kantor) dan bukan
     * pengurang 4-xxxx (DPP faktur pajak yang sudah dilaporkan tidak boleh
     * direstat diam-diam).
     */
    case OtherDeduction = 'other_deduction';

    public function label(): string
    {
        return match ($this) {
            self::PphFinal => 'PPh final dipotong pelanggan',
            self::PpnWapu => 'PPN dipungut pemungut (wapu)',
            self::Pph23 => 'PPh 23 jasa dipotong pelanggan',
            self::OtherDeduction => 'Potongan lain-lain (denda/klaim)',
        };
    }

    public function accountCode(): string
    {
        return match ($this) {
            self::PphFinal => '1-1700',
            self::PpnWapu => '2-1300',
            self::Pph23 => '1-1710',
            self::OtherDeduction => '7-2400',
        };
    }

    /**
     * The number of the bukti potong is the ONLY evidence supporting the PPh
     * credit, so a PPh withholding without it is an unrecoverable loss dressed
     * up as an asset. A wapu collection is proven by the owner's SSP, which
     * routinely arrives after the transfer, so it is recorded when it comes.
     */
    public function requiresCertificate(): bool
    {
        return $this === self::PphFinal || $this === self::Pph23;
    }

    public function certificateLabel(): string
    {
        return match ($this) {
            self::PphFinal => 'bukti potong',
            self::PpnWapu => 'bukti pungut',
            self::Pph23 => 'bukti potong PPh 23',
            // Nota debet/potongan dari pemberi kerja — opsional, bukan syarat.
            self::OtherDeduction => 'nota potongan',
        };
    }

    /**
     * A non-tax deduction has no statutory paper; its written REASON is the
     * whole audit trail. Without one the row is a 7-2400 debit no auditor can
     * trace to a contract clause — so PaymentService refuses it, the way it
     * refuses a PPh credit without its bukti potong.
     */
    public function requiresReason(): bool
    {
        return $this === self::OtherDeduction;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
