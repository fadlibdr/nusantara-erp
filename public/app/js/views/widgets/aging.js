/*
 * Keranjang umur piutang/hutang - SATU definisi untuk dua widget.
 *
 * Label dan nada warnanya disalin dari views/reports.js dengan sengaja dan
 * DIPAKU DashboardWidgetRegistryTest terhadap berkas itu: layar Laporan dan
 * widget dasbor yang menyebut "31-60 hari" dengan warna berbeda membuat
 * pembacanya mengira ia sedang melihat dua ukuran yang berbeda.
 *
 * BUKAN widget: berkas ini tidak punya build(), jadi ia tidak muncul di
 * katalog dan tidak diminta bercabang failure() (pemanggilnyalah yang
 * bercabang, sebelum memanggil agingEntries).
 */

export const AGING_BUCKETS = [
  { key: 'current', label: 'Belum jatuh tempo', tone: '' },
  { key: '1_30', label: '1-30 hari', tone: '' },
  { key: '31_60', label: '31-60 hari', tone: 'amber' },
  { key: '61_90', label: '61-90 hari', tone: 'amber' },
  { key: 'over_90', label: '> 90 hari', tone: 'red' },
];

/** buckets server -> baris batang, urut umur, keranjang kosong tetap digambar. */
export function agingEntries(buckets) {
  return AGING_BUCKETS.map((bucket) => ({
    label: bucket.label,
    tone: bucket.tone,
    value: Number(buckets[bucket.key] || 0),
  }));
}
