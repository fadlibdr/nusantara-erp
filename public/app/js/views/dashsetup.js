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
 * @param {Array<{id: string, size: string}>} current susunan TERSIMPAN apa adanya
 * @param {() => void} onSaved dipanggil setelah preferensi ditulis
 */
export function openDashboardSetup(current, onSaved) {
  /* Yang masuk ke sini adalah susunan TERSIMPAN, bukan hasil resolveLayout():
     yang kedua sudah membuang entri yang izinnya tidak dipegang, dan menyimpan
     kembali daftar yang sudah terpotong menghapusnya dari preferensi selamanya
     — kebalikan persis dari yang dijanjikan docblock resolveLayout ("TIDAK
     dihapus dari preferensinya … izin bisa kembali"). Terukur pada
     finance-manager tanpa hr.view: baris [payroll, ar-aging, tenggat] menjadi
     [ar-aging, tenggat] setelah satu klik Simpan (verifikasi P1-D). */
  const stored = normalise(current);
  const canSee = (id) => !BY_ID[id] || session.can(permOf(BY_ID[id]));

  /* Entri yang orangnya tidak boleh lihat: DISIMPAN DI SINI dengan posisi
     aslinya, tidak digambar, dan diletakkan kembali saat menulis. Entri
     hantu (id yang katalognya sudah tidak punya) justru MASUK ke draft —
     barisnya ditawarkan untuk dihapus, yang sebelumnya kode mati karena
     resolveLayout sudah membuangnya sebelum laci melihatnya. */
  const hidden = stored
    .map((entry, index) => ({ entry, index }))
    .filter(({ entry }) => !canSee(entry.id));

  // Salinan yang boleh diaduk-aduk: membatalkan laci harus benar-benar
  // membatalkan, termasuk urutan yang sudah diseret.
  let draft = stored.filter((entry) => canSee(entry.id)).map((entry) => ({ id: entry.id, size: entry.size }));

  /** draft + entri tersembunyi, masing-masing kembali ke posisi semula. */
  const merged = () => {
    const out = normalise(draft).filter((entry) => BY_ID[entry.id]);
    // Urut naik: setiap penyisipan menggeser yang di belakangnya, jadi indeks
    // yang lebih besar tetap benar hanya bila yang kecil dimasukkan lebih dulu.
    [...hidden].sort((a, b) => a.index - b.index).forEach(({ entry, index }) => {
      out.splice(Math.min(index, out.length), 0, { id: entry.id, size: entry.size });
    });
    return out;
  };

  const inUse = el('.dash-setup-list');
  const spare = el('.dash-setup-spare');

  /* Yang tidak terlihat tetap DISEBUT: sebuah daftar yang diam-diam lebih
     pendek daripada yang disimpan membuat "Simpan" terasa seperti tidak
     mengubah apa-apa padahal ia menulis daftar yang berbeda. */
  const hiddenNote = el('p.cell-sub', { style: { margin: '4px 0 12px' } });
  const paintHiddenNote = () => {
    hiddenNote.textContent = hidden.length
      ? `${hidden.length} widget lain ada di susunan Anda tetapi tidak dapat ditampilkan dengan izin Anda `
        + 'sekarang. Widget itu tetap disimpan pada posisinya — bila izinnya kembali, ia muncul lagi.'
      : '';
    hiddenNote.hidden = !hidden.length;
  };
  const body = el('div', [
    el('p.cell-sub', {
      text: 'Widget di bawah digambar berurutan dari kiri atas. Ukuran "lebar" memakai satu baris penuh.',
      style: { margin: '0 0 12px' },
    }),
    el('h3.dash-setup-head', { text: 'Di dasbor Anda' }),
    inUse,
    hiddenNote,
    el('h3.dash-setup-head', { text: 'Belum dipakai' }),
    spare,
  ]);

  /* FOKUS SETELAH paint(). Setiap Naik/Turun/Hapus/Tambah membangun ulang
     SELURUH daftar, jadi tombol yang sedang dipegang papan ketik ikut hilang —
     terukur: Enter pada 'Naik' baris ar-aging memindahkan barisnya dengan
     benar lalu melempar fokus ke <select> BARIS PERTAMA, dan setiap penekanan
     berikutnya menuntut empat Tab lagi (jalur "PAPAN KETIK LEBIH DULU" yang
     docblock di atas janjikan). paint() karena itu selalu dipanggil lewat
     paintAndFocus(), yang mengembalikan fokus ke kendali yang sama pada baris
     yang sama — atau ke tetangga terdekat bila barisnya baru saja dihapus. */
  function paintAndFocus(id, role) {
    paint();
    const row = id ? inUse.querySelector(`.dash-setup-row[data-id="${CSS.escape(id)}"]`) : null;
    const target = row
      ? (role === 'size' ? row.querySelector('select.dash-setup-size') : [...row.querySelectorAll('.btn')].find((b) => b.textContent.trim() === role && !b.disabled))
      : null;

    // Baris yang dituju tidak ada lagi (dihapus, atau tombolnya nonaktif di
    // ujung daftar): tombol pertama daftar, lalu daftar itu sendiri.
    (target
      || (row && [...row.querySelectorAll('.btn')].find((b) => !b.disabled))
      || inUse.querySelector('.dash-setup-row .btn:not([disabled])')
      || inUse
    ).focus?.();
  }

  const move = (index, delta) => {
    const target = index + delta;
    if (target < 0 || target >= draft.length) return;
    const [entry] = draft.splice(index, 1);
    draft.splice(target, 0, entry);
    // Tombol yang sama pada baris yang sama — yang baru saja pindah.
    paintAndFocus(entry.id, delta < 0 ? 'Naik' : 'Turun');
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
        /* Setelah menghapus, fokus pindah ke 'Hapus' baris TETANGGA (yang kini
           menempati posisi ini), bukan ke <body> — sebelumnya fokus hilang
           sama sekali dan papan ketik harus menelusuri dari awal dokumen. */
        button('Hapus', {
          size: 'sm',
          variant: 'ghost',
          title: `Hapus ${title} dari dasbor`,
          onClick: () => {
            draft.splice(index, 1);
            const next = draft[index] || draft[index - 1] || null;
            paintAndFocus(next ? next.id : null, 'Hapus');
          },
        }),
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
          // Yang baru ditambahkan ada di BAWAH daftar; fokus mengikutinya ke
          // sana, karena itulah baris yang orangnya baru saja buat.
          onClick: () => { draft.push({ id: widget.id, size: widget.size }); paintAndFocus(widget.id, 'Naik'); },
        }),
      ]));
    });
  }

  function paint() {
    paintInUse();
    paintSpare();
    paintHiddenNote();
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

  /* Bentuk yang dibandingkan penjaga: apa yang benar-benar akan DITULIS.
     Memakai `merged()` dan bukan `draft` supaya entri tersembunyi tidak
     pernah terhitung sebagai perubahan. */
  const asWritten = JSON.stringify(merged());

  const dialog = modal({
    title: 'Atur dasbor',
    width: 'wide',
    body,
    /* Penjaga "perubahan belum disimpan" — yang docblock di atas sebut sebagai
       ALASAN memilih modal(). `dirty` adalah opsi (ui.js: `if (!dirty) {
       close(); return true; }`), dan panggilan ini tidak melewatkannya sampai
       verifikasi kedua P1-D: Escape, klik latar, atau 'Batal' membuang
       susunan yang baru saja ditata tanpa satu pertanyaan pun. Terukur:
       sembilan baris ditata ulang, satu ketukan Escape, tidak ada dialog dan
       tidak ada yang tersisa. */
    dirty: () => JSON.stringify(merged()) !== asWritten,
    dirtyPrompt: {
      title: 'Buang perubahan susunan dasbor?',
      message: 'Urutan, ukuran dan pilihan widget yang baru Anda atur belum disimpan dan akan hilang.',
      // Bawaan modal() berbunyi "Buang isian"/"Kembali mengisi" — kata-kata
      // formulir, dan laci ini tidak punya satu isian pun.
      confirmLabel: 'Buang perubahan',
      cancelLabel: 'Kembali menata',
    },
    footer: el('div', { style: { display: 'flex', gap: '8px', width: '100%' } }, [
      button('Kembalikan ke bawaan', {
        variant: 'ghost',
        title: 'Susunan bawaan peran Anda',
        onClick: () => {
          draft = defaultLayout((session.user || {}).roles || [], (perm) => session.can(perm));
          // "Kembalikan ke bawaan" berarti bawaan, seluruhnya: entri
          // tersembunyi pun tidak boleh diselundupkan kembali ke dalamnya.
          hidden.length = 0;
          paint();
        },
      }),
      el('.spacer', { style: { flex: '1' } }),
      // requestClose(), bukan closeModal(): 'Batal' harus melewati penjaga
      // yang sama dengan Escape dan klik latar (pola periods.js).
      button('Batal', { variant: 'ghost', onClick: () => dialog.requestClose() }),
      button('Simpan', {
        variant: 'primary',
        onClick: () => {
          /* normalise() sekali lagi sebelum menulis: yang disimpan harus bentuk
             yang sama dengan yang dibaca, dan baris hantu (id yang katalognya
             sudah tidak punya) tidak ikut — orangnya baru saja melihat
             daftarnya, jadi menghapusnya di sini bukan kejutan. Entri yang
             izinnya sedang tidak dipegang justru IKUT, kembali ke posisi
             semula: ia tidak pernah ditawarkan untuk dihapus, jadi
             membuangnya di sini adalah keputusan yang tidak pernah diambil
             siapa pun. */
          const value = merged();
          prefs.set('dashboard.layout', value);
          closeModal();
          toast('Susunan dasbor disimpan.');
          onSaved();
        },
      }),
    ]),
  });
}
