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
import { safe, failure, failedStats, statRow, stat, footLink } from './kit.js';

export async function build({ mineOnly, reload, card }) {
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

  /* JUDUL KARTU MENGIKUTI BLOK YANG BENAR-BENAR DIKIRIM SERVER.
     Katalog memberi kartu ini judul tetap 'Proyek, piutang & hutang' dengan
     izin ['prj.view','fin.view'] (salah satu cukup), sementara server menyaring
     bloknya per izin — jadi gudang (prj.view tanpa fin.view) membaca judul yang
     menjanjikan TIGA angka di atas kartu berisi SATU, tanpa satu kata pun yang
     membedakan "tidak boleh dilihat" dari "tidak ada isinya". Sebelum P1-D ubin
     ini tidak punya judul kartu sama sekali (P1-C menggambar .stat-row telanjang),
     jadi janji berlebih itu baru (verifikasi kedua P1-D). */
  const shown = [
    tiles.projects ? 'Proyek' : null,
    tiles.ar_invoices ? 'piutang' : null,
    tiles.ap_bills ? 'hutang' : null,
  ].filter(Boolean);
  const heading = card && card.querySelector('.card-head h2');
  if (heading && shown.length) {
    heading.textContent = shown.length === 1
      ? shown[0]
      : `${shown.slice(0, -1).join(', ')} & ${shown[shown.length - 1]}`;
  }

  const body = el('.card-body', statRow([
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

  /* SATU pintu yang bisa dicapai papan ketik. Ketiga ubin di atas adalah <div>
     ber-onClick (kit.stat menambahkan cursor:pointer dan pendengar klik), jadi
     sampai verifikasi kedua P1-D kartu ini tidak punya satu pun elemen yang
     bisa di-Tab — dan katalog memberinya route yang tidak pernah dipakai.
     Kakinya menunjuk blok PERTAMA yang benar-benar dikirim server, supaya
     peran tanpa prj.view tidak diberi pintu ke layar yang akan menolaknya. */
  const door = tiles.projects
    ? { label: 'Semua proyek', route: 'r/projects' }
    : tiles.ar_invoices
      ? { label: 'Semua invoice termin', route: 'r/finance/ar-invoices' }
      : tiles.ap_bills
        ? { label: 'Semua tagihan vendor', route: 'r/finance/ap-bills' }
        : null;

  return el('div', [body, door ? footLink(door.label, () => navigate(door.route)) : null]);
}
