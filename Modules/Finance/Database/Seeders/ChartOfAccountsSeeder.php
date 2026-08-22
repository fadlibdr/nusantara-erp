<?php

namespace Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Finance\Models\Account;

/**
 * Master data: chart of accounts. Safe for production — extracted from
 * FinanceDatabaseSeeder so ProductionSeeder can seed the COA without the
 * demo documents. Idempotent (updateOrCreate by code).
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Full COA: groups (is_postable = false) with postable leaves. Contra
     * accounts (akumulasi penyusutan) are asset-typed with a credit normal
     * balance.
     */
    public function run(): void
    {
        // [code, name, type, normal, postable, parent_code]
        $accounts = [
            // ---- 1 ASET ----
            ['1-0000', 'Aset', 'asset', 'debit', false, null],
            ['1-1000', 'Aset Lancar', 'asset', 'debit', false, '1-0000'],
            // Kas kecil drawers post to their own 1-11xx children of this
            // account. Migration 2026_08_01_001109 flips it to a GROUP on
            // installations where it has never been posted to (the live demo);
            // the SHIPPED default stays postable because Core's suites pin
            // "1-1100 is a postable leaf" (SettingValidationTest,
            // AccountRepointingGuardTest — another team's files). The loop
            // below preserves an existing row's is_postable so re-seeding a
            // migrated database does not undo the flip.
            ['1-1100', 'Kas', 'asset', 'debit', true, '1-1000'],
            ['1-1200', 'Bank', 'asset', 'debit', false, '1-1000'],
            ['1-1210', 'Bank BCA Operasional', 'asset', 'debit', true, '1-1200'],
            ['1-1220', 'Bank Mandiri Proyek', 'asset', 'debit', true, '1-1200'],
            ['1-1300', 'Piutang Usaha', 'asset', 'debit', true, '1-1000'],
            ['1-1350', 'Piutang Retensi', 'asset', 'debit', true, '1-1000'],
            ['1-1360', 'Aset Kontrak (Pendapatan Belum Difakturkan)', 'asset', 'debit', true, '1-1000'],
            // Uang muka kerja karyawan (kasbon), didebit saat kasbon cair dan
            // dikredit penuh saat pertanggungjawaban. 1-13xx, bukan 1-14xx:
            // 1-1400 sudah dipakai Persediaan Material dan keluarga piutang
            // adalah 1-1300/1-1350/1-1360.
            ['1-1370', 'Piutang Karyawan (Kasbon)', 'asset', 'debit', true, '1-1000'],
            ['1-1400', 'Persediaan Material', 'asset', 'debit', true, '1-1000'],
            ['1-1500', 'Uang Muka Proyek', 'asset', 'debit', true, '1-1000'],
            ['1-1600', 'PPN Masukan', 'asset', 'debit', true, '1-1000'],
            ['1-1700', 'Pajak Dibayar Dimuka PPh', 'asset', 'debit', true, '1-1000'],
            // Sibling of 1-1700, not a child of it: 1-1700 is a POSTABLE leaf
            // already carrying the PPh final 4(2) withheld from termin
            // konstruksi, and turning it into a group would move a live
            // balance out of the trial balance. The two must not share one
            // saldo either — PPh final habis di situ, PPh 23 adalah kredit
            // pajak yang mengurangi PPh Badan akhir tahun (WithholdingType).
            ['1-1710', 'Pajak Dibayar Dimuka PPh 23', 'asset', 'debit', true, '1-1000'],
            ['1-2000', 'Aset Tetap', 'asset', 'debit', false, '1-0000'],
            ['1-2100', 'Tanah', 'asset', 'debit', true, '1-2000'],
            ['1-2200', 'Bangunan', 'asset', 'debit', true, '1-2000'],
            ['1-2210', 'Akumulasi Penyusutan Bangunan', 'asset', 'credit', true, '1-2000'],
            ['1-2300', 'Kendaraan', 'asset', 'debit', true, '1-2000'],
            ['1-2310', 'Akumulasi Penyusutan Kendaraan', 'asset', 'credit', true, '1-2000'],
            ['1-2400', 'Peralatan Proyek', 'asset', 'debit', true, '1-2000'],
            ['1-2410', 'Akumulasi Penyusutan Peralatan Proyek', 'asset', 'credit', true, '1-2000'],
            ['1-2500', 'Peralatan Kantor & IT', 'asset', 'debit', true, '1-2000'],
            ['1-2510', 'Akumulasi Penyusutan Peralatan Kantor & IT', 'asset', 'credit', true, '1-2000'],

            // ---- 2 KEWAJIBAN ----
            ['2-0000', 'Kewajiban', 'liability', 'credit', false, null],
            ['2-1000', 'Kewajiban Jangka Pendek', 'liability', 'credit', false, '2-0000'],
            ['2-1100', 'Hutang Usaha', 'liability', 'credit', true, '2-1000'],
            // Payroll liabilities, kept out of Hutang Usaha (trade) and out of
            // Hutang Pajak (2-1200): a net wage owed to staff is neither a
            // supplier invoice nor a tax, and on the balance sheet it should not
            // read as either.
            ['2-1110', 'Hutang Gaji & Upah', 'liability', 'credit', true, '2-1000'],
            ['2-1120', 'Hutang BPJS', 'liability', 'credit', true, '2-1000'],
            // GR/IR: barang sudah diterima (persediaan bertambah) tapi tagihan
            // vendor belum masuk. Didebit kembali saat AP bill atas PO disetujui.
            ['2-1150', 'Penerimaan Barang Belum Ditagih', 'liability', 'credit', true, '2-1000'],
            ['2-1200', 'Hutang Pajak', 'liability', 'credit', false, '2-1000'],
            ['2-1210', 'Hutang PPh 21', 'liability', 'credit', true, '2-1200'],
            ['2-1220', 'Hutang PPh 23', 'liability', 'credit', true, '2-1200'],
            ['2-1230', 'Hutang PPh Final 4(2)', 'liability', 'credit', true, '2-1200'],
            ['2-1240', 'Hutang PPh Badan', 'liability', 'credit', true, '2-1200'],
            ['2-1300', 'PPN Keluaran', 'liability', 'credit', true, '2-1000'],
            ['2-1400', 'Pendapatan Diterima Dimuka (Uang Muka)', 'liability', 'credit', true, '2-1000'],
            ['2-1410', 'Liabilitas Kontrak (Penagihan Melebihi Pendapatan)', 'liability', 'credit', true, '2-1000'],
            ['2-1500', 'Hutang Retensi Subkon', 'liability', 'credit', true, '2-1000'],
            ['2-1700', 'Provisi Kerugian Kontrak', 'liability', 'credit', true, '2-1000'],
            ['2-1600', 'Beban Yang Masih Harus Dibayar', 'liability', 'credit', true, '2-1000'],
            ['2-2000', 'Kewajiban Jangka Panjang', 'liability', 'credit', false, '2-0000'],
            ['2-2100', 'Hutang Bank', 'liability', 'credit', true, '2-2000'],

            // ---- 3 EKUITAS ----
            ['3-0000', 'Ekuitas', 'equity', 'credit', false, null],
            ['3-1100', 'Modal Disetor', 'equity', 'credit', true, '3-0000'],
            ['3-2100', 'Laba Ditahan', 'equity', 'credit', true, '3-0000'],
            // Lawan setiap saldo awal yang dimasukkan saat migrasi data: stok
            // awal, piutang awal, hutang awal. Bukan modal dan bukan laba —
            // akun antara yang ditutup sekali ke 3-1100 / 3-2100 oleh akuntan
            // setelah seluruh saldo awal masuk. Tanpa akun ini lawan stok awal
            // jatuh ke 6-4400 Selisih Persediaan dan melaporkan seluruh
            // persediaan pembuka sebagai keuntungan operasional.
            ['3-3100', 'Saldo Awal', 'equity', 'credit', true, '3-0000'],

            // ---- 4 PENDAPATAN ----
            ['4-0000', 'Pendapatan', 'revenue', 'credit', false, null],
            ['4-1100', 'Pendapatan Jasa Konstruksi', 'revenue', 'credit', true, '4-0000'],
            ['4-1200', 'Pendapatan Integrasi Sistem', 'revenue', 'credit', true, '4-0000'],
            ['4-1300', 'Pendapatan Jasa Pemeliharaan', 'revenue', 'credit', true, '4-0000'],

            // ---- 5 BEBAN PROYEK (HPP) ----
            ['5-0000', 'Beban Proyek (HPP)', 'cogs', 'debit', false, null],
            ['5-1100', 'Beban Material', 'cogs', 'debit', true, '5-0000'],
            ['5-1200', 'Beban Upah Proyek', 'cogs', 'debit', true, '5-0000'],
            ['5-1300', 'Beban Subkontraktor', 'cogs', 'debit', true, '5-0000'],
            ['5-1400', 'Beban Peralatan', 'cogs', 'debit', true, '5-0000'],
            ['5-1500', 'Beban Overhead Proyek', 'cogs', 'debit', true, '5-0000'],
            ['5-1600', 'Beban Provisi Kerugian Kontrak', 'cogs', 'debit', true, '5-0000'],

            // ---- 6 BEBAN OPERASIONAL ----
            ['6-0000', 'Beban Operasional', 'expense', 'debit', false, null],
            ['6-1100', 'Beban Gaji & Tunjangan', 'expense', 'debit', true, '6-0000'],
            ['6-1200', 'Beban BPJS & Kesejahteraan Karyawan', 'expense', 'debit', true, '6-0000'],
            ['6-2100', 'Beban Sewa Kantor', 'expense', 'debit', true, '6-0000'],
            ['6-2200', 'Beban Utilitas & Komunikasi', 'expense', 'debit', true, '6-0000'],
            ['6-3100', 'Beban Penyusutan', 'expense', 'debit', true, '6-0000'],
            ['6-4100', 'Beban Umum & Administrasi', 'expense', 'debit', true, '6-0000'],
            ['6-4200', 'Beban Pemasaran & Tender', 'expense', 'debit', true, '6-0000'],
            ['6-4300', 'Beban Perjalanan Dinas', 'expense', 'debit', true, '6-0000'],
            // Selisih hasil stock opname, barang rusak dan barang hilang.
            ['6-4400', 'Selisih Persediaan', 'expense', 'debit', true, '6-0000'],
            // Pencocokan tiga arah: selisih harga PO vs harga penerimaan barang.
            ['6-4500', 'Selisih Harga Pembelian', 'expense', 'debit', true, '6-0000'],

            // ---- 7 PENDAPATAN & BEBAN LAIN ----
            ['7-0000', 'Pendapatan & Beban Lain-lain', 'other', 'credit', false, null],
            ['7-1100', 'Pendapatan Bunga', 'other', 'credit', true, '7-0000'],
            ['7-1200', 'Pendapatan Lain-lain', 'other', 'credit', true, '7-0000'],
            ['7-2100', 'Beban Admin Bank', 'other', 'debit', true, '7-0000'],
            ['7-2200', 'Beban Bunga Pinjaman', 'other', 'debit', true, '7-0000'],
            // PPh final 4(2) dipotong customer atas pendapatan konstruksi kita
            // dibukukan sebagai beban pajak final (bukan uang muka PPh Badan).
            ['7-2300', 'Beban Pajak Final', 'other', 'debit', true, '7-0000'],
            // Denda keterlambatan / potongan lain-lain yang dipotong pemberi
            // kerja dari pembayaran termin (temuan #15) — cermin migrasi
            // 2026_08_08_001121, pola dua-tempat yang sama dengan 1-1710.
            ['7-2400', 'Beban Denda & Potongan Lain-lain', 'other', 'debit', true, '7-0000'],
        ];

        foreach ($accounts as [$code, $name, $type, $normal, $postable, $parentCode]) {
            // 1-1100 Kas: the group-or-leaf state belongs to the INSTALLATION
            // (see the comment on its row above) — never overwrite it.
            if ($code === '1-1100') {
                $existing = Account::withTrashed()->where('code', $code)->first();

                if ($existing !== null) {
                    $postable = (bool) $existing->is_postable;
                }
            }

            Account::withTrashed()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_type' => $type,
                    'normal_balance' => $normal,
                    'is_postable' => $postable,
                    'is_active' => true,
                    // Parents precede children in the list, so the lookup hits.
                    'parent_id' => $parentCode !== null
                        ? Account::query()->where('code', $parentCode)->value('id')
                        : null,
                ],
            );
        }
    }
}
