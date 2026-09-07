/**
 * charts.js — grafik SVG tangan Nusantara ERP (Fase 1, P1-A). BERKAS INI REFERENSI API-NYA.
 *
 * Lima fungsi murni yang mengembalikan satu elemen <svg> siap ditempel: tanpa fetch,
 * tanpa state, tanpa global, tanpa dependensi (0 KB vendor — keputusan pemilik
 * ROADMAP-HASHMICRO §5 #2: grafik ditulis sendiri supaya mewarisi token tema dan blok
 * cetak, dan warnanya bisa diukur harness S20). Pemanggil menyediakan data yang sudah
 * dihitung server; di sini hanya geometri.
 *
 *   lineChart({ series, xLabels?, xFormat?, yFormat?, yMax?, yMin?, yStep?, width?, height?, ariaLabel, sourceNote?, legend? })
 *     series[]: { label, points, token?, dash?, dots?, area?, width? } — `width` = tebal garis px (bawaan 2, dijepit 1–4)
 *     series : [{ label, points: [{ x?, y, title?, r?, token? }], dash?|dashed?, area?, token?, dots? }]
 *              dash: pola putus-putus seri sebagai string SVG ('5 3' rencana, '2 4' baseline);
 *              dashed:true = '6 4'. token: token warna eksplisit '--chart-n' (bawaan: posisi seri,
 *              1..8 berulang) — points[].token menimpanya per titik (GRN vs PO pada SATU garis
 *              kronologis). dots: true (bawaan) | false (garis saja; run satu titik tetap bertitik)
 *              | 'last' (hanya titik terakhir). points[].title menggantikan <title> bawaan
 *              "label — x: y" (kurva-S: "Minggu 12 — rencana 62 %, aktual 48 %"); points[].r =
 *              jari-jari titik itu (titik as-of EVM 4) — hanya angka > 0 yang dipakai: r ≤ 0 atau
 *              bukan angka memakai jari-jari bawaan (r negatif adalah atribut yang ditolak Chromium
 *              tanpa menggambar titiknya, r 0 titik tak terlihat yang masih membawa <title>).
 *              token yang tidak dikenal (bukan --chart-1..8/grid/axis/text/today/weekend/baseline)
 *              DIABAIKAN → warna posisi seri, bukan stroke:none diam-diam. Semua murni & lewat
 *              token — paritas P1-E.
 *              x = angka (indeks/skala apa pun) ATAU string tanggal ISO (jadi sumbu tanggal);
 *              x kosong = indeks titik. y null/NaN/undefined = CELAH (garis putus), bukan nol.
 *              Campuran: begitu ada x tanggal, titik yang x-nya bukan tanggal DIBUANG sebagai
 *              celah (svg data-dropped-x = jumlahnya) — tidak diformat jadi "01 Jan 70". Seri
 *              tanpa satu pun titik tergambar tetap di legenda sebagai "label (tanpa data)".
 *     xLabels: label per indeks x (['M1','M2',…]); xFormat(x) menang bila ada; bawaan:
 *              tanggal → "05 Sep 2026" (bentuk fmt.date format.js), angka → id-ID.
 *     yFormat: (angka) → teks; dipakai di sumbu DAN <title> tiap titik (bawaan id-ID, 2 desimal).
 *     yMin/yMax: sumbu dipaksa ke nilai itu (kurva EVM memakai yMax ≥ 100 hasil hitungannya
 *              sendiri — aturan ">100 % dipertahankan" milik pemanggil, bukan grafik).
 *     yStep  : langkah tick tetap (EVM: yMax 125 + yStep 25 → 0/25/50/75/100/125; tanpa yStep
 *              langkah rapi otomatis memberi 0/50/100 dan puncak sumbu tak berlabel).
 *              Bawaan: domain data SELALU memuat nol (nilai negatif → sumbu memotong nol);
 *              tren harga yang tidak boleh mulai dari nol memberi yMin sendiri.
 *              Nilai DI LUAR sumbu yang dipaksa: garis dan area dipotong pada tepi plot
 *              (<clipPath>), titiknya ditempel di tepi plot dengan data-outside="above|below"
 *              dan <title> "… (di luar sumbu)" — tidak pernah digambar keluar kotak svg
 *              (menimpa kartu di atas/bawahnya), tidak pula dibengkokkan diam-diam ke tepi.
 *     Satu titik → titik saja (tanpa garis). Semua titik kosong → placeholder "Belum ada data".
 *
 *   barChart({ categories, series, stacked?, horizontal?, yFormat?, yMax?, yMin?, width?, height?, ariaLabel, sourceNote?, legend? })
 *     categories: ['A','B',…]; series: [{ label, values: [angka|null,…] }] sepanjang categories.
 *     Bawaan berkelompok; stacked menumpuk (positif ke atas, negatif ke bawah); horizontal
 *     menukar sumbu (kategori di kiri, satu baris 26 px per kategori — tinggi otomatis).
 *     null = tidak ada batang (bukan batang nol); nol = garis rambut di sumbu nol dengan <title>.
 *
 *   donutChart({ slices, centerLabel?, centerSub?, valueFormat?, ariaLabel, sourceNote? })
 *     Lebar tetap 360 (ukuran intrinsik = viewBox); legenda dibungkus per kata ke kolom 128 px
 *     (tinggi mengikuti jumlah barisnya), sehingga ukuran huruf tidak bergantung pada panjang
 *     label di viewport mana pun. slices: [{ label, value }]; hanya value > 0 yang digambar (yang 0 tetap di legenda
 *     sebagai 0 %); value null/NaN/teks → baris legenda "? (tidak dihitung)", negatif →
 *     "(bukan bagian dari keseluruhan)" — keduanya tanpa swatch, tidak pernah disembunyikan;
 *     satu irisan → cincin penuh; tak ada yang > 0 → placeholder, dan bila ada barisnya
 *     placeholder itu menyebutnya: "Belum ada data yang bisa dihitung" + "3 baris tidak
 *     digambar: 1 negatif, 2 tak terukur" (svg data-excluded = jumlah baris).
 *
 *   sparkline({ points, width?, height?, ariaLabel, format? })
 *     points: [angka|null,…] (null = celah). 120×32 bawaan, tanpa sumbu; <title> di garis
 *     (n titik, min, maks, terakhir) dan di titik terakhir. Satu titik → titik saja.
 *
 *   ganttChart({ rows, from?, to?, zoom?, today?, weekends?, ariaLabel, sourceNote?, labelWidth?, timelineWidth?, rowHeight? })
 *     rows: [{ label, start:'YYYY-MM-DD', end, progress?(0..1), baselineStart?, baselineEnd?, level? }]
 *     Baca-saja (P1-H memakainya). from/to bawaan = rentang data. zoom 'week' (garis tiap
 *     Senin, bulan di baris atas) | 'month' (garis tiap tanggal 1, tahun di atas). today
 *     bawaan = hari ini; garis "Hari ini" hanya bila di dalam rentang. weekends: bayangan
 *     Sabtu–Minggu. Bar baseline digambar DI BAWAH bar aktual (DOM lebih dulu, sedikit lebih
 *     rendah supaya selisihnya terlihat). end kosong → bar terbuka sampai tepi kanan dengan
 *     tepi putus-putus dan <title> yang mengatakannya; start kosong → terbuka di kiri; dua-
 *     duanya kosong → teks "tanpa tanggal". Data yang tidak konsisten tidak dinormalkan:
 *     tanggal tidak valid ('2026-13-45') → teks "tanggal tidak valid: …" (bukan 14 Feb 2027),
 *     end < start → teks "tanggal selesai sebelum mulai (…)", baris di luar from..to → teks
 *     "di luar rentang (…)" — baris terbuka menyebut hanya ujung yang ada: "selesai …, sebelum
 *     rentang (mulai belum ditetapkan)" / "mulai …, setelah rentang (selesai belum ditetapkan)",
 *     batas from/to tidak pernah dikutip seolah tanggal tugas; progress di luar 0..1 → lapisan progres dijepit tetapi <title>
 *     menyebut angka aslinya "(di luar 0–100 %)", baseline terbalik/tidak valid → tidak digambar
 *     + catatan "· baseline tidak valid …" di <title> bar DAN di teks baris yang tanpa bar.
 *     Baris berteks yang punya baseline sah tetap menggambar baseline-nya, teksnya di atas bar
 *     itu; legenda 'Baseline' hanya bila ada baseline yang tergambar di dalam rentang.
 *     Ketergantungan (dependency) TIDAK digambar — ditunda ke Fase 2
 *     (kolomnya tidak ada).
 *     Lebar alami labelWidth+timelineWidth (900); viewBox tetap responsif, tetapi svg diberi
 *     min-width 80 % lebar alami supaya teks 11 px tidak menyusut di bawah ±9 px — bungkus
 *     dengan <div class="chart-scroll"> agar menggulir mendatar di ponsel.
 *
 * Aturan yang berlaku untuk semua:
 *   • Warna HANYA lewat token CSS: seri ke-n memakai var(--chart-n) (1..8, berulang), lainnya
 *     --chart-grid/--chart-axis/--chart-text/--chart-today/--chart-weekend/--chart-baseline
 *     (app.css, terdefinisi di dua tema + blok cetak). Tidak ada literal warna di berkas ini.
 *     Setiap bentuk berwarna membawa data-token="--chart-n" dan data-paint="fill|stroke" —
 *     itulah yang diukur harness S20 (nilai terkomputasi == nilai token).
 *   • Setiap MARK (titik, batang, irisan, bar gantt, garis sparkline) berkelas .mark dan
 *     membawa tepat satu <title> berisi nilai berformat — untuk pembaca layar dan harness
 *     (jumlah `.mark > title` == jumlah .mark). Satu-satunya <title> lain: label gantt yang
 *     dipotong (data-truncated="true") membawa nama lengkapnya.
 *   • Responsif: viewBox + width:100 % (kelas .chart). Teks memakai var(--font) 11 px dengan
 *     angka tabular (kelas .chart-lib di app.css).
 *   • Legenda di dalam svg (swatch = token seri; garis putus-putus ikut ditampilkan) dan
 *     catatan sumber opsional (sourceNote) di bawahnya — seperti grafik tangan yang ada.
 *   • Kosong = jujur: svg RINGKAS 360×64 berisi teks "Belum ada data", data-empty="true",
 *     kelas is-empty (pemanggil boleh menukarnya dengan ui.emptyState()); sparkline kosong
 *     tetap seukuran sparkline. Tidak pernah sumbu kosong yang berpura-pura nol.
 *   • Cetak: blok @media print app.css menukar token ke abu-abu dan memberi seri 2..8 pola
 *     putus-putus/garis tepi — pembeda bentuk, bukan warna saja.
 */

const NS = 'http://www.w3.org/2000/svg';
const DAY = 86400000;
/* Lebar glyph TAKSIRAN pada 11 px --font, untuk memperkirakan lebar label tanpa layout DOM
   (fungsi ini murni dan bisa dipanggil sebelum svg ditempel). Diukur getBBox di Chromium 151
   dengan tumpukan --font (5 Sep 2026, px/glyph): teks legenda 4,99–5,12, 'Februari' 5,12,
   'Rp 20.000.000.000' 5,51, label tanggal '05 Jan 2026'…'29 Sep 2026' 5,45–5,67 (maks 62,4 px),
   tetapi 'September' 6,02 (54 px), deretan angka polos '0123456789' 6,12, dan label pendek 3
   huruf ('Mei', 'Jun', 'K1…') 6,4–8,2. 5,6 adalah batas atas teks CAMPURAN (huruf + angka +
   spasi) sepanjang ≥ 8 huruf, BUKAN batas atas semua teks — kata berglyph lebar, angka polos,
   dan label pendek melampauinya. Pemakainya tidak bergantung pada taksiran sebagai batas
   atas: label tepi ditambatkan ke ujung svg (xLabelBox — tidak pernah terpotong berapa pun
   lebar aslinya), penjarangan menyisakan celah 6 px (thin), label kategori batang dipotong ke
   pita − 4 px, label gantt diklip pada labelWidth − 8; harness S20 mencatat px/glyph terukur
   setiap label tick (tick_glyph_px_max). Nilai lama 6,3 memotong 'Februari' jadi 'Februa…'
   pada pita 55,7 px padahal teks aslinya 41 px. Legenda donat TIDAK memakai CHAR_W: kolomnya
   120 px tanpa jangkar/pemotongan, jadi ia membungkus menurut taksiran per kelas glyph
   (glyphWidth) — verifikasi P1-A putaran 2: 'PEMBANGUNAN GEDUNG' 18 huruf lolos 21 huruf/baris
   tetapi 137 px lebar, keluar 9,5 px dari viewBox 360. */
const CHAR_W = 5.6;
/* Lebar glyph per KELAS pada 11 px --font, diukur getBBox 40 glyph berulang di Chromium pada
   tumpukan --font host ini (5 Sep 2026, desktop 1440 / ponsel 390 sama ±0,05): spasi 3,1;
   i j l ' 2,4–2,5; f I t . , : ; ! / [ ] 2,9–3,1; r - ( ) " 3,7–3,9; c k s v x y z 5,5;
   angka + a b d e g h n o p q u + L ? _ + – 6,1; T F Z 6,7; A B E K P S V X Y & 7,3–7,4;
   C D H N R U w 7,9–8,0; G O Q 8,5; m M 9,2; W % 9,8–10,4; — 11,0. Setiap kelas memakai batas
   ATAS anggotanya. '1' (5,3 sendiri) dan 'J' (5,5) dihitung 6,2: tabular-nums (.chart-lib)
   menyamakan advance semua angka, dan 'BAJA' terukur 28,2 = J 6,2 — jumlah per glyph diuji
   terhadap lebar string utuh 135 sampel (kata proyek huruf kecil/BESAR/Judul/angka/campur +
   kasus tepi): terukur ÷ taksiran maks 1,004 ('WWWWWWWWWWW' 114,8 vs 114,4; 'bandara' 41,3 vs
   41,1), 'PEMBANGUNAN GEDUNG' 137,3 vs 138,6, '1234567890 1234567890' 126,0 vs 127,2 — kolom
   120 px + 8 px sampai tepi svg menampung selisih 6,7 %. Huruf besar di luar tabel (É, Ø) dihitung 8,0,
   lainnya 6,2. Font klien lain (Segoe UI, SF, Roboto) umumnya lebih sempit; bila lebih lebar,
   klip legenda (donutChart) menahan sisanya. */
const GLYPH_CLASSES = [[' ', 3.2], ["ijl'", 2.6], ['fIt.,:;!/[]', 3.1], ['r-()"', 3.9], ['cksvxyz', 5.5], ['TFZ', 6.8], ['ABEKPSVXY&', 7.4], ['CDHNRUw', 8.0], ['GOQ', 8.6], ['mM', 9.2], ['W%', 10.4], ['—', 11.0]];
const GLYPH_WIDTH = new Map(GLYPH_CLASSES.flatMap(([chars, w]) => [...chars].map((ch) => [ch, w])));
const FONT = 11;
const SERIES_TOKENS = 8;
const EMPTY_TEXT = 'Belum ada data';

/* Satu-satunya state modul: penghitung id <clipPath>, supaya dua grafik pada halaman yang
   sama tidak berbagi id (url(#…) merujuk id pertama di dokumen). */
let clipSeq = 0;

const numberFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const percentFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });
/* Label tanggal bawaan = bentuk yang sama dengan format.js fmt.date ("05 Sep 2026", tahun 4
   digit): tahun 2 digit adalah format tanggal ketiga di aplikasi (CONVENTIONS §11 — format
   dari format.js). Pemanggil yang mengoper xFormat tetap menang. */
const shortDate = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
const dayMonth = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', timeZone: 'UTC' });
const fullDate = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
const monthYear = new Intl.DateTimeFormat('id-ID', { month: 'short', year: 'numeric', timeZone: 'UTC' });
const monthOnly = new Intl.DateTimeFormat('id-ID', { month: 'short', timeZone: 'UTC' });

/* ------------------------------------------------------------ pembantu DOM */

function make(tag, attrs = {}, text) {
  const node = document.createElementNS(NS, tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === null || value === undefined || value === false) continue;
    node.setAttribute(key, typeof value === 'number' ? String(round(value)) : value);
  }
  if (text !== undefined) node.textContent = String(text);
  return node;
}

function round(value) {
  return Math.round(value * 100) / 100;
}

/** Warna lewat token saja; data-token/data-paint adalah yang diukur harness. */
function paint(node, prop, token) {
  node.style[prop] = `var(${token})`;
  node.setAttribute('data-token', token);
  node.setAttribute('data-paint', prop);
  return node;
}

function mark(node, title) {
  node.classList.add('mark');
  node.appendChild(make('title', {}, title));
  return node;
}

function seriesToken(index) {
  return `--chart-${(index % SERIES_TOKENS) + 1}`;
}

/** Token eksplisit dari pemanggil hanya bila token grafik yang TERDEFINISI di app.css (dua tema +
    blok cetak); selain itu null → pemanggil mendapat warna posisi seri. '--chart-nope' dulu lolos
    dan var() yang tidak terdefinisi menjadi stroke:none — garis hilang tanpa pesan. */
function tokenOf(value) {
  return typeof value === 'string' && /^--chart-(?:[1-8]|grid|axis|text|today|weekend|baseline)$/.test(value) ? value : null;
}

/** Jari-jari titik dari pemanggil: hanya angka > 0. */
function radiusOf(value) {
  const r = finite(value);
  return r !== null && r > 0 ? r : null;
}

function finite(value) {
  if (value === null || value === undefined || value === '') return null;
  const n = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(n) ? n : null;
}

function formatter(fn, fallback = (v) => numberFormat.format(v)) {
  return typeof fn === 'function' ? fn : fallback;
}

function truncate(text, maxChars) {
  const s = String(text ?? '');
  if (maxChars < 2) return s.length > 1 ? '…' : s;
  return s.length > maxChars ? `${s.slice(0, maxChars - 1)}…` : s;
}

function textWidth(text) {
  return String(text ?? '').length * CHAR_W;
}

function glyphWidth(ch) {
  const known = GLYPH_WIDTH.get(ch);
  if (known !== undefined) return known;
  return ch !== ch.toLowerCase() ? 8.0 : 6.2;
}

/** Taksiran lebar teks per kelas glyph (GLYPH_CLASSES) — untuk legenda donat. */
function estimateWidth(text) {
  let w = 0;
  for (const ch of String(text ?? '')) w += glyphWidth(ch);
  return w;
}

/** Kotak label sumbu-x: berjangkar tengah, kecuali label yang akan keluar dari tepi svg — yang
    terakhir ditambatkan ke ujung kanan ('10 Jun 2026' berpusat di x = width − 16 dulu terpotong
    ±12 px), yang pertama ke ujung kiri. Mengembalikan jangkar DAN tepi kiri/kanan taksirannya:
    penjarangan (thin) dan penggambaran (xTickLabel) memakai kotak yang sama — label yang
    ditambatkan ke ujung bergeser setengah lebarnya (±30 px untuk tanggal) dari pusatnya. */
function xLabelBox(cx, label, width) {
  const w = textWidth(label);
  if (cx + w / 2 > width - 1) return { anchor: 'end', left: cx - w, right: cx };
  if (cx - w / 2 < 1) return { anchor: 'start', left: cx, right: cx + w };
  return { anchor: 'middle', left: cx - w / 2, right: cx + w / 2 };
}

function xTickLabel(svg, cx, y, label, width, extraClass = '') {
  svg.appendChild(make('text', { class: `chart-tick${extraClass ? ` ${extraClass}` : ''}`, x: cx, y, 'text-anchor': xLabelBox(cx, label, width).anchor }, label));
}

function frame(kind, width, height, ariaLabel) {
  const svg = make('svg', { viewBox: `0 0 ${round(width)} ${round(height)}`, role: 'img', 'aria-label': ariaLabel });
  svg.setAttribute('class', `chart chart-lib chart-${kind}`);
  return svg;
}

/** Placeholder "Belum ada data" berukuran RINGKAS (360×64, max-width 360 px) untuk semua
    jenis kecuali sparkline (yang berukuran intrinsik): viewBox selebar grafik penuh (720/900)
    menyusutkan teks 13 px jadi 7 px (garis/batang) atau 4,4 px (gantt) di ponsel 390 px, dan
    di desktop menyisakan kartu kosong 290–300 px untuk satu baris teks (diukur 5 Sep 2026). */
function placeholder(kind, ariaLabel, { width = 360, height = 64, fit = true, message = EMPTY_TEXT, sub } = {}) {
  const svg = frame(kind, width, height, ariaLabel);
  svg.classList.add('is-empty');
  svg.dataset.empty = 'true';
  if (fit) { svg.setAttribute('width', width); svg.setAttribute('height', height); svg.style.maxWidth = `${width}px`; }
  /* `sub` = baris kedua 11 px yang mengatakan MENGAPA kosong (donat yang semua barisnya
     dikecualikan); tanpa sub, satu baris di tengah seperti semula. */
  svg.appendChild(make('text', { class: 'chart-empty', x: width / 2, y: sub ? height / 2 - 2 : height / 2 + FONT / 3, 'text-anchor': 'middle' }, message));
  if (sub) svg.appendChild(make('text', { class: 'chart-empty-sub chart-tick', x: width / 2, y: height / 2 + 15, 'text-anchor': 'middle' }, sub));
  return svg;
}

/** Klip area plot (+pad px, bawaan 2 untuk tebal garis) untuk garis/area: nilai di luar
    yMin/yMax yang dipaksa tidak boleh terlukis di luar svg; pad 0 = klip tepat ke kotaknya
    (legenda donat ke viewBox). Mengembalikan nilai atribut clip-path. */
function plotClip(svg, x, y, w, h, pad = 2) {
  const id = `chart-clip-${++clipSeq}`;
  const defs = make('defs');
  const clip = make('clipPath', { id });
  clip.appendChild(make('rect', { x: x - pad, y: y - pad, width: w + 2 * pad, height: h + 2 * pad }));
  defs.appendChild(clip);
  svg.appendChild(defs);
  return `url(#${id})`;
}

function noteLine(svg, text, x, y) {
  if (!text) return 0;
  svg.appendChild(make('text', { class: 'chart-note', x, y }, text));
  return 16;
}

/* --------------------------------------------------------------- legenda */

/** items: [{ label, token, kind: 'line'|'box', dash?, opacity?, series? }] — baris-baris
    yang sudah dibungkus ke lebar `width`; tinggi = jumlah baris × 16. */
function legendRows(items, width, x0 = 0) {
  const rows = [[]];
  let x = x0;
  items.forEach((item) => {
    const w = 16 + 6 + textWidth(item.label) + 18;
    if (x + w > width - 8 && rows[rows.length - 1].length) {
      rows.push([]);
      x = x0;
    }
    rows[rows.length - 1].push({ ...item, x, w });
    x += w;
  });
  return rows;
}

function drawLegend(svg, rows, y0) {
  rows.forEach((row, r) => {
    const y = y0 + r * 16;
    row.forEach((item) => {
      if (item.kind === 'line') {
        const line = make('line', { class: 'legend-swatch series-line', x1: item.x, x2: item.x + 16, y1: y - 4, y2: y - 4, 'stroke-width': item.width ?? 2.5, 'stroke-dasharray': item.dash ?? null, 'data-series': item.series });
        svg.appendChild(paint(line, 'stroke', item.token));
      } else if (item.kind === 'dot') {
        const dot = make('circle', { class: 'legend-swatch series-point', cx: item.x + 8, cy: y - 4, r: 3, 'data-series': item.series });
        svg.appendChild(paint(dot, 'fill', item.token));
      } else {
        const box = make('rect', { class: 'legend-swatch', x: item.x, y: y - 9, width: 12, height: 10, rx: 2, 'fill-opacity': item.opacity, 'data-series': item.series });
        svg.appendChild(paint(box, 'fill', item.token));
      }
      svg.appendChild(make('text', { class: 'chart-legend', x: item.x + 22, y, 'data-nodata': item.nodata ? 'true' : null }, item.label));
    });
  });
  return rows.length * 16;
}

/**
 * Lebar viewBox yang masuk akal untuk lebar layar sekarang.
 *
 * charts.js menggambar pada viewBox tetap dan CSS meregangkannya ke lebar
 * kartunya, jadi teks 11 px di dalam svg 720 yang dipasang pada kartu 328 px
 * TERBACA 5,0 px — di bawah setiap lantai keterbacaan yang dipakai aplikasi ini
 * di tempat lain. Selama legenda hidup di DOM (sebelum P1-E) itu tidak terasa
 * pada dua grafik proyek; sejak legendanya masuk ke dalam svg, ia terasa
 * (verifikasi P1-E, diukur 390×844: legenda dan tick 5,0 px, garis 0,91 px).
 *
 * Yang dikembalikan adalah viewBox, bukan piksel layar: pada ponsel viewBox
 * yang mendekati lebar kartunya membuat skala ≈ 1, sehingga 11 px tetap 11 px.
 */
export function chartWidth(wide = 720, narrow = 380) {
  return typeof window !== 'undefined' && window.innerWidth <= 560 ? narrow : wide;
}

/* ---------------------------------------------------------------- skala */

function niceStep(rough) {
  if (!(rough > 0)) return 1;
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const n = rough / magnitude;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * magnitude;
}

/** Domain nilai: memuat nol kecuali min/max dipaksa; tepi dibulatkan ke langkah rapi
    (atau ke `fixedStep` bila pemanggil menetapkannya — yStep). */
function domain(values, min, max, tickCount = 4, fixedStep) {
  let dataLo = 0;
  let dataHi = 0;
  values.forEach((v) => { if (v < dataLo) dataLo = v; if (v > dataHi) dataHi = v; }); // bukan spread: 10 rb titik aman
  let lo = finite(min) ?? dataLo;
  let hi = finite(max) ?? dataHi;
  if (lo === hi) {
    if (lo === 0) hi = 1;
    else if (lo > 0) lo = 0;
    else hi = 0;
  }
  const step = finite(fixedStep) > 0 ? finite(fixedStep) : niceStep((hi - lo) / tickCount);
  const bothForced = finite(min) !== null && finite(max) !== null;
  if (finite(min) === null) lo = Math.floor(lo / step + 1e-9) * step;
  if (finite(max) === null) hi = Math.ceil(hi / step - 1e-9) * step;
  /* Jangkar garis kisi. Bila pemanggil memaksa KEDUA tepinya, tepi itulah
     jangkarnya: sumbu yang dipaksa 50..350 dengan langkah 75 harus digaris di
     50/125/200/275/350 — lima garis yang membentang penuh — bukan di
     75/150/225/300, yang membuang label lantai DAN label langit-langit.
     Terukur pada tren harga satuan (yMin/yMax/yStep ketiganya dipaksa): 5 garis
     kisi hanya pada ~6 % pasangan harga, dan demo lolos cuma karena kedua
     harganya sama (verifikasi P1-E). Bila salah satu tepi dihitung sendiri,
     jangkarnya tetap kelipatan langkah — di sanalah "langkah rapi" berarti
     "angka rapi". */
  const ticks = [];
  const first = bothForced ? lo : Math.ceil(lo / step - 1e-9) * step;
  for (let v = first; v <= hi + step * 1e-6; v += step) ticks.push(Math.abs(v) < step * 1e-9 ? 0 : +v.toPrecision(12));
  return { lo, hi, ticks };
}

function xValue(point, index) {
  const raw = point && typeof point === 'object' ? point.x : undefined;
  if (raw === null || raw === undefined) return { x: index, date: false };
  if (typeof raw === 'number') return { x: Number.isFinite(raw) ? raw : index, date: false };
  const parsed = Date.parse(raw);
  return Number.isFinite(parsed) ? { x: parsed, date: true } : { x: index, date: false };
}

/** Indeks label sumbu-x yang digambar. `centers` = pusat tiap label (koordinat svg), `labelAt(i)`
    = teksnya (dipanggil malas: hanya kandidat yang diformat — 10 rb titik tidak memformat 10 rb
    tanggal). Irama: paling banyak floor(plotW/minGap) label berlangkah tetap dari kiri, dan label
    terakhir selalu (tanggal/kategori terbaru adalah yang dicari pembaca); irama bawaan (64 px, tera '05 Sep 2026' 61,6 px taksiran)
    menyesuaikan DUA arah terhadap lebar label terlebar + 2·gap, dengan lantai 28 px; irama yang
    DISEBUT pemanggil (barChart: 48 atau lebar band) hanya dinaikkan, tidak pernah diturunkan. Lalu setiap kandidat diuji terhadap KOTAK label tetangga yang sudah terpilih
    — kotak xLabelBox yang sama dengan yang digambar, termasuk pergeseran jangkar tepi: yang
    menabrak dibuang, label terakhir menang atas tetangga kirinya. Dulu irama dihitung untuk label
    berpusat sementara label terakhir ditambatkan ke ujung kanan (bergeser ±30 px ke kiri): dua
    label terakhir setiap sumbu tanggal ≥ 10 titik bertumpuk 17–54 px (verifikasi P1-A putaran
    2, 5 Sep 2026). Pemanggil menjamin `centers` terurut naik. */
function thin(centers, labelAt, width, plotW, minGap = null, gap = 6) {
  const count = centers.length;
  const memo = new Map();
  const label = (i) => { if (!memo.has(i)) memo.set(i, labelAt(i)); return memo.get(i); };
  const box = (i) => xLabelBox(centers[i], label(i), width);
  const candidates = (step) => {
    const c = [];
    for (let i = 0; i < count; i += step) c.push(i);
    if (count && c[c.length - 1] !== count - 1) c.push(count - 1);
    return c;
  };
  const stepFor = (g) => Math.max(1, Math.ceil(count / Math.max(1, Math.floor(plotW / g))));
  const base = minGap ?? 64;
  let step = stepFor(base);
  const widest = Math.max(0, ...candidates(step).map((i) => textWidth(label(i))));
  /* Irama BAWAAN menyesuaikan DUA arah; irama yang DISEBUT pemanggil hanya naik.
     Menaikkan untuk label lebar sudah ada sejak P1-A. Menurunkan untuk label
     SEMPIT ditambahkan setelah verifikasi P1-E: 64 px ditera untuk
     '05 Sep 2026' (61,6 px) dan dipakai apa adanya oleh sumbu berlabel 'M12'
     (±21 px), sehingga kurva-S 12 minggu tergambar M1, M3, M5, M7, M9, M11,
     M12 — tujuh label di sumbu yang grafik tangannya menggambar dua belas,
     dengan ruang yang jelas cukup.

     Hanya bawaan, karena `barChart` MENYEBUT iramanya (48, atau lebar band):
     angka itu adalah pernyataan tentang bentuk grafiknya, bukan tera untuk
     lebar teks, dan menurunkannya akan menambah label di sumbu yang tidak
     meminta. Lantai 28 px menjaga jarak; uji tabrakan di bawah tetap kata
     terakhir, jadi irama yang lebih rapat tidak pernah bisa membuat dua label
     bertumpuk. */
  const rhythm = minGap === null
    ? Math.max(28, widest + gap * 2)
    : Math.max(base, widest + gap * 2);
  if (rhythm !== base) step = stepFor(rhythm);
  const picked = [];
  candidates(step).forEach((i) => {
    const { left } = box(i);
    const collides = () => picked.length > 0 && box(picked[picked.length - 1]).right + gap > left;
    if (i === count - 1) { while (collides()) picked.pop(); } else if (collides()) return;
    picked.push(i);
  });
  return picked;
}

/* ------------------------------------------------------------- lineChart */

export function lineChart({
  series = [], xLabels, xFormat, yFormat, yMax, yMin, yStep, width = 720, height = 260,
  ariaLabel = 'Grafik garis', sourceNote, legend = true,
} = {}) {
  const fy = formatter(yFormat);
  let anyDate = false;
  const rows = (Array.isArray(series) ? series : []).map((s, i) => {
    const points = (Array.isArray(s?.points) ? s.points : []).map((p, index) => {
      const { x, date } = xValue(p, index);
      anyDate = anyDate || date;
      const obj = p && typeof p === 'object' ? p : {};
      return { x, date, y: finite(p && typeof p === 'object' ? p.y : p), title: typeof obj.title === 'string' && obj.title ? obj.title : null, r: radiusOf(obj.r), token: tokenOf(obj.token) };
    }).sort((a, b) => a.x - b.x);
    const dash = typeof s?.dash === 'string' && s.dash.trim() ? s.dash.trim() : s?.dashed ? '6 4' : null;
    const dots = s?.dots === false ? 'none' : s?.dots === 'last' ? 'last' : 'all';
    /* Tebal garis per seri, 2 px bila tidak disebut. Ada karena grafik tangan
       yang digantikan P1-E memberi seri TERUKUR satu setengah kali tebal seri
       acuannya (`.chart .act { stroke-width: 2.5 }` vs `.plan/.base/.ev` 2),
       dan hierarki itu hilang tanpa suara saat charts.js menuliskan 2 untuk
       semuanya — seri kini hanya berbeda warna dan pola putus (verifikasi
       P1-E). Dijepit 1–4: sebuah garis 12 px bukan penekanan melainkan pita. */
    const strokeWidth = Math.min(4, Math.max(1, finite(s?.width) ?? 2));
    return { label: s?.label ?? `Seri ${i + 1}`, dash, dots, width: strokeWidth, area: !!s?.area, token: tokenOf(s?.token) ?? seriesToken(i), index: i + 1, points };
  });
  /* Skala campuran adalah kesalahan pemanggil, tetapi keluarannya tidak boleh mengarang:
     begitu satu x adalah tanggal, x yang bukan tanggal (angka, 'abc') dibuang sebagai
     celah — dulu indeksnya ikut diformat sebagai tanggal dan tampil "01 Jan 70". */
  let droppedX = 0;
  if (anyDate) {
    rows.forEach((s) => {
      const kept = s.points.filter((p) => p.date);
      droppedX += s.points.length - kept.length;
      s.points = kept;
    });
  }

  /* Seri yang tidak punya satu pun titik tergambar (semua y null, atau semua x-nya dibuang
     pada sumbu tanggal) tetap di legenda tetapi MENGATAKANNYA: 'indeks (tanpa data)' — swatch
     tanpa garis dulu tampak seperti seri yang kebetulan tidak terlihat. */
  rows.forEach((s) => { s.hasData = s.points.some((p) => p.y !== null); });
  /* …dan apakah seri itu akan punya GARIS sama sekali. Sebuah run satu titik
     tidak menggambar path, jadi seri yang seluruh datanya terpencil (biaya
     aktual EVM dengan lubang di tengah, atau proyek yang baru dibaseline)
     hanya menghasilkan titik — sementara legendanya tetap menggambar swatch
     berupa GARIS penuh untuk garis yang tidak ada di grafik (verifikasi P1-E). */
  rows.forEach((s) => {
    let run = 0;
    s.hasLine = false;
    s.points.forEach((p) => {
      run = p.y === null ? 0 : run + 1;
      if (run > 1) s.hasLine = true;
    });
  });
  const ys = rows.flatMap((s) => s.points.filter((p) => p.y !== null).map((p) => p.y));
  if (!ys.length) return placeholder('line', ariaLabel);

  const { lo, hi, ticks } = domain(ys, yMin, yMax, 4, yStep);
  const labelX = (x) => (typeof xFormat === 'function' ? xFormat(x)
    : Array.isArray(xLabels) && Number.isInteger(x) && xLabels[x] !== undefined ? xLabels[x]
      : anyDate ? shortDate.format(new Date(x)) : numberFormat.format(x));

  const PAD = { top: 14, right: 16, bottom: 28, left: Math.min(120, Math.max(36, Math.max(...ticks.map((t) => textWidth(fy(t)))) + 14)) };
  const plotW = width - PAD.left - PAD.right;
  const plotH = height - PAD.top - PAD.bottom;
  /* Legenda ditata SEKALI dari x0 = PAD.left dan baris-baris itulah yang digambar: menghitung
     tingginya dari x0 = 0 memberi baris lebih sedikit daripada yang tergambar (sumbu Rp →
     PAD.left 120, 8 seri berlabel 26–31 huruf: 3 baris dihitung, 4 digambar, catatan sumber
     16 px di luar viewBox — menimpa kepala kartu berikutnya; diukur 5 Sep 2026). */
  const items = rows.map((s) => ({
    label: s.hasData ? s.label : `${s.label} (tanpa data)`,
    token: s.token,
    // Seri yang hanya bertitik dilambangkan TITIK: swatch garis untuk seri
    // tanpa garis adalah legenda yang menjelaskan grafik lain.
    kind: s.hasData && !s.hasLine ? 'dot' : 'line',
    dash: s.dash,
    // Swatch setebal garisnya: legenda yang menggambar semua seri sama tebal
    // menghapus hierarki yang baru saja dipulihkan di plotnya.
    width: s.width,
    series: s.index,
    nodata: !s.hasData,
  }));
  const legendLayout = legend && items.length ? legendRows(items, width, PAD.left) : [];
  const legendH = legendLayout.length * 16;
  const noteH = sourceNote ? 16 : 0;
  const H = height + legendH + noteH;
  const xs = [...new Set(rows.flatMap((s) => s.points.map((p) => p.x)))].sort((a, b) => a - b);
  const x0 = xs[0];
  const x1 = xs[xs.length - 1];
  const x = (v) => PAD.left + (x1 === x0 ? plotW / 2 : ((v - x0) / (x1 - x0)) * plotW);
  const y = (v) => PAD.top + plotH - ((v - lo) / (hi - lo)) * plotH;

  const svg = frame('line', width, H, ariaLabel);
  if (droppedX) svg.dataset.droppedX = String(droppedX);
  const clip = plotClip(svg, PAD.left, PAD.top, plotW, plotH);
  ticks.forEach((t) => {
    svg.appendChild(paint(make('line', { class: 'chart-grid', x1: PAD.left, x2: width - PAD.right, y1: y(t), y2: y(t) }), 'stroke', '--chart-grid'));
    svg.appendChild(make('text', { class: 'chart-tick', x: PAD.left - 7, y: y(t) + 3.5, 'text-anchor': 'end' }, fy(t)));
  });
  svg.appendChild(paint(make('line', { class: 'chart-axis', x1: PAD.left, x2: PAD.left, y1: PAD.top, y2: PAD.top + plotH }), 'stroke', '--chart-axis'));
  if (lo < 0 && hi > 0) svg.appendChild(paint(make('line', { class: 'chart-zero', x1: PAD.left, x2: width - PAD.right, y1: y(0), y2: y(0) }), 'stroke', '--chart-axis'));
  thin(xs.map(x), (i) => labelX(xs[i]), width, plotW).forEach((i) => xTickLabel(svg, x(xs[i]), PAD.top + plotH + 19, labelX(xs[i]), width));

  const base = y(Math.min(Math.max(0, lo), hi));
  rows.forEach((s) => {
    /* Celah: setiap run titik berurutan yang punya nilai jadi satu path sendiri —
       null tidak pernah dijembatani dan tidak pernah dianggap nol. */
    const runs = [];
    let run = [];
    s.points.forEach((p) => {
      if (p.y === null) { if (run.length) runs.push(run); run = []; } else run.push(p);
    });
    if (run.length) runs.push(run);

    runs.forEach((pts) => {
      if (pts.length < 2) return;
      const d = pts.map((p, i) => `${i ? 'L' : 'M'}${round(x(p.x))},${round(y(p.y))}`).join(' ');
      if (s.area) {
        const area = make('path', { class: 'series-area', d: `${d} L${round(x(pts[pts.length - 1].x))},${round(base)} L${round(x(pts[0].x))},${round(base)} Z`, 'fill-opacity': 0.12, 'data-series': s.index, 'clip-path': clip });
        svg.appendChild(paint(area, 'fill', s.token));
      }
      const path = make('path', { class: 'series-line', d, fill: 'none', 'stroke-width': s.width, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'stroke-dasharray': s.dash, 'data-series': s.index, 'clip-path': clip });
      svg.appendChild(paint(path, 'stroke', s.token));
    });
    const lastRun = runs[runs.length - 1];
    runs.forEach((pts) => pts.forEach((p, k) => {
      /* dots:false → hanya run satu titik (tanpa garis) yang bertitik; 'last' → titik terakhir
         seri (+ run satu titik). Kurva-S 52 minggu × 3 seri = 156 titik kalau semua bertitik. */
      const isLast = pts === lastRun && k === pts.length - 1;
      if (pts.length > 1 && (s.dots === 'none' || (s.dots === 'last' && !isLast))) return;
      /* Titik di luar sumbu yang dipaksa ditempel di tepi plot dan MENGATAKANNYA: yMax 100
         dengan nilai 140 dulu menggambar titik 73 px di atas svg, menimpa kepala kartu. */
      const outside = p.y > hi ? 'above' : p.y < lo ? 'below' : null;
      const cy = outside === 'above' ? PAD.top : outside === 'below' ? PAD.top + plotH : y(p.y);
      /* Run satu titik digambar walau `dots:false` — tanpa garis maupun titik
         nilainya tidak terlihat sama sekali — tetapi ukurannya TIDAK boleh
         melampaui penanda yang pemanggilnya minta sendiri: titik as-of EVM
         (r 4, satu-satunya titik yang dicari orang saat membuka kartu itu)
         dulu diimbangi oleh titik pengecualian yang juga r 4, 2,7 px di
         sebelahnya (verifikasi P1-E). Pengecualian karena itu memakai jari-jari
         titik biasa. */
      const dot = make('circle', { class: 'series-point', cx: x(p.x), cy, r: p.r ?? 3, 'data-series': s.index, 'data-outside': outside });
      svg.appendChild(mark(paint(dot, 'fill', p.token ?? s.token), `${p.title ?? `${s.label} — ${labelX(p.x)}: ${fy(p.y)}`}${outside ? ' (di luar sumbu)' : ''}`));
    }));
  });

  let cursor = height + 12;
  if (legendH) cursor += drawLegend(svg, legendLayout, cursor);
  noteLine(svg, sourceNote, PAD.left, cursor);
  return svg;
}

/* -------------------------------------------------------------- barChart */

export function barChart({
  categories = [], series = [], stacked = false, horizontal = false, yFormat, yMax, yMin,
  width = 720, height, ariaLabel = 'Grafik batang', sourceNote, legend = true,
} = {}) {
  const fy = formatter(yFormat);
  const cats = Array.isArray(categories) ? categories.map((c) => String(c ?? '')) : [];
  const rows = (Array.isArray(series) ? series : []).map((s, i) => ({
    label: s?.label ?? `Seri ${i + 1}`, token: seriesToken(i), index: i + 1,
    values: cats.map((_, j) => finite(Array.isArray(s?.values) ? s.values[j] : null)),
  }));
  const noteH = sourceNote ? 16 : 0;
  const n = cats.length;
  const m = rows.length;
  const rowH = 26;
  const plotHeight = horizontal ? (height ?? Math.max(60, n * rowH)) : (height ?? 260);
  const present = rows.flatMap((s) => s.values.filter((v) => v !== null));

  if (!n || !m || !present.length) return placeholder('bar', ariaLabel);

  /* Domain: tumpukan diukur per kategori (jumlah positif dan jumlah negatif terpisah). */
  const extents = stacked
    ? cats.flatMap((_, j) => {
      const vals = rows.map((s) => s.values[j]).filter((v) => v !== null);
      return [vals.filter((v) => v > 0).reduce((a, b) => a + b, 0), vals.filter((v) => v < 0).reduce((a, b) => a + b, 0)];
    })
    : present;
  const { lo, hi, ticks } = domain(extents, yMin, yMax);

  const catLabelW = Math.min(180, Math.max(...cats.map(textWidth)) + 14);
  const PAD = horizontal
    ? { top: 8, right: 16, bottom: 28, left: Math.max(40, catLabelW) }
    : { top: 14, right: 16, bottom: 28, left: Math.min(120, Math.max(36, Math.max(...ticks.map((t) => textWidth(fy(t)))) + 14)) };
  /* Legenda ditata sekali dari PAD.left (lihat catatan di lineChart). */
  const items = rows.map((s) => ({ label: s.label, token: s.token, kind: 'box', series: s.index }));
  const legendLayout = legend && items.length > 1 ? legendRows(items, width, PAD.left) : [];
  const legendH = legendLayout.length * 16;
  const H = PAD.top + plotHeight + PAD.bottom + legendH + noteH;
  const plotW = width - PAD.left - PAD.right;
  const plotH = plotHeight;
  const svg = frame('bar', width, H, ariaLabel);

  const value = (v) => (horizontal
    ? PAD.left + ((v - lo) / (hi - lo)) * plotW
    : PAD.top + plotH - ((v - lo) / (hi - lo)) * plotH);
  const zero = value(Math.min(Math.max(0, lo), hi));

  /* Label nilai mendatar dijarangkan dengan kotak yang sama seperti sumbu-x garis: 'Rp 1.000.000.000,00'
     (103 px) pada 6 tick berjarak 117 px menabrak tetangganya begitu yang terakhir ditambatkan ke ujung. */
  const tickLabelIdx = new Set(horizontal ? thin(ticks.map(value), (i) => fy(ticks[i]), width, plotW, 48) : []);
  ticks.forEach((t, k) => {
    if (horizontal) {
      svg.appendChild(paint(make('line', { class: 'chart-grid', x1: value(t), x2: value(t), y1: PAD.top, y2: PAD.top + plotH }), 'stroke', '--chart-grid'));
      if (tickLabelIdx.has(k)) xTickLabel(svg, value(t), PAD.top + plotH + 19, fy(t), width);
    } else {
      svg.appendChild(paint(make('line', { class: 'chart-grid', x1: PAD.left, x2: width - PAD.right, y1: value(t), y2: value(t) }), 'stroke', '--chart-grid'));
      svg.appendChild(make('text', { class: 'chart-tick', x: PAD.left - 7, y: value(t) + 3.5, 'text-anchor': 'end' }, fy(t)));
    }
  });
  const axis = horizontal
    ? { x1: PAD.left, x2: width - PAD.right, y1: PAD.top + plotH, y2: PAD.top + plotH }
    : { x1: PAD.left, x2: PAD.left, y1: PAD.top, y2: PAD.top + plotH };
  svg.appendChild(paint(make('line', { class: 'chart-axis', ...axis }), 'stroke', '--chart-axis'));
  if (lo < 0 && hi > 0) {
    const z = horizontal ? { x1: zero, x2: zero, y1: PAD.top, y2: PAD.top + plotH } : { x1: PAD.left, x2: width - PAD.right, y1: zero, y2: zero };
    svg.appendChild(paint(make('line', { class: 'chart-zero', ...z }), 'stroke', '--chart-axis'));
  }

  const band = (horizontal ? plotH : plotW) / n;
  const groupW = band * (stacked ? 0.6 : 0.72);
  const barW = stacked ? groupW : groupW / m;
  /* Celah antarbatang 1 px hanya bila batangnya cukup lebar; di bawah ±5 px celahnya
     menyusut dan batang tipis tetap ≥ 0,5 px — `barW - 1` tanpa lantai menghasilkan
     lebar NEGATIF begitu n×m batang melewati ±0,72·plotW (200 kategori × 3 seri →
     width="-0.2": Chromium menolak atribut itu dan tidak menggambar satu batang pun,
     sementara 600 <title> tetap ada di DOM — diukur 5 Sep 2026). */
  const thick = Math.max(0.5, barW - Math.min(1, barW * 0.2));
  const catLabel = (j) => truncate(cats[j], Math.max(3, Math.floor((band - 4) / CHAR_W)));
  const labelIdx = new Set(horizontal ? cats.map((_, i) => i) : thin(cats.map((_, j) => PAD.left + j * band + band / 2), catLabel, width, plotW, Math.max(48, band)));

  cats.forEach((cat, j) => {
    const bandStart = (horizontal ? PAD.top : PAD.left) + j * band;
    if (labelIdx.has(j)) {
      if (horizontal) {
        svg.appendChild(make('text', { class: 'chart-tick', x: PAD.left - 7, y: bandStart + band / 2 + 3.5, 'text-anchor': 'end' }, truncate(cat, Math.floor((PAD.left - 10) / CHAR_W))));
      } else {
        xTickLabel(svg, bandStart + band / 2, PAD.top + plotH + 19, catLabel(j), width);
      }
    }
    let up = 0;
    let down = 0;
    rows.forEach((s, i) => {
      const v = s.values[j];
      if (v === null) return;
      let from = 0;
      let to = v;
      if (stacked) {
        if (v >= 0) { from = up; up += v; } else { from = down; down += v; }
        to = from + v;
      }
      const a = value(Math.max(lo, Math.min(hi, from)));
      const b = value(Math.max(lo, Math.min(hi, to)));
      const along = bandStart + (band - groupW) / 2 + (stacked ? 0 : i * barW);
      const attrs = horizontal
        ? { x: Math.min(a, b), y: along, width: Math.max(1, Math.abs(b - a)), height: thick }
        : { x: along, y: Math.min(a, b), width: thick, height: Math.max(1, Math.abs(b - a)) };
      const rect = make('rect', { class: 'series-bar', rx: 1.5, 'data-series': s.index, ...attrs });
      svg.appendChild(mark(paint(rect, 'fill', s.token), m > 1 ? `${s.label} — ${cat}: ${fy(v)}` : `${cat}: ${fy(v)}`));
    });
  });

  let cursor = PAD.top + plotH + PAD.bottom + 8;
  if (legendH) cursor += drawLegend(svg, legendLayout, cursor);
  noteLine(svg, sourceNote, PAD.left, cursor);
  return svg;
}

/* ------------------------------------------------------------ donutChart */

/** Bungkus teks legenda per kata ke baris selebar `maxWidth` px menurut `widthOf` (taksiran per
    kelas glyph — jumlah huruf bukan ukuran: 'PEMBANGUNAN GEDUNG' 18 huruf = 137 px, 'Kategori
    dengan nama' 18 huruf = 97 px). `phrases` = potongan yang sebaiknya tidak dipisah
    ('— 10.000.000.000', '(37,5 %)'): dipindahkan utuh ke baris baru bila muat, dan baru dipecah
    per kata (lalu per huruf, sebanyak yang muat) bila lebih panjang dari satu baris. */
function wrapPhrases(phrases, maxWidth, widthOf = estimateWidth) {
  const lines = [];
  let line = '';
  const fits = (text) => widthOf(text) <= maxWidth;
  const place = (word) => {
    while (!fits(word)) {
      if (line) { lines.push(line); line = ''; }
      const chars = [...word];
      let n = 1;
      while (n < chars.length && fits(chars.slice(0, n + 1).join(''))) n++;
      lines.push(chars.slice(0, n).join(''));
      word = chars.slice(n).join('');
    }
    if (!line) line = word;
    else if (fits(`${line} ${word}`)) line += ` ${word}`;
    else { lines.push(line); line = word; }
  };
  phrases.forEach((phrase) => {
    const text = String(phrase ?? '').trim();
    if (!text) return;
    if (fits(text)) place(text); else text.split(/\s+/).forEach(place);
  });
  if (line || !lines.length) lines.push(line);
  return lines;
}

export function donutChart({ slices = [], centerLabel, centerSub, valueFormat, ariaLabel = 'Grafik donat', sourceNote } = {}) {
  const fv = formatter(valueFormat);
  const rows = (Array.isArray(slices) ? slices : []).map((s, i) => ({ label: s?.label ?? `Bagian ${i + 1}`, value: finite(s?.value), token: seriesToken(i), index: i + 1 }));
  const drawn = rows.filter((s) => s.value !== null && s.value > 0);
  const total = drawn.reduce((a, s) => a + s.value, 0);
  /* Setiap baris masuk legenda — yang tidak digambar mengatakan mengapa (tanpa swatch):
     nilai tak terukur (null/NaN/teks) → "? (tidak dihitung)", negatif → "bukan bagian
     dari keseluruhan". Menyembunyikannya membuat "b — 5 (100 %)" tampak lengkap padahal
     ada baris yang hilang (aturan kejujuran CONVENTIONS §6/§11). */
  const legendPhrases = (s) => [s.label, ...(s.value === null ? ['— ?', '(tidak dihitung)']
    : s.value < 0 ? [`— ${fv(s.value)}`, '(bukan bagian dari keseluruhan)']
      : [`— ${fv(s.value)}`, `(${s.value > 0 ? `${percentFormat.format((s.value / total) * 100)} %` : '0 %'})`])];
  /* Lebar TETAP 360 (= placeholder), 1 viewBox px = 1 px sampai wadah 360 px: legenda dibungkus
     per kata ke kolom 128 px, bukan svg yang dilebarkan 360–560 mengikuti label terpanjang lalu
     menyusut ×0,64 di ponsel 390 (teks legenda 7–8,2 px, di bawah lantai ±11 px jenis lain;
     verifikasi P1-A putaran 2). Ukuran huruf donat kini tidak bergantung pada panjang label di
     viewport mana pun, dan dua donat di satu dasbor selalu sama besar. */
  const W = 360;
  const legendX = 212;
  /* Kolom teks legenda 120 px (x 232 … 352): dibungkus menurut taksiran lebar per kelas glyph,
     bukan 21 huruf × CHAR_W 5,6 — label huruf besar/angka ('PEMBANGUNAN GEDUNG', '1234567890')
     berukuran 6,1–8,6 px/glyph dan dulu berakhir 2–10 px di luar viewBox (verifikasi P1-A
     putaran 2). Grup legenda diklip ke viewBox (plotClip pad 0) sebagai jaring terakhir untuk
     font klien yang lebih lebar dari taksiran: yang tidak muat terpotong, bukan melukis di
     luar svg di atas elemen berikutnya. */
  const legendW = W - legendX - 20 - 8; // 120 px; 8 px berikutnya sampai tepi svg menampung selisih taksiran ≤ 6,7 % (terukur maks 2 %)
  const entries = rows.map((s) => ({ ...s, lines: wrapPhrases(legendPhrases(s), legendW) }));
  const entryH = (e) => 18 + (e.lines.length - 1) * 14;
  const rowsH = entries.reduce((a, e) => a + entryH(e), 0);
  const noteH = sourceNote ? 18 : 0;
  const H = Math.max(200, rowsH + 24) + noteH;
  if (!drawn.length) {
    /* Ada baris tetapi tidak satu pun bisa digambar: placeholder MENYEBUT berapa dan mengapa
       (svg data-excluded = jumlah baris) — placeholder polos menelan tiga baris negatif/tak
       terukur tanpa jejak, padahal legenda berjanji tidak pernah menyembunyikannya (verifikasi
       P1-A putaran 2). Tanpa baris sama sekali tetap "Belum ada data". */
    if (!rows.length) return placeholder('donut', ariaLabel);
    const count = (fn) => rows.filter(fn).length;
    const parts = [[count((s) => s.value === 0), 'bernilai 0'], [count((s) => s.value !== null && s.value < 0), 'negatif'], [count((s) => s.value === null), 'tak terukur']].filter(([n]) => n > 0).map(([n, why]) => `${n} ${why}`);
    const svg = placeholder('donut', ariaLabel, { message: 'Belum ada data yang bisa dihitung', sub: `${rows.length} baris tidak digambar: ${parts.join(', ')}` });
    svg.dataset.excluded = String(rows.length);
    return svg;
  }

  const svg = frame('donut', W, H, ariaLabel);
  /* Ukuran intrinsik (atribut width/height + .chart-donut { width: auto; max-width: 100% }):
     viewBox yang direntang ke lebar kartu membuat teks 11 px jadi 17 px dan angka tengah 28 px
     (diukur 5 Sep 2026). 1 viewBox px = 1 px kecuali wadahnya lebih sempit dari 360. */
  svg.setAttribute('width', round(W));
  svg.setAttribute('height', round(H));
  const cx = 100;
  const cy = Math.max(100, (H - noteH) / 2);
  const R = 84;
  const r = 56;
  const pct = (v) => `${percentFormat.format((v / total) * 100)} %`;

  if (drawn.length === 1) {
    const ring = make('circle', { class: 'series-slice', cx, cy, r: (R + r) / 2, fill: 'none', 'stroke-width': R - r, 'data-series': drawn[0].index });
    svg.appendChild(mark(paint(ring, 'stroke', drawn[0].token), `${drawn[0].label}: ${fv(drawn[0].value)} (${pct(drawn[0].value)})`));
  } else {
    let angle = -Math.PI / 2;
    drawn.forEach((s) => {
      const sweep = (s.value / total) * Math.PI * 2;
      const a0 = angle;
      const a1 = angle + sweep;
      angle = a1;
      const p = (rad, ang) => `${round(cx + rad * Math.cos(ang))},${round(cy + rad * Math.sin(ang))}`;
      /* Busur > 180° dipecah dua: titik awal dan akhir busur ≥ 359,99° jatuh pada
         koordinat yang sama setelah pembulatan 2 desimal, dan SVG lalu MENGHILANGKAN
         busurnya — irisan 99,999 % tergambar sebagai cakram tanpa lubang (1e-5) atau
         tidak sama sekali (1e-6; Rp 10 M vs Rp 100 rb adalah data ERP biasa). Dua
         busur ≤ 180° selalu punya ujung yang berbeda. */
      const arc = (rad, from, to, sweepFlag) => {
        const pieces = Math.abs(to - from) > Math.PI ? 2 : 1;
        return Array.from({ length: pieces }, (_, k) => `A${rad},${rad} 0 0 ${sweepFlag} ${p(rad, from + ((to - from) * (k + 1)) / pieces)}`).join(' ');
      };
      const d = `M${p(R, a0)} ${arc(R, a0, a1, 1)} L${p(r, a1)} ${arc(r, a1, a0, 0)} Z`;
      const path = make('path', { class: 'series-slice', d, 'data-series': s.index });
      svg.appendChild(mark(paint(path, 'fill', s.token), `${s.label}: ${fv(s.value)} (${pct(s.value)})`));
    });
  }

  if (centerLabel !== undefined && centerLabel !== null) {
    svg.appendChild(make('text', { class: 'chart-center', x: cx, y: cy + (centerSub ? 2 : 6), 'text-anchor': 'middle' }, centerLabel));
    if (centerSub) svg.appendChild(make('text', { class: 'chart-center-sub', x: cx, y: cy + 18, 'text-anchor': 'middle' }, centerSub));
  }

  let y = Math.max(16, (H - noteH) / 2 - rowsH / 2 + 12);
  const legend = make('g', { class: 'chart-legend-group', 'clip-path': plotClip(svg, 0, 0, W, H, 0) });
  svg.appendChild(legend);
  entries.forEach((s) => {
    if (s.value !== null && s.value >= 0) legend.appendChild(paint(make('rect', { class: 'legend-swatch', x: legendX, y: y - 9, width: 12, height: 10, rx: 2, 'data-series': s.index }), 'fill', s.token));
    /* Satu <text> per baris legenda, satu <tspan> per baris teks (dy 14): harness membaca
       entri lewat tspan-nya, dan getBBox <text> mencakup semua barisnya. */
    const text = make('text', { class: 'chart-legend', x: legendX + 20, y, 'data-excluded': s.value === null ? 'unknown' : s.value < 0 ? 'negative' : null, 'data-lines': s.lines.length });
    s.lines.forEach((line, k) => text.appendChild(make('tspan', { x: legendX + 20, dy: k ? 14 : 0 }, line)));
    legend.appendChild(text);
    y += entryH(s);
  });
  noteLine(svg, sourceNote, 8, H - 5);
  return svg;
}

/* ------------------------------------------------------------- sparkline */

export function sparkline({ points = [], width = 120, height = 32, ariaLabel = 'Sparkline', format } = {}) {
  const f = formatter(format);
  const vals = (Array.isArray(points) ? points : []).map((p) => finite(p && typeof p === 'object' ? p.y : p));
  const idx = vals.map((v, i) => (v === null ? null : i)).filter((i) => i !== null);
  /* Sparkline berukuran intrinsik (atribut width/height + .chart-spark { width:auto }):
     ia duduk di sel tabel atau ubin angka, bukan selebar kartu seperti grafik lain. */
  const sized = (svg) => { svg.setAttribute('width', width); svg.setAttribute('height', height); return svg; };
  if (!idx.length) return sized(placeholder('spark', ariaLabel, { width, height, fit: false }));

  const svg = sized(frame('spark', width, height, ariaLabel));
  const present = idx.map((i) => vals[i]);
  const min = Math.min(...present);
  const max = Math.max(...present);
  const pad = 3;
  const n = vals.length;
  const x = (i) => pad + (n <= 1 ? (width - 2 * pad) / 2 : (i / (n - 1)) * (width - 2 * pad));
  const y = (v) => (max === min ? height / 2 : pad + (1 - (v - min) / (max - min)) * (height - 2 * pad));
  const token = seriesToken(0);

  const runs = [];
  let run = [];
  vals.forEach((v, i) => { if (v === null) { if (run.length) runs.push(run); run = []; } else run.push(i); });
  if (run.length) runs.push(run);
  runs.filter((r) => r.length > 1).forEach((r, k) => {
    const d = r.map((i, j) => `${j ? 'L' : 'M'}${round(x(i))},${round(y(vals[i]))}`).join(' ');
    const path = make('path', { class: 'series-line', d, fill: 'none', 'stroke-width': 1.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'data-series': 1 });
    svg.appendChild(mark(paint(path, 'stroke', token), k === 0
      ? `${present.length} titik: min ${f(min)}, maks ${f(max)}, terakhir ${f(present[present.length - 1])}`
      : `lanjutan setelah celah (${r.length} titik)`));
  });
  const last = idx[idx.length - 1];
  const dot = make('circle', { class: 'series-point', cx: x(last), cy: y(vals[last]), r: idx.length === 1 ? 3 : 2.5, 'data-series': 1 });
  svg.appendChild(mark(paint(dot, 'fill', token), idx.length === 1 ? `satu titik: ${f(vals[last])}` : `terakhir: ${f(vals[last])}`));
  return svg;
}

/* ------------------------------------------------------------ ganttChart */

/** 'YYYY-MM-DD' (atau string tanggal lain yang bisa di-parse) → hari UTC dalam ms.
    Kosong → null ("belum ditetapkan"); TIDAK VALID → NaN ('2026-13-45', '2026-02-30', 'abc'):
    Date.UTC menggulung bulan 13 tanggal 45 jadi 14 Feb 2027 — tanggal yang tidak ada di data
    dan dulu tampil di <title> bar (aturan kejujuran: tak diketahui → null, bukan dikarang). */
function parseDay(value) {
  if (value === null || value === undefined || value === '') return null;
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value));
  if (m) {
    const ms = Date.UTC(+m[1], +m[2] - 1, +m[3]);
    const d = new Date(ms);
    return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3] ? ms : NaN;
  }
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? Math.floor(parsed / DAY) * DAY : NaN;
}

/** parseDay tanpa NaN: tidak valid diperlakukan seperti kosong (untuk from/to/today). */
function dayOrNull(value) {
  const d = parseDay(value);
  return Number.isNaN(d) ? null : d;
}

function localToday() {
  const now = new Date();
  return Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
}

export function ganttChart({
  rows = [], from, to, zoom = 'week', today, weekends = true, ariaLabel = 'Gantt',
  sourceNote, labelWidth = 180, timelineWidth = 720, rowHeight = 28,
} = {}) {
  /* Data yang tidak konsisten TIDAK dinormalkan diam-diam: tanggal tidak valid, selesai
     sebelum mulai, progres di luar 0..1 — semuanya tetap terlihat sebagai apa adanya
     (teks di baris / keterangan di <title>), bukan bar 1 px bertitle terbalik atau "100 %". */
  const tasks = (Array.isArray(rows) ? rows : []).map((r, i) => {
    const rawProgress = finite(r?.progress);
    const parsed = Object.fromEntries(['start', 'end', 'baselineStart', 'baselineEnd'].map((k) => [k, parseDay(r?.[k])]));
    const invalid = Object.keys(parsed).filter((k) => Number.isNaN(parsed[k]));
    const valid = (k) => (Number.isNaN(parsed[k]) ? null : parsed[k]);
    const t = {
      label: String(r?.label ?? `Baris ${i + 1}`),
      start: valid('start'), end: valid('end'),
      bStart: valid('baselineStart'), bEnd: valid('baselineEnd'),
      invalid: invalid.map((k) => `${k} "${truncate(r[k], 24)}"`),
      invalidDates: invalid.some((k) => k === 'start' || k === 'end'),
      rawProgress,
      progress: rawProgress === null ? null : Math.max(0, Math.min(1, rawProgress)),
      level: Math.max(0, Math.floor(finite(r?.level) ?? 0)),
    };
    t.inverted = t.start !== null && t.end !== null && t.end < t.start;
    t.bInverted = t.bStart !== null && t.bEnd !== null && t.bEnd < t.bStart;
    t.hasBaseline = t.bStart !== null && t.bEnd !== null && !t.bInverted;
    return t;
  });
  const dates = tasks.flatMap((t) => [t.start, t.end, t.bStart, t.bEnd]).filter((d) => d !== null);
  const W = labelWidth + timelineWidth;
  const headerH = 36;
  const noteH = sourceNote ? 16 : 0;
  if (!tasks.length || (!dates.length && dayOrNull(from) === null)) return placeholder('gantt', ariaLabel);

  const fromMs = dayOrNull(from) ?? Math.min(...dates);
  let toMs = dayOrNull(to) ?? Math.max(...dates);
  if (!(toMs > fromMs)) toMs = fromMs + 6 * DAY;
  const todayMs = dayOrNull(today) ?? localToday();
  const showToday = todayMs >= fromMs && todayMs <= toMs;
  const range = (a, b) => `${fullDate.format(new Date(a))} – ${fullDate.format(new Date(b))}`;

  /* Bentuk bar setiap baris dihitung SEKALI, di sini: legenda harus tahu apa
     yang akan digambar sebelum tinggi svg ditetapkan, dan perulangan baris di
     bawah memakai fungsi yang sama supaya keduanya tidak bisa berbeda pendapat
     tentang baris mana yang punya bar (dan ujung mana yang terbuka). */
  const barShape = (t) => {
    if (t.invalidDates || t.inverted || (t.start === null && t.end === null)) return null;
    const openStart = t.start === null;
    const openEnd = t.end === null;
    const s = openStart ? fromMs : t.start;
    const e = openEnd ? toMs : t.end;
    if (e + DAY <= fromMs || s > toMs) return null;

    return { openStart, openEnd, s, e };
  };

  /* Legenda hanya menyebut yang memang digambar: "Hari ini" tanpa garisnya atau
     "Baseline" tanpa satu pun baseline adalah legenda yang berbohong. */
  const legendItems = [{ label: 'Aktual', token: '--chart-1', kind: 'box', opacity: 0.35 }];
  if (tasks.some((t) => t.progress !== null && t.progress > 0)) legendItems.push({ label: 'Progres', token: '--chart-1', kind: 'box' });
  /* Baseline dihitung "digambar" hanya bila menyentuh rentang: baseline di luar from..to tidak
     punya rect, dan legenda 'Baseline' untuknya adalah legenda yang berbohong. */
  const baselineDrawn = (t) => t.hasBaseline && t.bEnd + DAY > fromMs && t.bStart <= toMs;
  if (tasks.some(baselineDrawn)) legendItems.push({ label: 'Baseline', token: '--chart-baseline', kind: 'box' });
  /* Bar berujung putus-putus dijelaskan HANYA oleh <title>-nya sampai P1-H —
     yaitu tidak dijelaskan sama sekali di ponsel (tidak muncul pada ketukan),
     di kertas, dan bagi pembaca layar: sebuah paket yang tanggal selesainya
     belum ditetapkan terbaca sebagai paket yang direncanakan berjalan sampai
     ujung proyek. */
  if (tasks.some((t) => { const shape = barShape(t); return shape && (shape.openStart || shape.openEnd); })) {
    legendItems.push({ label: 'Tanggal belum ditetapkan', token: '--chart-1', kind: 'line', dash: '3 2', width: 2 });
  }
  if (showToday) legendItems.push({ label: 'Hari ini', token: '--chart-today', kind: 'line' });
  const legendLayout = legendRows(legendItems, W, 8);
  const legendH = legendLayout.length * 16;
  const H = headerH + tasks.length * rowHeight + 10 + legendH + noteH;
  const days = Math.round((toMs - fromMs) / DAY) + 1; // `to` inklusif
  const dayW = timelineWidth / days;
  const x = (ms) => labelWidth + ((ms - fromMs) / DAY) * dayW;
  const clampX = (v) => Math.max(labelWidth, Math.min(labelWidth + timelineWidth, v));
  const rowsTop = headerH;
  const rowsH = tasks.length * rowHeight;

  const svg = frame('gantt', W, H, ariaLabel);
  svg.style.minWidth = `${Math.round(W * 0.8)}px`;
  /* Label baris 11,5 px (level 0 tebal): 5,6–5,9 px/glyph normal, 5,9–6,1 tebal (diukur 5 Sep
     2026) — pemotongan memakai faktor itu, dan kolom label diklip pada labelWidth − 6 supaya
     label berglyph lebar ('WWW…', 10,9 px/glyph) pun tidak pernah menembus kolom jadwal. */
  const labelChars = (level) => Math.floor((labelWidth - 14 - level * 12) / (CHAR_W * (11.5 / 11) * (level === 0 ? 1.06 : 1)));
  /* DUA BARIS, bukan satu yang dipotong. Nama paket pekerjaan yang sungguhan
     lebih panjang daripada satu baris kolom label — diukur pada berkas demo,
     kolom 180: 9 dari 11 nama terpotong, di ponsel MAUPUN di layar 1440 (dan
     di kertas, tempat <title> tidak bisa disentuh sama sekali). Baris kedua
     memakai tinggi baris yang sudah ada (28 px) tanpa menggeser satu bar pun;
     yang masih tidak muat tetap dipotong dengan '…' dan tetap membawa nama
     lengkapnya di <title>. */
  const labelLines = (text, maxChars) => {
    if (text.length <= maxChars) return [text];
    const cut = text.lastIndexOf(' ', maxChars);
    const head = cut > maxChars * 0.4 ? text.slice(0, cut) : text.slice(0, maxChars);
    const tail = text.slice(head.length).trim();

    return [head, truncate(tail, maxChars)];
  };
  const labelClip = plotClip(svg, 0, 0, labelWidth - 8, H);

  /* Bayangan akhir pekan: Sabtu+Minggu digabung jadi satu rect. */
  if (weekends) {
    for (let d = fromMs; d <= toMs; d += DAY) {
      const dow = new Date(d).getUTCDay();
      if (dow !== 6 && !(dow === 0 && d === fromMs)) continue;
      const span = dow === 6 && d + DAY <= toMs ? 2 : 1;
      svg.appendChild(paint(make('rect', { class: 'gantt-weekend', x: x(d), y: rowsTop, width: dayW * span, height: rowsH }), 'fill', '--chart-weekend'));
      if (span === 2) d += DAY;
    }
  }

  /* Garis & label tick: minggu (Senin) atau bulan (tanggal 1); baris atas bulan/tahun. */
  const ticks = [];
  if (zoom === 'month') {
    const first = new Date(fromMs);
    let cursor = Date.UTC(first.getUTCFullYear(), first.getUTCMonth(), 1);
    if (cursor < fromMs) cursor = Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 1);
    while (cursor <= toMs) {
      const dt = new Date(cursor);
      ticks.push({ at: cursor, label: monthOnly.format(dt) });
      cursor = Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 1);
    }
  } else {
    let cursor = fromMs + ((8 - new Date(fromMs).getUTCDay()) % 7) * DAY;
    while (cursor <= toMs) { ticks.push({ at: cursor, label: dayMonth.format(new Date(cursor)) }); cursor += 7 * DAY; }
  }
  const spacing = ticks.length > 1 ? x(ticks[1].at) - x(ticks[0].at) : timelineWidth;
  const every = Math.max(1, Math.ceil(44 / Math.max(1, spacing)));
  const todayX = showToday ? x(todayMs) + dayW / 2 : null;
  ticks.forEach((t, i) => {
    svg.appendChild(paint(make('line', { class: 'gantt-tick', x1: x(t.at), x2: x(t.at), y1: headerH - 16, y2: rowsTop + rowsH }), 'stroke', '--chart-grid'));
    /* Label tick mengalah pada label "Hari ini" yang berbagi baris dengannya. */
    const nearToday = todayX !== null && x(t.at) + 3 < todayX + 4 + textWidth('Hari ini') && x(t.at) + 3 + textWidth(t.label) > todayX;
    const fits = x(t.at) + 3 + textWidth(t.label) <= W; // label tick terakhir tidak boleh keluar tepi kanan
    if (i % every === 0 && !nearToday && fits) svg.appendChild(make('text', { class: 'gantt-tick-label chart-tick', x: x(t.at) + 3, y: headerH - 5 }, t.label));
  });

  const groups = [];
  {
    const first = new Date(fromMs);
    let cursor = zoom === 'month' ? Date.UTC(first.getUTCFullYear(), 0, 1) : Date.UTC(first.getUTCFullYear(), first.getUTCMonth(), 1);
    while (cursor <= toMs) {
      const dt = new Date(cursor);
      const next = zoom === 'month' ? Date.UTC(dt.getUTCFullYear() + 1, 0, 1) : Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 1);
      groups.push({ from: Math.max(cursor, fromMs), to: Math.min(next, toMs + DAY), label: zoom === 'month' ? String(dt.getUTCFullYear()) : monthYear.format(dt) });
      cursor = next;
    }
  }
  groups.forEach((g) => {
    const w = x(g.to) - x(g.from);
    if (w >= textWidth(g.label) + 8) svg.appendChild(make('text', { class: 'gantt-group chart-tick', x: x(g.from) + 4, y: 12 }, g.label));
    svg.appendChild(paint(make('line', { class: 'gantt-group-line', x1: x(g.from), x2: x(g.from), y1: 0, y2: headerH - 16 }), 'stroke', '--chart-axis'));
  });
  svg.appendChild(paint(make('line', { class: 'chart-axis', x1: labelWidth, x2: W, y1: rowsTop, y2: rowsTop }), 'stroke', '--chart-axis'));

  tasks.forEach((t, i) => {
    const top = rowsTop + i * rowHeight;
    const mid = top + rowHeight / 2;
    svg.appendChild(paint(make('line', { class: 'gantt-row-line', x1: 0, x2: W, y1: top + rowHeight, y2: top + rowHeight }), 'stroke', '--chart-grid'));
    const indent = t.level * 12;
    const lines = labelLines(t.label, labelChars(t.level));
    const truncated = lines.join(' ') !== t.label;
    /* Satu <text> dengan dua <tspan>, bukan dua <text>: satu baris jadwal tetap
       satu simpul label, jadi "jumlah label = jumlah tugas" tetap benar. */
    const text = make('text', { class: 'gantt-label', x: 8 + indent, y: lines.length > 1 ? mid - 2 : mid + 4, 'data-full': t.label, 'data-truncated': truncated ? 'true' : null, 'data-lines': lines.length, 'font-weight': t.level === 0 ? 600 : null, 'clip-path': labelClip }, undefined);
    lines.forEach((line, index) => text.appendChild(make('tspan', { x: 8 + indent, dy: index === 0 ? 0 : 12 }, line)));
    /* Label yang dipotong membawa nama lengkapnya di <title> — satu-satunya <title> di
       luar .mark (nama WBS lazim > 29 huruf, dan baris "tanpa tanggal" tidak punya bar
       yang <title>-nya mengulang nama itu). Harness S20 menghitung .mark > title. */
    if (truncated) text.appendChild(make('title', {}, t.label));
    svg.appendChild(text);

    const withBaseline = baselineDrawn(t);
    if (withBaseline) {
      const a = clampX(x(t.bStart));
      const b = clampX(x(t.bEnd + DAY));
      const base = make('rect', { class: 'gantt-baseline', x: a, y: mid + 1, width: Math.max(1, b - a), height: 7, rx: 1.5 });
      svg.appendChild(mark(paint(base, 'fill', '--chart-baseline'), `${t.label} — baseline: ${range(t.bStart, t.bEnd)}`));
    }

    /* Baseline yang bermasalah disebut di catatan baris DAN di <title> bar — dulu hanya di
       <title>, sehingga baris "tanpa tanggal" dengan baseline terbalik tidak berkata apa-apa. */
    const invalidBaseline = t.invalid.filter((k) => k.startsWith('baseline'));
    const baselineNote = t.bInverted ? ' · baseline tidak valid (selesai sebelum mulai)' : invalidBaseline.length ? ` · baseline tidak valid: ${invalidBaseline.join(', ')}` : '';
    /* Catatan baris (tanggal tidak valid / terbalik / tanpa tanggal / di luar rentang) tetap
       digambar bersama bar baseline-nya — baseline adalah data yang ada — tetapi DI ATAS bar itu
       (y mid − 3; bar baseline menempati mid + 1..8), bukan menimpanya seperti dulu (teks di
       mid + 4 di atas rect 240 px; verifikasi P1-A putaran 2). */
    const rowNote = (cls, text) => svg.appendChild(make('text', { class: `${cls} chart-tick`, x: labelWidth + 6, y: withBaseline ? mid - 3 : mid + 4, 'data-above-baseline': withBaseline ? 'true' : null }, text));
    const shape = barShape(t);
    if (t.invalidDates) { rowNote('gantt-invalid', `tanggal tidak valid: ${t.invalid.join(', ')}${t.bInverted ? ' · baseline tidak valid (selesai sebelum mulai)' : ''}`); return; }
    if (t.inverted) { rowNote('gantt-invalid', `tanggal selesai sebelum mulai (${range(t.start, t.end)})${baselineNote}`); return; }
    if (t.start === null && t.end === null) { rowNote('gantt-nodate', `tanpa tanggal${baselineNote}`); return; }
    const openStart = t.start === null;
    const openEnd = t.end === null;
    const s = openStart ? fromMs : t.start;
    const e = openEnd ? toMs : t.end;
    if (shape === null) {
      /* Baris terbuka di luar rentang menyebut HANYA tanggal yang ada: batas rentang (from/to)
         yang disubstitusikan untuk ujung yang kosong dulu ikut dicetak sebagai tanggal tugas —
         'di luar rentang (01 Sep 2026 – 10 Jan 2026)' untuk {start:null, end:'2026-01-10'}
         (verifikasi P1-A putaran 2). Baris terbuka hanya bisa berada di satu sisi: ujung yang
         kosong menyentuh rentang. */
      rowNote('gantt-outside', (openStart ? `selesai ${fullDate.format(new Date(t.end))}, sebelum rentang (mulai belum ditetapkan)`
        : openEnd ? `mulai ${fullDate.format(new Date(t.start))}, setelah rentang (selesai belum ditetapkan)`
          : `di luar rentang (${range(s, e)})`) + baselineNote);
      return;
    }
    const a = clampX(x(s));
    const b = clampX(x(e + DAY));
    const pctText = t.rawProgress === null ? ''
      : t.rawProgress !== t.progress ? ` · progres ${percentFormat.format(t.rawProgress * 100)} % (di luar 0–100 %)`
        : ` · ${percentFormat.format(t.progress * 100)} %`;
    const title = (openEnd
      ? `${t.label}: mulai ${fullDate.format(new Date(t.start))}, tanggal selesai belum ditetapkan (bar terbuka)`
      : openStart
        ? `${t.label}: tanggal mulai belum ditetapkan, selesai ${fullDate.format(new Date(t.end))} (bar terbuka)`
        : `${t.label}: ${range(t.start, t.end)}`) + pctText + baselineNote;
    const bar = make('rect', { class: 'gantt-bar', x: a, y: mid - 9, width: Math.max(1, b - a), height: 14, rx: 2, 'fill-opacity': 0.35, 'data-open': openEnd ? 'end' : openStart ? 'start' : null, 'data-level': t.level });
    svg.appendChild(mark(paint(bar, 'fill', '--chart-1'), title));
    if (t.progress !== null && t.progress > 0) {
      svg.appendChild(paint(make('rect', { class: 'gantt-progress', x: a, y: mid - 9, width: Math.max(1, (b - a) * t.progress), height: 14, rx: 2 }), 'fill', '--chart-1'));
    }
    if (openEnd || openStart) {
      const ex = openEnd ? b : a;
      svg.appendChild(paint(make('line', { class: 'gantt-open-edge', x1: ex, x2: ex, y1: mid - 11, y2: mid + 11, 'stroke-width': 2, 'stroke-dasharray': '3 2' }), 'stroke', '--chart-1'));
    }
  });

  if (showToday) {
    svg.appendChild(paint(make('line', { class: 'gantt-today', x1: todayX, x2: todayX, y1: headerH - 16, y2: rowsTop + rowsH, 'stroke-width': 1.5 }), 'stroke', '--chart-today'));
    svg.appendChild(paint(make('text', { class: 'gantt-today-label', x: todayX + 4, y: headerH - 5, 'font-weight': 600 }, 'Hari ini'), 'fill', '--chart-today'));
  }

  let cursor = rowsTop + rowsH + 18;
  cursor += drawLegend(svg, legendLayout, cursor);
  noteLine(svg, sourceNote, 8, cursor);
  return svg;
}
