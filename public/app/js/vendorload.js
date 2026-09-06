/*
 * Pemuat malas untuk pustaka vendor UMD (P1-D).
 *
 * SortableJS 1.15.7 dilayani sebagai berkas UMD, bukan modul ES: `import`
 * atasnya tidak memberi apa pun, dan mengubah berkas vendor supaya bisa
 * di-`import` berarti berkas yang sha256-nya tidak lagi sama dengan tarball
 * yang diverifikasi terhadap registry — persis yang VendorManifestTest ada
 * untuk mencegah. Maka ia dimuat sebagai <script> dan mendaftar di
 * window.Sortable, apa adanya.
 *
 * MALAS, dan itu keputusan yang tertulis di VENDOR.md: 15 KB gzip tidak boleh
 * ikut setiap pemuatan shell hanya karena SATU laci di SATU layar memakainya.
 * Yang memuatnya adalah laci "Atur dasbor" ketika DIBUKA (dan papan kanban
 * P1-G nanti) — pembaca yang tidak pernah mengatur dasbornya tidak pernah
 * mengunduhnya.
 *
 * URL-nya RELATIF terhadap /app/ dan tidak pernah absolut ke host lain:
 * aturan tanpa-CDN dipindai VendorManifestTest ke seluruh public/app.
 */

const pending = new Map();

/**
 * Muat satu skrip vendor sekali saja; percobaan berikutnya memakai janji yang
 * sama. Gagal (berkas hilang di deploy, jaringan putus) MENOLAK — pemanggilnya
 * yang memutuskan cara terdegradasi, karena hanya ia yang tahu apa yang hilang.
 */
export function loadVendorScript(src) {
  if (pending.has(src)) return pending.get(src);

  const promise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.addEventListener('load', () => resolve());
    script.addEventListener('error', () => {
      // Dibuang dari cache: percobaan berikutnya (tombol yang diklik lagi)
      // boleh mencoba lagi, bukan mewarisi kegagalan selamanya.
      pending.delete(src);
      reject(new Error(`Gagal memuat ${src}`));
    });
    document.head.appendChild(script);
  });

  pending.set(src, promise);
  return promise;
}

export const SORTABLE_SRC = 'vendor/sortablejs@1.15.7/Sortable.min.js';

/** window.Sortable, dimuat bila perlu. Menolak bila berkasnya tidak ada. */
export async function sortable() {
  if (!window.Sortable) await loadVendorScript(SORTABLE_SRC);
  if (!window.Sortable) throw new Error('SortableJS dimuat tetapi tidak mendaftarkan window.Sortable.');
  return window.Sortable;
}
