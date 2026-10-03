# UI/UX Redesign — Nusantara ERP

Status: draf arsitektur (Phase A–B selesai sebagai desain; implementasi belum dimulai).
Sumber: `UX Audit & Brief.dc.html`, `Nusantara ERP Redesign.dc.html` (prototipe), `Current UI.dc.html` (rekreasi keadaan saat ini).

## 1. Tujuan

Karyawan non-spesialis harus bisa: tahu apa yang harus dikerjakan hari ini, menemukan layarnya,
mengisi data dengan benar, memahami status dokumen, dan menyelesaikan prosesnya — tanpa memahami
modul, entitas basis data, atau istilah ERP. Semua aturan bisnis, izin, API, dan jejak audit tetap.

Urutan optimasi: kejelasan → penyelesaian tugas → pencegahan kesalahan → keyakinan → efisiensi → polesan.

## 2. Masalah yang ditemukan (ringkas; bukti di brief)

| Tingkat | Masalah | Lokasi |
|---|---|---|
| Kritis | Navigasi modul-sentris: 14 grup / 131 rute; admin 121 tautan = 4,9 viewport | `schema.js NAV`, `app.js renderNav` |
| Kritis | Tidak ada "Pekerjaan Saya"; landing = dasbor BI (desktop) / kisi modul (ponsel) | `home.js`, `dashboard.js`, aturan landing `app.js` |
| Kritis | Halaman dokumen = tumpahan `Object.keys(record)` dengan `titleize()` | `detail.js renderDetail` |
| Tinggi | Formulir transaksional 13 field + baris item di modal 960 px | `form.js` |
| Tinggi | Status hanya lencana satu kata; kalimat keadaan terpisah dari tombol keputusan | `detail.js statusStrip`, `ui.js badge` |
| Tinggi | Filter permanen, tanpa keping aktif; urut kolom tak terlihat | `list.js filterBar` |
| Tinggi | Beranda modul = kisi tautan setara, tanpa alur proses | `module.js` |
| Sedang | Aksi baris berulang (Cetak·Ubah·Hapus ikon 28 px); dua sistem ikon; pencarian berorientasi kode | `list.js`, `ui.js`, `search.js` |

## 3. Arsitektur informasi

Tiga lapis. NAV di `schema.js` **tidak diubah strukturnya**; yang berubah adalah apa yang tampil lebih dulu.

1. **Ruang kerja saya** — Beranda · Persetujuan (core/inbox) · Pekerjaan Saya (daftar generik tersaring `created_by = saya` + status) · Favorit · Terakhir dibuka. Selalu tampil.
2. **Bidang kerja** — 14 grup NAV, dilipat, hanya grup aktif terbuka, maks ~8 tautan tampil + "Semua layar <modul> →" ke beranda modul.
3. **Layar & dokumen** — list/form/detail generik + layar khusus. Tidak ada rute dihapus/dipindah.

Landing tunggal untuk semua lebar: `#/home` (ruang kerja). `#/dashboard` tetap untuk manajemen.

## 4. Shell

- Sidebar 248 px, rel ikon 64 px (tablet selalu rel; label tetap di tooltip & beranda modul). Penanda grup aktif memakai aksen modul §12 CONVENTIONS.
- Bilah atas: remah modul beraksen (tetap), kotak pencarian nyata (Ctrl+K), **Buat ▾** peka-izin & peka-modul (mengumpulkan tombol Tambah yang sudah ada), lonceng, akun. Tema pindah ke menu akun.
- ≤ 760 px: bilah bawah Beranda · Persetujuan · Cari · Menu; laci untuk lapis 2–3.

## 5. View generik

### Daftar (`list.js`)
Kepala: nama + `description` + jumlah + Buat. Tab status cepat dari `statusColumn.enum`. Pencarian + tombol Filter (popover) → keping aktif yang bisa dilepas + "Hapus semua"; teks jumlah menyebut "tersaring" hanya bila tersaring. Panah urut tampak. Satu aksi utama (Buka, baris klik) + menu ⋯ (Cetak, Ubah, Duplikat, — Hapus). Kunci skema opsional: `quickFilters`, `primaryColumn`.

### Formulir (`form.js`, rute baru `#/f/<resource>/new|<id>`)
Layout dihitung: `lines` ada atau > 8 field → halaman penuh dengan navigasi bagian (desktop), bagian "Lanjutan" dilipat (`section.advanced`), total hidup, kaki lengket Batal · Simpan draf · Simpan & ajukan (hanya bila aksi `submit` ada dan izinnya dipegang). Ringkasan galat di atas dengan tautan lompat + galat per field; `liveNote` tetap. Formulir pendek tetap modal. Kunci: `form.layout: 'page'|'modal'`.

### Detail (`detail.js`)
Kepala dokumen: jenis, lencana berkalimat, nomor (mono), pihak, proyek. **Panel tindakan** hanya bila giliran pembaca (Setujui / Tolak… dengan dialog alasan wajib); setelah diputus panel berganti kalimat keadaan + langkah berikutnya (mis. "Buat GRN"). 4–5 nilai kunci (`detail.headline`). Tab Ringkasan · Item · Persetujuan · Lampiran · Aktivitas bila konten > 1 kartu. Kartu Informasi memakai **label `form.fields`**, bukan `titleize`; kunci tanpa label → "Bidang lainnya" dilipat.

## 6. Bahasa status (satu pemetaan di `enums.js`)

| enum | lencana | kalimat |
|---|---|---|
| draft | Draf | Belum diajukan — lengkapi lalu tekan Ajukan. |
| submitted | Menunggu persetujuan | Menunggu persetujuan Anda (tingkat n dari m) / Diajukan ke penyetuju; tidak bisa diubah sampai diputus. |
| rejected | Ditolak — perlu perbaikan | Ditolak oleh X: "…". Perbaiki lalu ajukan lagi. |
| approved | Disetujui | Disetujui dan terkunci. Langkah berikutnya: <dokumen lanjutan>. |
| posted/closed | Diposting / Selesai | Sudah dibukukan; perubahan hanya lewat pembalikan atau revisi. |

Lencana = ikon + kata. Aksen modul tidak pernah untuk status.

## 7. Sistem visual

Token baru (lihat `:root` di prototipe): latar `#f4f5f7`, permukaan `#fff`, teks `#13181e`, primer `#2b4fc8`, aksen "perlu tindakan" `#8a5a0f/#fbf1dc`; gelap `#0e1216/#161b21/#7d9df3`. Semantik success/warning/danger/info dan `--accent-1..8` **nilai lama dipertahankan**. Tipografi: Instrument Sans (UI) + IBM Plex Mono (nomor, Rp) — **di-vendor lokal** (VendorManifestTest, tanpa CDN). Radius 8/10/14; bayangan hanya lapisan terangkat.

## 8. Berkas terdampak

- Shell: `app.js` (buildShell, renderNav, landing), `crumbs.js`, `search.js`, `notifications.js`, `sw.js SHELL` + bump versi.
- Sistem desain: `app.css` (disusun ulang per komponen), `ui.js` (pageHeader, docHeader, statusBadge, filterChip, overflowMenu, tabs, drawer, stepper, actionPanel), `enums.js`, `illustrations.js`.
- Generik: `views/list.js`, `views/form.js`, `views/detail.js`, `views/actions.js`, `router.js`.
- Beranda: `views/home.js`, `views/module.js`, `views/tugas.js`, baru `views/pekerjaan.js`; `dashboard.js` + `widgets/*` kerangka kartu.
- Skema: kunci opsional `description`, `quickFilters`, `detail.headline`, `form.layout`, `MODULES[*].flow` — data saja.
- Uji: `SidebarNavWiringTest`, `LauncherWiringTest`, `PwaServiceWorkerTest`, `VendorManifestTest`, harness S8/S20/S21; baru: primitif ui.js, kalimat status.

## 9. Risiko & mitigasi

RBAC rendah (semua dari `visibleNav()`/`session.can()`/jawaban server tersaring). Skema sedang (~90 layar; kunci baru opsional dengan bawaan lama + snapshot per resource). SW pasti (SHELL + versi). Cetak rendah (komponen baru masuk daftar sembunyi). Ponsel sedang (bilah bawah vs onboarding sheet z-45 / laci z-50). Gelap rendah (token di 4 blok + cetak). Uji sedang (perbarui yang memaku DOM, jangan dilonggarkan).

## 10. Fase

1 Fondasi (token, primitif, font lokal, kalimat status) → 2 Shell → 3 View generik → 4 Beranda & Pekerjaan Saya & beranda modul → 5 Pencarian berjenis → 6 Layar khusus (mulai Lapangan, Workspace Proyek) → 7 A11y & responsif 390/768/1024/1440 → 8 Regresi (PHPUnit, harness, 7 perjalanan §22).

## 11. Keterbatasan yang diketahui

- Prototipe memakai Google Fonts; implementasi wajib vendor lokal.
- Angka di prototipe adalah data demo seed; semua angka produksi tetap dari server (aturan "— bukan 0").
