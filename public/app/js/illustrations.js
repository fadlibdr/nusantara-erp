/*
 * Ilustrasi keadaan kosong (P1-B) — lima gambar garis kecil untuk
 * ui.js emptyState({ kind }): inbox · search · filter · error · done.
 *
 * Aturannya: tidak ada satu pun literal warna di berkas ini. Setiap bentuk
 * hanya membawa kelas — .ln garis pendukung, .ac garis aksen, .fl bidang
 * pendukung, .fa bidang aksen — dan app.css (§ empty/err) yang memberinya
 * token: --border-strong / --primary / --surface-3 / --primary-soft, dengan
 * --danger untuk kind error dan --success untuk kind done. Jadi ilustrasi
 * mengikuti tema terang/gelap dengan sendirinya, dan harness S21 bisa
 * mengukur stroke terkomputasinya terhadap token. Dirancang untuk 96–120 px
 * (kotak 120 × 120, garis 1,5–1,75 px); ≤ 1,5 KB per gambar.
 */

const SHADOW = '<ellipse class="fl" cx="60" cy="102" rx="38" ry="5"/>';

const ILLUSTRATIONS = {
  // Nampan kosong dengan panah masuk — belum ada yang tercatat.
  inbox: `${SHADOW}
<path class="fl" d="M24 62h72v30a6 6 0 0 1-6 6H30a6 6 0 0 1-6-6z"/>
<path class="ln" d="M24 62 36 36h48l12 26v30a6 6 0 0 1-6 6H30a6 6 0 0 1-6-6z"/>
<path class="ln" d="M24 62h20l6 10h20l6-10h20"/>
<path class="ac" d="M60 14v28M49 31l11 11 11-11"/>`,
  // Lembar dengan baris kosong dan kaca pembesar — pencarian tanpa hasil.
  search: `${SHADOW}
<rect class="fl" x="22" y="24" width="60" height="70" rx="6"/>
<path class="ln" d="M22 30a6 6 0 0 1 6-6h48a6 6 0 0 1 6 6v64a6 6 0 0 1-6 6H28a6 6 0 0 1-6-6z"/>
<path class="ln" d="M34 44h36M34 56h28M34 68h20"/>
<circle class="fa" cx="82" cy="72" r="15"/>
<path class="ac" d="M82 57a15 15 0 1 0 0 30 15 15 0 0 0 0-30zM93 83l12 12"/>`,
  // Corong dengan tanda silang — filter menyaring semuanya.
  filter: `${SHADOW}
<path class="fl" d="M24 26h72L68 60v30l-16 8V60z"/>
<path class="ln" d="M24 26h72L68 60v30l-16 8V60z"/>
<path class="ln" d="M36 40h48"/>
<circle class="fa" cx="88" cy="84" r="14"/>
<path class="ac" d="M88 70a14 14 0 1 0 0 28 14 14 0 0 0 0-28zM82 78l12 12M94 78 82 90"/>`,
  // Dokumen dengan segitiga peringatan — sumbernya gagal, bukan kosong.
  error: `${SHADOW}
<path class="fl" d="M30 20h40l18 18v52a6 6 0 0 1-6 6H30a6 6 0 0 1-6-6V26a6 6 0 0 1 6-6z"/>
<path class="ln" d="M30 20h40l18 18v52a6 6 0 0 1-6 6H30a6 6 0 0 1-6-6V26a6 6 0 0 1 6-6zM70 20v18h18"/>
<path class="ln" d="M36 50h24M36 62h30"/>
<path class="fa" d="M84 62 104 96H64z"/>
<path class="ac" d="M84 62 104 96H64zM84 74v10M84 90v1"/>`,
  // Papan jepit dengan centang — semuanya sudah selesai.
  done: `${SHADOW}
<rect class="fl" x="26" y="24" width="60" height="72" rx="6"/>
<path class="ln" d="M26 30a6 6 0 0 1 6-6h48a6 6 0 0 1 6 6v60a6 6 0 0 1-6 6H32a6 6 0 0 1-6-6z"/>
<path class="ln" d="M46 24v-4a4 4 0 0 1 4-4h12a4 4 0 0 1 4 4v4M38 50h24M38 62h18"/>
<circle class="fa" cx="82" cy="74" r="15"/>
<path class="ac" d="M82 59a15 15 0 1 0 0 30 15 15 0 0 0 0-30zM74 74l6 6 10-11"/>`,
};

export const ILLUSTRATION_KINDS = Object.keys(ILLUSTRATIONS);

/**
 * <svg class="illus" data-kind="…"> untuk satu jenis; jenis yang tidak dikenal
 * jatuh ke 'inbox' (bukan galat — keadaan kosong tidak boleh gagal digambar).
 * Ukuran diberikan app.css (.empty .illus), bukan atribut, supaya varian
 * .compact cukup mengubah satu aturan.
 */
export function illustration(kind = 'inbox') {
  const name = ILLUSTRATIONS[kind] ? kind : 'inbox';
  const template = document.createElement('template');
  template.innerHTML = `<svg class="illus" data-kind="${name}" viewBox="0 0 120 120" aria-hidden="true" focusable="false">${ILLUSTRATIONS[name]}</svg>`;
  return template.content.firstElementChild;
}
