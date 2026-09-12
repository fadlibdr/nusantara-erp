<?php

namespace Modules\Finance\Console\Commands;

use Illuminate\Console\Command;
use Modules\Finance\Services\BankInboxService;

/**
 * Memeriksa folder terpantau rekening koran — tiap jam dari penjadwal
 * (FinanceServiceProvider), atau dari tombol "Periksa sekarang" di layar
 * lewat service yang sama. HANYA membaca folder; yang ditulis adalah ledger
 * fin_bank_inbox_files dan stempel core_settings bank_inbox.checked_at.
 *
 * Folder yang belum ada adalah keadaan bawaan setiap instalasi baru, termasuk
 * produksi sesudah deploy: dikatakan, keluar 0, tanpa notifikasi. Berkas yang
 * gagal juga keluar 0 — kegagalannya sudah menjadi baris ledger + notifikasi
 * kepada pemegang fin.update; status keluar bukan-nol dari penjadwal tidak
 * dibaca siapa pun (keluaran CLI dibuang ke /dev/null — PANDUAN-ADMINISTRATOR
 * §5.2).
 */
class BankInboxCommand extends Command
{
    protected $signature = 'fin:bank-inbox';

    protected $description = 'Read the watched bank-statement folder (per account-code sub-folder) and import new files through the ordinary statement import';

    public function handle(BankInboxService $inbox): int
    {
        if (! $inbox->folderExists()) {
            $inbox->scan();   // stempel "terakhir diperiksa" tetap ditulis: pemeriksaannya berjalan
            $this->info(sprintf('Folder terpantau belum ada: %s — tidak ada yang diperiksa (buat foldernya sesuai PANDUAN-ADMINISTRATOR §5.13).', $inbox->path()));

            return self::SUCCESS;
        }

        $result = $inbox->scan();
        $c = $result['counts'];

        if ($result['locked']) {
            // Tombol "Periksa sekarang" atau jam sebelumnya masih memegang kuncinya: tidak ada yang
            // diperiksa, tidak ada yang ditulis — dikatakan, keluar 0 (V-folder-1).
            $this->info(BankInboxService::LOCKED_NOTE);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d berkas: %d diimpor, %d gagal, %d salinan, %d diabaikan, %d tidak berubah',
            $c['seen'], $c['imported'], $c['failed'], $c['duplicate'], $c['ignored'], $c['unchanged'],
        ));

        return self::SUCCESS;
    }
}
