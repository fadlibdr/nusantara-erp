/* Widget "Proyek, piutang & hutang" (P1-D) - GET core/dashboard/summary.

   INI widget yang membawa Temuan 79. Sampai temuan itu ketiga angka di bawah
   adalah reduce() sisi klien atas halaman pertama per_page:100 - sebuah
   kekurangan hitung yang mulai pada dokumen ke-101 dan tidak pernah
   mengumumkan dirinya: seorang direktur yang membaca "Piutang belum tertagih
   Rp 3,2 M" tidak punya cara tahu bahwa invoice 101..140 tidak ikut. Sejak itu
   ketiganya dijumlah SATU kueri agregat per angka, di server, atas seluruh
   tabel. Widget ini tidak boleh menjumlah ulang apa pun.

   Server menyaring bloknya per izin (aturan yang sama dengan kalender), jadi
   blok yang tidak ada berarti "Anda tidak boleh melihatnya" - bukan nol.

   `mine` ikut dikirim supaya widget ini dan "Progres proyek" selalu bercerita
   tentang himpunan proyek yang SAMA saat sakelar "Proyek saya" menyala. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedStats, statRow, stat } from './kit.js';

export async function build({ mineOnly, reload }) {
  const tiles = await safe('core/dashboard/summary', { mine: mineOnly ? 1 : undefined });

  /* Satu fetch memberi makan KETIGA ubin, jadi gagalnya menjatuhkan ketiganya
     ke '—' - bukan ke Rp 0. Nol adalah pernyataan tentang uang, dan
     "Hutang belum dibayar Rp 0" di atas sumber yang jatuh adalah kebohongan
     yang rapi. */
  if (failure(tiles)) {
    return failedStats(['Proyek berjalan', 'Piutang belum tertagih', 'Hutang belum dibayar'], reload);
  }

  // Label ikut sakelarnya: angka yang berganti makna tidak boleh memakai judul
  // yang sama.
  const projectLabel = mineOnly ? 'Proyek saya (berjalan)' : 'Proyek berjalan';

  return el('.card-body', statRow([
    tiles.projects
      ? stat(projectLabel, String(tiles.projects.active_count), {
        sub: `Nilai kontrak ${fmt.rupiahShort(Number(tiles.projects.contract_value || 0))}`,
        onClick: () => navigate('r/projects'),
      })
      : null,
    tiles.ar_invoices
      ? stat('Piutang belum tertagih', fmt.rupiahShort(Number(tiles.ar_invoices.outstanding || 0)), {
        sub: `${tiles.ar_invoices.open_count} invoice terbuka`,
        onClick: () => navigate('r/finance/ar-invoices'),
      })
      : null,
    tiles.ap_bills
      ? stat('Hutang belum dibayar', fmt.rupiahShort(Number(tiles.ap_bills.outstanding || 0)), {
        sub: `${tiles.ap_bills.open_count} tagihan terbuka`,
        onClick: () => navigate('r/finance/ap-bills'),
      })
      : null,
  ]));
}
