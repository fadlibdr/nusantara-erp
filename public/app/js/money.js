/* Input rupiah berformat — satu widget generik dalam cetakan combobox.js:
 * tidak tahu apa-apa tentang schema.js, memulangkan kontrak buildInput
 * { node, input, read } — ditambah write() untuk menanam ulang sebuah nilai.
 *
 * Mengganti <input type=number> polos yang dipakai field currency: 15000000000
 * dan 1500000000 tak terbedakan mata, dan satu scroll-wheel nyasar mengubah
 * angkanya diam-diam. Di sini keduanya hilang secara struktural — type=text
 * tidak punya increment roda ataupun panah — dan tampilannya berkelompok id-ID
 * ('15.000.000.000') selagi diketik.
 *
 * Minus di depan tetap sah: value_change pada CCO CRM memang bisa negatif, dan
 * min=0 yang lama tidak pernah benar-benar ditegakkan (modal tidak punya
 * <form>, jadi menolak '-' sekarang justru regresi). Satu koma desimal sampai
 * dua digit (currency schema memakai step 0.01). read() memulangkan Number
 * yang sama persis dengan yang dihasilkan input number lama — termasuk saat
 * membuka form: snapshot dirty-check openForm membandingkan read(), bukan teks
 * tampilan, jadi memformat tampilan tidak membuat form "kotor" saat dibuka. */

import { el } from './ui.js';

/**
 * Rapikan teks apa pun menjadi angka kanonik: digit, satu koma desimal
 * (maks 2 digit), minus hanya di depan. Tempelan lewat jalur yang sama:
 * 'Rp 15.000.000.000' kehilangan huruf dan titik pengelompoknya;
 * '15,000,000,000' (gaya en-US) punya lebih dari satu koma, berarti koma
 * ribuan — semuanya dibuang.
 *
 * dotDecimal (hanya di-set jalur tempel/seret): SATU-SATUNYA titik yang
 * diikuti 1-2 digit di ujung dibaca titik desimal en-US — '1234.56' dari
 * portal bank menjadi 1.234,56, bukan 123.456 (salah seratus kali lipat).
 * Titik lain tetap ribuan id-ID: '1.500' (3 digit di belakang) dan
 * '1.500.000' (lebih dari satu titik) tidak tersentuh. Aturan ini TIDAK
 * boleh berlaku saat mengetik: backspace pada tampilan '15.000' menghasilkan
 * '15.00', yang harus tetap terbaca 1500 — bukan berubah jadi 15,00.
 */
function sanitize(text, { dotDecimal = false } = {}) {
  const source = String(text ?? '');
  const trimmed = source.trim();

  /*
   * Negatif bila minus muncul SEBELUM digit pertama ('Rp -1.000' — bentuk
   * render rupiah() sendiri) atau seluruh angka terkurung tanda kurung
   * akuntansi ('(Rp 1.000)'). startsWith('-') yang lama melihat 'R' lebih
   * dulu dan membuang tandanya diam-diam — balik tanda pada angka kontrak.
   * '15-' yang terketik di tengah tetap positif: minusnya sesudah digit.
   */
  const negative = /^[^0-9]*-/.test(trimmed) || /^\(.*\d.*\)$/.test(trimmed);

  let working = source;
  if (dotDecimal) {
    // Grup 1 tanpa titik = titik yang cocok adalah satu-satunya; ')' opsional
    // di ekor supaya '(1,000.50)' gaya akuntansi tetap terbaca desimalnya.
    const m = /^([^.]*)\.(\d{1,2})\s*\)?\s*$/.exec(source);
    if (m) working = `${m[1].replace(/[^0-9]/g, '')},${m[2]}`;
  }

  let digits = working.replace(/[^0-9,]/g, '');
  const firstComma = digits.indexOf(',');
  if (firstComma !== -1 && digits.indexOf(',', firstComma + 1) !== -1) {
    digits = digits.replace(/,/g, '');
  }

  const commaIndex = digits.indexOf(',');
  let int = commaIndex === -1 ? digits : digits.slice(0, commaIndex);
  const dec = commaIndex === -1 ? null : digits.slice(commaIndex + 1, commaIndex + 3);
  int = int.replace(/^0+(?=\d)/, '');
  if (int === '' && dec !== null) int = '0';

  const body = dec === null ? int : `${int},${dec}`;
  if (body === '') return negative ? '-' : '';
  return negative ? `-${body}` : body;
}

/** Kelompokkan bagian bulat bertitik ala id-ID; koma desimal dibiarkan. */
function format(raw) {
  if (!raw) return '';
  const negative = raw.startsWith('-');
  const body = negative ? raw.slice(1) : raw;
  const commaIndex = body.indexOf(',');
  const int = commaIndex === -1 ? body : body.slice(0, commaIndex);
  const tail = commaIndex === -1 ? '' : body.slice(commaIndex);
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${negative ? '-' : ''}${grouped}${tail}`;
}

/* Ambang satuan terbilang, besar → kecil; findIndex mengambil yang pertama pas. */
const UNITS = [
  { div: 1e12, suffix: 'T' },
  { div: 1e9, suffix: 'M' },
  { div: 1e6, suffix: 'juta' },
  { div: 1e3, suffix: 'ribu' },
];

/**
 * Terbilang singkat ('1,5 M') untuk hint di bawah field — pembacaan kasar buat
 * mata yang sedang mengetik, BUKAN pengganti Terbilang resmi kwitansi di
 * server. Kosong untuk nol/kosong/di bawah seribu: 500 tidak butuh bantuan
 * dibaca, dan hint yang selalu menyala berhenti dilirik justru pada angka
 * miliaran yang jadi alasan ia ada.
 */
function terbilangSingkat(value) {
  if (!Number.isFinite(value)) return '';
  const abs = Math.abs(value);
  if (abs < 1000) return '';

  let index = UNITS.findIndex((unit) => abs >= unit.div);
  let scaled = Math.round((abs / UNITS[index].div) * 100) / 100;
  /* Pembulatan dua desimal bisa meluap melewati satuannya sendiri:
     999.999.999 → '1.000 juta' — naik satu satuan supaya terbaca '≈ 1 M'. */
  if (scaled >= 1000 && index > 0) {
    index -= 1;
    scaled = Math.round((abs / UNITS[index].div) * 100) / 100;
  }

  /* '≈' hanya saat dua desimal tidak menampung nilainya: 1.234.567.890 tampil
     '≈ 1,23 M', sedangkan 1.500.000.000 tampil '1,5 M' polos — hint yang
     membulatkan tanpa bilang ikut menyesatkan mata yang justru sedang
     memverifikasi. Uji modulo, bukan perkalian balik: 1,55 × 1e9 meleset
     sepersekian rupiah dalam float dan memalsukan '≈' pada nilai yang pas. */
  const exact = abs % (UNITS[index].div / 100) === 0;
  const text = scaled.toLocaleString('id-ID', { maximumFractionDigits: 2 });
  return `${exact ? '' : '≈ '}${value < 0 ? '-' : ''}${text} ${UNITS[index].suffix}`;
}

let hintSeq = 0;

/* Pemulihan caret adalah satu-satunya bagian input berformat yang lazim salah,
 * jadi ia dieja: hitung karakter bermakna (digit, koma, minus) di kiri caret
 * SEBELUM teks ditulis ulang, lalu jalan di teks baru sampai jumlah yang sama
 * terlewati. Titik pengelompok tidak dihitung — dialah yang berpindah-pindah. */
const SIGNIFICANT = /[0-9,-]/;

function countSignificant(text) {
  let count = 0;
  for (const ch of text) if (SIGNIFICANT.test(ch)) count += 1;
  return count;
}

function caretIndex(text, significant) {
  if (significant <= 0) return 0;
  let count = 0;
  for (let i = 0; i < text.length; i += 1) {
    if (SIGNIFICANT.test(text[i])) {
      count += 1;
      if (count === significant) return i + 1;
    }
  }
  return text.length; // tempelan yang kehilangan koma ribuannya mendarat di ujung
}

/** Tampilan kanonik untuk sebuah nilai awal (Number atau string desimal server). */
function displayFor(value) {
  if (value === null || value === undefined || value === '') return '';
  const n = Number(value);
  if (!Number.isFinite(n)) return '';
  // '15000000000.00' → '15.000.000.000'; sen tak-nol ('…,50') dipertahankan —
  // .00 gugur persis seperti Number() pada input number lama.
  const fixed = n.toFixed(2);
  const dot = fixed.indexOf('.');
  const int = fixed.slice(0, dot);
  const dec = fixed.slice(dot + 1).replace(/0+$/, '');
  return format(dec ? `${int},${dec}` : int);
}

/**
 * Input rupiah: input[type=text][inputmode=decimal] di dalam pembungkus
 * .input-affix.prefix ber-span 'Rp' yang sudah ada, sehingga app.css berlaku
 * tanpa perubahan di form maupun sel tabel baris (sel currency memang sudah
 * merender pembungkus affix ini hari ini). Di form (non-compact) affix itu
 * ditambah hint terbilang '1,5 M' di bawahnya — lihat terbilangSingkat.
 *
 * inputmode decimal, bukan numeric: keypad numeric iOS tidak punya tombol koma
 * sama sekali — sen ',50' jadi tak terketik di lapangan; decimal memberi
 * pemisah desimal lokal (minus memang tetap absen di sebagian keyboard mobile
 * — keterbatasan bawaan platform).
 *
 * Event 'input' native menggelembung dari input di dalam, jadi pendengar
 * subtotal buildLines dan repaint total-baris bekerja tanpa perubahan; `input`
 * pada objek kembalian menunjuk input di dalam supaya setFieldError dan lem
 * aria-labelledby milik field() di ui.js tetap bekerja.
 */
export function moneyInput({ value, compact = false } = {}) {
  const input = el('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off' });
  input.value = displayFor(value);

  const readValue = () => {
    // Payload bertipe sama dengan input number lama: '' → null, selainnya Number.
    const text = input.value.replace(/\./g, '').replace(',', '.');
    if (text === '' || text === '-') return null;
    const n = Number(text);
    return Number.isFinite(n) ? n : null;
  };

  /* Hint terbilang di bawah field (temuan #20): nilai kontrak miliaran
   * diverifikasi lewat '1,5 M', bukan dengan menghitung titik. Tidak dirakit
   * pada sel tabel baris (compact) — baris 31px tidak punya tempat untuk baris
   * kedua, dan lima belas hint menumpuk di kolom uang PO adalah derau.
   *
   * Kelas 'help' menumpang gaya .field .help yang sudah ada; aria-hidden +
   * aria-describedby mengikuti pola combobox.js: field() membungkus kontrol
   * dalam <label>, dan teks polos di dalamnya dilebur jadi NAMA field oleh
   * screen reader — '≈ 1,5 M' yang berubah tiap ketikan tidak boleh jadi nama.
   * Simpul yang dirujuk aria-describedby tetap terbaca meski aria-hidden. */
  const hint = compact ? null : el('.help.money-hint', { id: `money-hint-${++hintSeq}`, 'aria-hidden': 'true' });

  function syncHint() {
    if (!hint) return;
    const text = terbilangSingkat(readValue());
    hint.textContent = text;
    hint.hidden = text === '';
    if (text) input.setAttribute('aria-describedby', hint.id);
    else input.removeAttribute('aria-describedby');
  }

  input.addEventListener('input', (event) => {
    const before = input.value;
    const caret = input.selectionStart ?? before.length;
    const significant = countSignificant(before.slice(0, caret));

    // Titik desimal en-US hanya masuk lewat tempel/seret — mengetik tidak
    // pernah menghasilkannya (lihat sanitize soal backspace pada '15.000').
    const pasted = event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop';
    const text = format(sanitize(before, { dotDecimal: pasted }));
    if (text !== before) input.value = text;

    const position = caretIndex(input.value, significant);
    input.setSelectionRange(position, position);
    syncHint();
  });

  // Sisa ketikan yang belum selesai ('1.500,' atau '-') dirapikan saat fokus
  // pergi. read()-nya sudah sama sebelum dan sesudah, jadi tidak ada event
  // yang perlu dipalsukan untuk penghitung subtotal.
  input.addEventListener('blur', () => {
    const trimmed = sanitize(input.value).replace(/,$/, '').replace(/^-$/, '');
    const text = format(trimmed);
    if (text !== input.value) input.value = text;
    syncHint(); // '1.500,' yang dirapikan bisa menggeser nilai yang dibaca hint
  });

  const affix = el('.input-affix.prefix', [el('span', { text: 'Rp' }), input]);
  /* Sel tabel baris memakai affix telanjang seperti sebelumnya — pembungkus
     ekstra di dalam td mengubah tinggi baris; di form, pembungkus polos ini
     tidak tersentuh CSS mana pun dan .input-affix di dalamnya tetap cocok. */
  const node = hint ? el('div', [affix, hint]) : affix;
  syncHint();

  return {
    node,
    input,
    read: readValue,
    /* Menanam ulang sebuah NILAI (bukan teks tampilan) — dipakai layar
     * Pengaturan saat 'Batalkan perubahan' dan 'Kembalikan ke bawaan'
     * mengembalikan Number dari server ke kontrol yang sudah diketik.
     * Wajib lewat displayFor: menulis Number mentah ke input.value memberi
     * bentuk JS-nya ('12500000.5'), lalu read() membuang titik itu sebagai
     * pemisah ribuan id-ID dan memulangkan 125000005 — membatalkan perubahan
     * malah menaikkan parameternya sepuluh kali lipat. Sekaligus mengembalikan
     * pengelompokan '12.500.000,5' yang justru jadi alasan widget ini ada. */
    write: (next) => { input.value = displayFor(next); syncHint(); },
  };
}
