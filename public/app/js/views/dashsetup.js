/*
 * Laci "Atur dasbor" (Fase 1 / P1-D) — tambah, hapus, ubah ukuran, urutkan.
 *
 * KENAPA MODAL DAN BUKAN PANEL GESER KEDUA. Aplikasi ini sudah punya SATU
 * tumpukan overlay dengan perangkap fokus, Escape bertingkat, penjaga
 * "perubahan belum disimpan", dan pembersih combobox (ui.js modal()). Laci
 * kedua berarti aturan fokus kedua yang harus benar sendiri — dan yang salah di
 * sana bukan tampilan melainkan orang yang tidak bisa keluar dengan papan
 * ketik. Yang dijanjikan ROADMAP adalah kemampuannya (tambah/hapus/ukuran/urut),
 * bukan arah gesernya.
 *
 * PAPAN KETIK LEBIH DULU, SERET BELAKANGAN. Setiap baris punya tombol Naik dan
 * Turun yang bekerja tanpa satu byte vendor pun; SortableJS dimuat MALAS saat
 * laci dibuka dan hanya menambahkan seret-lepas (termasuk sentuh) di atas
 * urutan yang sudah bisa diubah. Bila berkas vendornya gagal dimuat, laci ini
 * tetap berfungsi penuh dan mengatakannya sekali di konsol — tidak ada layar
 * yang mati karena 15 KB yang tidak sampai.
 *
 * DISIMPAN SEKALI, saat "Simpan": perubahan urutan yang menulis PUT per klik
 * akan mengirim belasan permintaan untuk satu kali menata. Nilainya masuk
 * preferensi `dashboard.layout` (whitelist server, plafon 16 KB — bentuk yang
 * ditolak server tidak pernah bisa dibuat dari laci ini).
 */

import { el, clear, button, modal, closeModal, toast, icon } from '../ui.js';
import { session } from '../api.js';
import { prefs } from '../prefs.js';
import { sortable } from '../vendorload.js';
import { CATALOG, BY_ID, SIZES, permOf, accentOf, defaultLayout, normalise } from './widgets/registry.js';

const SIZE_LABEL = { kecil: 'Kecil', sedang: 'Sedang', lebar: 'Lebar' };

/** Widget yang boleh dilihat orang ini, apa pun susunannya sekarang. */
function permitted() {
  return CATALOG.filter((widget) => session.can(permOf(widget)));
}

/**
 * @param {Array<{id: string, size: string}>} current susunan yang sedang tampil
 * @param {() => void} onSaved dipanggil setelah preferensi ditulis
 */
export function openDashboardSetup(current, onSaved) {
  // Salinan yang boleh diaduk-aduk: membatalkan laci harus benar-benar
  // membatalkan, termasuk urutan yang sudah diseret.
  let draft = current.map((entry) => ({ id: entry.id, size: entry.size }));

  const inUse = el('.dash-setup-list');
  const spare = el('.dash-setup-spare');
  const body = el('div', [
    el('p.cell-sub', {
      text: 'Widget di bawah digambar berurutan dari kiri atas. Ukuran "lebar" memakai satu baris penuh.',
      style: { margin: '0 0 12px' },
    }),
    el('h3.dash-setup-head', { text: 'Di dasbor Anda' }),
    inUse,
    el('h3.dash-setup-head', { text: 'Belum dipakai' }),
    spare,
  ]);

  const move = (index, delta) => {
    const target = index + delta;
    if (target < 0 || target >= draft.length) return;
    const [entry] = draft.splice(index, 1);
    draft.splice(target, 0, entry);
    paint();
  };

  function paintInUse() {
    clear(inUse);

    if (!draft.length) {
      inUse.appendChild(el('p.muted', {
        text: 'Dasbor Anda kosong. Tambahkan widget dari daftar di bawah.',
        style: { margin: '0 0 12px' },
      }));
      return;
    }

    draft.forEach((entry, index) => {
      const widget = BY_ID[entry.id];
      // Susunan bisa memuat id yang katalognya sudah tidak punya (widget
      // dicabut di rilis berikutnya). Barisnya tetap ditawarkan untuk DIHAPUS,
      // supaya tidak ada baris hantu yang hanya bisa dibuang lewat konsol.
      const title = widget ? widget.title : `${entry.id} (tidak dikenal lagi)`;
      const sizes = widget ? widget.sizes : SIZES;

      /* `onchange`, huruf kecil: el() memasang pendengar dengan key.slice(2)
         apa adanya, jadi 'onChange' mendaftar untuk peristiwa 'Change' yang
         tidak pernah terjadi. (button() punya jalannya sendiri — di sana
         onClick memang benar.) */
      const select = el('select.dash-setup-size', {
        'aria-label': `Ukuran ${title}`,
        onchange: (event) => { entry.size = event.target.value; },
      }, sizes.map((size) => el('option', {
        value: size, text: SIZE_LABEL[size] || size, selected: size === entry.size,
      })));

      inUse.appendChild(el('.dash-setup-row', {
        dataset: { id: entry.id, accent: widget ? String(accentOf(widget)) : '8' },
      }, [
        el('span.dash-setup-grip', { title: 'Seret untuk memindahkan' }, icon('menu', 14)),
        el('span.dash-setup-name', [
          el('span.cell-main', { text: title }),
          widget ? el('span.cell-sub', { text: widget.desc }) : null,
        ]),
        select,
        /* Tulisan, bukan ikon: PATHS ui.js tidak punya panah ke ATAS, dan
           menambah satu glyph hanya untuk laci ini menukar kejelasan dengan
           kerapian. Tombol bertulisan juga yang dibacakan pembaca layar. */
        button('Naik', { size: 'sm', variant: 'ghost', title: `Naikkan ${title}`, disabled: index === 0, onClick: () => move(index, -1) }),
        button('Turun', { size: 'sm', variant: 'ghost', title: `Turunkan ${title}`, disabled: index === draft.length - 1, onClick: () => move(index, 1) }),
        button('Hapus', { size: 'sm', variant: 'ghost', title: `Hapus ${title} dari dasbor`, onClick: () => { draft.splice(index, 1); paint(); } }),
      ]));
    });

    attachDrag();
  }

  function paintSpare() {
    clear(spare);
    const used = new Set(draft.map((entry) => entry.id));
    const available = permitted().filter((widget) => !used.has(widget.id));

    if (!available.length) {
      spare.appendChild(el('p.muted', {
        text: 'Seluruh widget yang boleh Anda lihat sudah ada di dasbor.',
        style: { margin: 0 },
      }));
      return;
    }

    available.forEach((widget) => {
      spare.appendChild(el('.dash-setup-row.spare', { dataset: { accent: String(accentOf(widget)) } }, [
        el('span.dash-setup-name', [
          el('span.cell-main', { text: widget.title }),
          el('span.cell-sub', { text: widget.desc }),
        ]),
        button('Tambah', {
          size: 'sm', variant: 'ghost', iconName: 'plus',
          onClick: () => { draft.push({ id: widget.id, size: widget.size }); paint(); },
        }),
      ]));
    });
  }

  function paint() {
    paintInUse();
    paintSpare();
  }

  /* Seret-lepas hanya MENAMBAH: urutan sudah bisa diubah tanpa tetikus, dan
     window.Sortable yang tidak pernah tiba tidak boleh mematikan laci. */
  let dragBound = false;
  function attachDrag() {
    if (dragBound || !inUse.childElementCount) return;
    sortable().then((Sortable) => {
      dragBound = true;
      Sortable.create(inUse, {
        handle: '.dash-setup-grip',
        animation: 140,
        onEnd: () => {
          // Kebenarannya adalah DOM setelah seret; draft dibangun ulang darinya
          // supaya tidak ada dua sumber urutan yang bisa berselisih.
          const order = Array.from(inUse.querySelectorAll('.dash-setup-row')).map((row) => row.dataset.id);
          draft = order
            .map((id) => draft.find((entry) => entry.id === id))
            .filter(Boolean);
          paint();
        },
      });
    }).catch((error) => {
      console.error('Atur dasbor: SortableJS gagal dimuat — urutan tetap bisa diubah lewat tombol Naik/Turun.', error);
    });
  }

  paint();

  modal({
    title: 'Atur dasbor',
    width: 'wide',
    body,
    footer: el('div', { style: { display: 'flex', gap: '8px', width: '100%' } }, [
      button('Kembalikan ke bawaan', {
        variant: 'ghost',
        title: 'Susunan bawaan peran Anda',
        onClick: () => {
          draft = defaultLayout((session.user || {}).roles || [], (perm) => session.can(perm));
          paint();
        },
      }),
      el('.spacer', { style: { flex: '1' } }),
      button('Batal', { variant: 'ghost', onClick: () => closeModal() }),
      button('Simpan', {
        variant: 'primary',
        onClick: () => {
          /* normalise() sekali lagi sebelum menulis: yang disimpan harus bentuk
             yang sama dengan yang dibaca, dan baris hantu (id yang katalognya
             sudah tidak punya) tidak ikut — orangnya baru saja melihat
             daftarnya, jadi menghapusnya di sini bukan kejutan. */
          const value = normalise(draft).filter((entry) => BY_ID[entry.id]);
          prefs.set('dashboard.layout', value);
          closeModal();
          toast('Susunan dasbor disimpan.');
          onSaved();
        },
      }),
    ]),
  });
}
