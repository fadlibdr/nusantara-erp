/**
 * charts.js — grafik SVG tangan Nusantara ERP (Fase 1, P1-A). BERKAS INI REFERENSI API-NYA.
 *
 * Lima fungsi murni yang mengembalikan satu elemen <svg> siap ditempel: tanpa fetch,
 * tanpa state, tanpa global, tanpa dependensi (0 KB vendor — keputusan pemilik
 * ROADMAP-HASHMICRO §5 #2: grafik ditulis sendiri supaya mewarisi token tema dan blok
 * cetak, dan warnanya bisa diukur harness S20). Pemanggil menyediakan data yang sudah
 * dihitung server; di sini hanya geometri.
 *
 *   lineChart({ series, xLabels?, xFormat?, yFormat?, yMax?, yMin?, width?, height?, ariaLabel, sourceNote?, legend? })
 *     series : [{ label, points: [{ x?, y }], dashed?, area? }]
 *              x = angka (indeks/skala apa pun) ATAU string tanggal ISO (jadi sumbu tanggal);
 *              x kosong = indeks titik. y null/NaN/undefined = CELAH (garis putus), bukan nol.
 *     xLabels: label per indeks x (['M1','M2',…]); xFormat(x) menang bila ada; bawaan:
 *              tanggal → "05 Sep 26", angka → id-ID.
 *     yFormat: (angka) → teks; dipakai di sumbu DAN <title> tiap titik (bawaan id-ID, 2 desimal).
 *     yMin/yMax: sumbu dipaksa ke nilai itu (kurva EVM memakai yMax ≥ 100 hasil hitungannya
 *              sendiri — aturan ">100 % dipertahankan" milik pemanggil, bukan grafik).
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
 *     slices: [{ label, value }]; hanya value > 0 yang digambar (yang 0 tetap di legenda
 *     sebagai 0 %); value null/NaN/teks → baris legenda "? (tidak dihitung)", negatif →
 *     "(bukan bagian dari keseluruhan)" — keduanya tanpa swatch, tidak pernah disembunyikan;
 *     satu irisan → cincin penuh; tak ada yang > 0 → placeholder.
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
 *     "di luar rentang (…)", progress di luar 0..1 → lapisan progres dijepit tetapi <title>
 *     menyebut angka aslinya "(di luar 0–100 %)", baseline terbalik → tidak digambar + catatan
 *     di <title> bar. Ketergantungan (dependency) TIDAK digambar — ditunda ke Fase 2
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
 *     (jumlah <title> == jumlah .mark). Elemen lain tidak memakai <title>.
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
/* Lebar rata-rata glyph pada 11 px --font, untuk memperkirakan lebar label (tanpa
   layout DOM — fungsi ini murni dan bisa dipanggil sebelum svg ditempel). */
const CHAR_W = 6.3;
const FONT = 11;
const SERIES_TOKENS = 8;
const EMPTY_TEXT = 'Belum ada data';

/* Satu-satunya state modul: penghitung id <clipPath>, supaya dua grafik pada halaman yang
   sama tidak berbagi id (url(#…) merujuk id pertama di dokumen). */
let clipSeq = 0;

const numberFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const percentFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });
const shortDate = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: '2-digit', timeZone: 'UTC' });
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

function frame(kind, width, height, ariaLabel) {
  const svg = make('svg', { viewBox: `0 0 ${round(width)} ${round(height)}`, role: 'img', 'aria-label': ariaLabel });
  svg.setAttribute('class', `chart chart-lib chart-${kind}`);
  return svg;
}

/** Placeholder "Belum ada data" berukuran RINGKAS (360×64, max-width 360 px) untuk semua
    jenis kecuali sparkline (yang berukuran intrinsik): viewBox selebar grafik penuh (720/900)
    menyusutkan teks 13 px jadi 7 px (garis/batang) atau 4,4 px (gantt) di ponsel 390 px, dan
    di desktop menyisakan kartu kosong 290–300 px untuk satu baris teks (diukur 5 Sep 2026). */
function placeholder(kind, ariaLabel, { width = 360, height = 64, fit = true, message = EMPTY_TEXT } = {}) {
  const svg = frame(kind, width, height, ariaLabel);
  svg.classList.add('is-empty');
  svg.dataset.empty = 'true';
  if (fit) svg.style.maxWidth = `${width}px`;
  svg.appendChild(make('text', { class: 'chart-empty', x: width / 2, y: height / 2 + FONT / 3, 'text-anchor': 'middle' }, message));
  return svg;
}

/** Klip area plot (+2 px untuk tebal garis) untuk garis/area: nilai di luar yMin/yMax yang
    dipaksa tidak boleh terlukis di luar svg. Mengembalikan nilai atribut clip-path. */
function plotClip(svg, x, y, w, h) {
  const id = `chart-clip-${++clipSeq}`;
  const defs = make('defs');
  const clip = make('clipPath', { id });
  clip.appendChild(make('rect', { x: x - 2, y: y - 2, width: w + 4, height: h + 4 }));
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

/** items: [{ label, token, kind: 'line'|'box', dashed?, opacity?, series? }] — baris-baris
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
        const line = make('line', { class: 'legend-swatch series-line', x1: item.x, x2: item.x + 16, y1: y - 4, y2: y - 4, 'stroke-width': 2.5, 'stroke-dasharray': item.dashed ? '6 4' : null, 'data-series': item.series });
        svg.appendChild(paint(line, 'stroke', item.token));
      } else {
        const box = make('rect', { class: 'legend-swatch', x: item.x, y: y - 9, width: 12, height: 10, rx: 2, 'fill-opacity': item.opacity, 'data-series': item.series });
        svg.appendChild(paint(box, 'fill', item.token));
      }
      svg.appendChild(make('text', { class: 'chart-legend', x: item.x + 22, y }, item.label));
    });
  });
  return rows.length * 16;
}

/* ---------------------------------------------------------------- skala */

function niceStep(rough) {
  if (!(rough > 0)) return 1;
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const n = rough / magnitude;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * magnitude;
}

/** Domain nilai: memuat nol kecuali min/max dipaksa; tepi dibulatkan ke langkah rapi. */
function domain(values, min, max, tickCount = 4) {
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
  const step = niceStep((hi - lo) / tickCount);
  if (finite(min) === null) lo = Math.floor(lo / step + 1e-9) * step;
  if (finite(max) === null) hi = Math.ceil(hi / step - 1e-9) * step;
  const ticks = [];
  for (let v = Math.ceil(lo / step - 1e-9) * step; v <= hi + step * 1e-6; v += step) ticks.push(Math.abs(v) < step * 1e-9 ? 0 : +v.toPrecision(12));
  return { lo, hi, ticks };
}

function xValue(point, index) {
  const raw = point && typeof point === 'object' ? point.x : undefined;
  if (raw === null || raw === undefined) return { x: index, date: false };
  if (typeof raw === 'number') return { x: Number.isFinite(raw) ? raw : index, date: false };
  const parsed = Date.parse(raw);
  return Number.isFinite(parsed) ? { x: parsed, date: true } : { x: index, date: false };
}

/** Indeks label sumbu-x yang digambar: paling banyak floor(plotW/minGap), yang terakhir selalu. */
function thin(count, plotW, minGap = 64) {
  const step = Math.max(1, Math.ceil(count / Math.max(1, Math.floor(plotW / minGap))));
  const picked = [];
  for (let i = 0; i < count; i++) if (i % step === 0 || i === count - 1) picked.push(i);
  if (picked.length >= 2 && (picked[picked.length - 1] - picked[picked.length - 2]) * (plotW / Math.max(1, count - 1)) < minGap * 0.6) picked.splice(picked.length - 2, 1);
  return picked;
}

/* ------------------------------------------------------------- lineChart */

export function lineChart({
  series = [], xLabels, xFormat, yFormat, yMax, yMin, width = 720, height = 260,
  ariaLabel = 'Grafik garis', sourceNote, legend = true,
} = {}) {
  const fy = formatter(yFormat);
  let anyDate = false;
  const rows = (Array.isArray(series) ? series : []).map((s, i) => {
    const points = (Array.isArray(s?.points) ? s.points : []).map((p, index) => {
      const { x, date } = xValue(p, index);
      anyDate = anyDate || date;
      return { x, y: finite(p && typeof p === 'object' ? p.y : p) };
    }).sort((a, b) => a.x - b.x);
    return { label: s?.label ?? `Seri ${i + 1}`, dashed: !!s?.dashed, area: !!s?.area, token: seriesToken(i), index: i + 1, points };
  });

  const ys = rows.flatMap((s) => s.points.filter((p) => p.y !== null).map((p) => p.y));
  if (!ys.length) return placeholder('line', ariaLabel);

  const { lo, hi, ticks } = domain(ys, yMin, yMax);
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
  const items = rows.map((s) => ({ label: s.label, token: s.token, kind: 'line', dashed: s.dashed, series: s.index }));
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
  const clip = plotClip(svg, PAD.left, PAD.top, plotW, plotH);
  ticks.forEach((t) => {
    svg.appendChild(paint(make('line', { class: 'chart-grid', x1: PAD.left, x2: width - PAD.right, y1: y(t), y2: y(t) }), 'stroke', '--chart-grid'));
    svg.appendChild(make('text', { class: 'chart-tick', x: PAD.left - 7, y: y(t) + 3.5, 'text-anchor': 'end' }, fy(t)));
  });
  svg.appendChild(paint(make('line', { class: 'chart-axis', x1: PAD.left, x2: PAD.left, y1: PAD.top, y2: PAD.top + plotH }), 'stroke', '--chart-axis'));
  if (lo < 0 && hi > 0) svg.appendChild(paint(make('line', { class: 'chart-zero', x1: PAD.left, x2: width - PAD.right, y1: y(0), y2: y(0) }), 'stroke', '--chart-axis'));
  thin(xs.length, plotW).forEach((i) => {
    svg.appendChild(make('text', { class: 'chart-tick', x: x(xs[i]), y: PAD.top + plotH + 19, 'text-anchor': 'middle' }, labelX(xs[i])));
  });

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
      const path = make('path', { class: 'series-line', d, fill: 'none', 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'stroke-dasharray': s.dashed ? '6 4' : null, 'data-series': s.index, 'clip-path': clip });
      svg.appendChild(paint(path, 'stroke', s.token));
    });
    runs.forEach((pts) => pts.forEach((p) => {
      /* Titik di luar sumbu yang dipaksa ditempel di tepi plot dan MENGATAKANNYA: yMax 100
         dengan nilai 140 dulu menggambar titik 73 px di atas svg, menimpa kepala kartu. */
      const outside = p.y > hi ? 'above' : p.y < lo ? 'below' : null;
      const cy = outside === 'above' ? PAD.top : outside === 'below' ? PAD.top + plotH : y(p.y);
      const dot = make('circle', { class: 'series-point', cx: x(p.x), cy, r: pts.length === 1 ? 4 : 3, 'data-series': s.index, 'data-outside': outside });
      svg.appendChild(mark(paint(dot, 'fill', s.token), `${s.label} — ${labelX(p.x)}: ${fy(p.y)}${outside ? ' (di luar sumbu)' : ''}`));
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

  ticks.forEach((t) => {
    if (horizontal) {
      svg.appendChild(paint(make('line', { class: 'chart-grid', x1: value(t), x2: value(t), y1: PAD.top, y2: PAD.top + plotH }), 'stroke', '--chart-grid'));
      svg.appendChild(make('text', { class: 'chart-tick', x: value(t), y: PAD.top + plotH + 19, 'text-anchor': 'middle' }, fy(t)));
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
  const labelIdx = new Set(horizontal ? cats.map((_, i) => i) : thin(n, plotW, Math.max(48, band)));

  cats.forEach((cat, j) => {
    const bandStart = (horizontal ? PAD.top : PAD.left) + j * band;
    if (labelIdx.has(j)) {
      if (horizontal) {
        svg.appendChild(make('text', { class: 'chart-tick', x: PAD.left - 7, y: bandStart + band / 2 + 3.5, 'text-anchor': 'end' }, truncate(cat, Math.floor((PAD.left - 10) / CHAR_W))));
      } else {
        svg.appendChild(make('text', { class: 'chart-tick', x: bandStart + band / 2, y: PAD.top + plotH + 19, 'text-anchor': 'middle' }, truncate(cat, Math.max(3, Math.floor(band / CHAR_W) - 1))));
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

export function donutChart({ slices = [], centerLabel, centerSub, valueFormat, ariaLabel = 'Grafik donat', sourceNote } = {}) {
  const fv = formatter(valueFormat);
  const rows = (Array.isArray(slices) ? slices : []).map((s, i) => ({ label: s?.label ?? `Bagian ${i + 1}`, value: finite(s?.value), token: seriesToken(i), index: i + 1 }));
  const drawn = rows.filter((s) => s.value !== null && s.value > 0);
  const total = drawn.reduce((a, s) => a + s.value, 0);
  /* Setiap baris masuk legenda — yang tidak digambar mengatakan mengapa (tanpa swatch):
     nilai tak terukur (null/NaN/teks) → "? (tidak dihitung)", negatif → "bukan bagian
     dari keseluruhan". Menyembunyikannya membuat "b — 5 (100 %)" tampak lengkap padahal
     ada baris yang hilang (aturan kejujuran CONVENTIONS §6/§11). */
  const legendText = (s) => (s.value === null ? `${s.label} — ? (tidak dihitung)`
    : s.value < 0 ? `${s.label} — ${fv(s.value)} (bukan bagian dari keseluruhan)`
      : `${s.label} — ${fv(s.value)} (${s.value > 0 ? `${percentFormat.format((s.value / total) * 100)} %` : '0 %'})`);
  const listed = rows;
  const legendX = 212;
  const rowsH = listed.length * 18;
  const W = Math.min(560, Math.max(360, legendX + Math.max(0, ...listed.map((s) => textWidth(legendText(s)))) + 34));
  const noteH = sourceNote ? 18 : 0;
  const H = Math.max(200, rowsH + 24) + noteH;
  if (!drawn.length) return placeholder('donut', ariaLabel);

  const svg = frame('donut', W, H, ariaLabel);
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

  const y0 = Math.max(16, (H - noteH) / 2 - rowsH / 2 + 12);
  listed.forEach((s, i) => {
    const y = y0 + i * 18;
    if (s.value !== null && s.value >= 0) svg.appendChild(paint(make('rect', { class: 'legend-swatch', x: legendX, y: y - 9, width: 12, height: 10, rx: 2, 'data-series': s.index }), 'fill', s.token));
    svg.appendChild(make('text', { class: 'chart-legend', x: legendX + 20, y, 'data-excluded': s.value === null ? 'unknown' : s.value < 0 ? 'negative' : null }, legendText(s)));
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

  /* Legenda hanya menyebut yang memang digambar: "Hari ini" tanpa garisnya atau
     "Baseline" tanpa satu pun baseline adalah legenda yang berbohong. */
  const legendItems = [{ label: 'Aktual', token: '--chart-1', kind: 'box', opacity: 0.35 }];
  if (tasks.some((t) => t.progress !== null && t.progress > 0)) legendItems.push({ label: 'Progres', token: '--chart-1', kind: 'box' });
  if (tasks.some((t) => t.hasBaseline)) legendItems.push({ label: 'Baseline', token: '--chart-baseline', kind: 'box' });
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
    if (i % every === 0 && !nearToday) svg.appendChild(make('text', { class: 'gantt-tick-label chart-tick', x: x(t.at) + 3, y: headerH - 5 }, t.label));
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
    const text = make('text', { class: 'gantt-label', x: 8 + indent, y: mid + 4, 'data-full': t.label, 'font-weight': t.level === 0 ? 600 : null }, truncate(t.label, Math.floor((labelWidth - 14 - indent) / CHAR_W)));
    svg.appendChild(text);

    if (t.hasBaseline && t.bEnd + DAY > fromMs && t.bStart <= toMs) {
      const a = clampX(x(t.bStart));
      const b = clampX(x(t.bEnd + DAY));
      const base = make('rect', { class: 'gantt-baseline', x: a, y: mid + 1, width: Math.max(1, b - a), height: 7, rx: 1.5 });
      svg.appendChild(mark(paint(base, 'fill', '--chart-baseline'), `${t.label} — baseline: ${range(t.bStart, t.bEnd)}`));
    }

    const rowNote = (cls, text) => svg.appendChild(make('text', { class: `${cls} chart-tick`, x: labelWidth + 6, y: mid + 4 }, text));
    if (t.invalidDates) { rowNote('gantt-invalid', `tanggal tidak valid: ${t.invalid.join(', ')}`); return; }
    if (t.inverted) { rowNote('gantt-invalid', `tanggal selesai sebelum mulai (${range(t.start, t.end)})`); return; }
    if (t.start === null && t.end === null) { rowNote('gantt-nodate', 'tanpa tanggal'); return; }
    const openStart = t.start === null;
    const openEnd = t.end === null;
    const s = openStart ? fromMs : t.start;
    const e = openEnd ? toMs : t.end;
    if (e + DAY <= fromMs || s > toMs) { rowNote('gantt-outside', `di luar rentang (${range(s, e)})`); return; }
    const a = clampX(x(s));
    const b = clampX(x(e + DAY));
    const pctText = t.rawProgress === null ? ''
      : t.rawProgress !== t.progress ? ` · progres ${percentFormat.format(t.rawProgress * 100)} % (di luar 0–100 %)`
        : ` · ${percentFormat.format(t.progress * 100)} %`;
    const baselineNote = t.bInverted ? ' · baseline tidak valid (selesai sebelum mulai)' : t.invalid.length ? ` · baseline tidak valid: ${t.invalid.join(', ')}` : '';
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
