# Laporan Paket UI-6d — SDM + Layanan + Aset: domain yang sehat, dan aturan yang akhirnya ditulis

> Domain keempat dan terakhir (keputusan pemilik). Layar: karyawan · absensi · timesheet · payroll ·
> cuti · sertifikat · tiket · pelanggaran SLA · CSAT · aset · utilisasi · pemeliharaan · profil.

---

## Audit: tiga belas layar, dan hampir tidak ada yang rusak

Sebelas layar disapu dengan instrumen yang UI-6b bangun (kotak baris dipotong terhadap tiap leluhur
yang menggulung), pada **1440 · 768 · 390 px**:

```
--- 1440px selesai ---   nol tabrakan, nol terpotong, nol gulir menyamping
---  768px selesai ---   nol tabrakan, nol terpotong, nol gulir menyamping
---  390px selesai ---   nol tabrakan, nol terpotong, nol gulir menyamping
```

Nol grafik di seluruh domain, jadi "grafik lebih sunyi dari angkanya" tidak berlaku di sini —
seperti di UI-6c. Nol ubin berempat anak, jadi regresi subgrid UI-5 tidak sampai ke sini.

Satu yang benar-benar salah, dan pembandingnya adalah **tetangganya sendiri**: `#/sla-breaches`
menggambar kedua angkanya dengan `.value.sm` — **16 px di dalam petak selebar 567 px** — sementara
`#/csat`, di modul yang SAMA, menggambar 22 px di petak 278 px, termasuk kalimat sepanjang "Belum
ada penilaian". Tidak ada alasan panjang-string di situ; ia hanya tidak pernah disepakati.

## Sensus: aturan yang tiga paket sebelumnya pakai tanpa pernah menuliskannya

UI-6b menyatukan baris ubin pahlawan `#/d/projects/1` dan UI-6c menyatukan `#/stock`, keduanya
dengan alasan yang sama. Sebelum mengulanginya untuk ketiga kalinya, seluruh aplikasi disensus —
**13 baris ubin tingkat atas**:

| keadaan | jumlah | layar |
|---|---|---|
| seragam **22 px** | 4 | `#/d/projects/1` (7 ubin) · `#/stock` (2) · `#/usulan-pesan-ulang` (3) · `#/csat` (4) |
| seragam **16 px** | 7 | `#/harga-satuan` (4) · `#/periods` (3) · laba rugi (3) · arus kas (3) · proyeksi kas (3) · `#/sla-breaches` (2) · dokumen PO (5) |
| **CAMPUR** | 2 | `#/evm` (3 indeks 22 px + 3 uang 16 px) · `#/tax-exports` (2 hitungan 22 px + 2 uang 16 px) |

Sensus itu mengubah rencana saya. Dugaan yang wajar — "yang kecil dipakai di baris yang ramai" —
**tidak terbukti**: petak paling sempit di seluruh aplikasi (181 px, tujuh ubin, `#/d/projects/1`)
memakai ukuran BESAR, dan yang paling lapang (567 px, dua ubin, `#/sla-breaches`) memakai yang
kecil. Yang benar-benar berkorelasi adalah **panjang string**: baris yang isinya uang rupiah penuh
memakai `.sm`.

Maka garisnya ditarik di tempat yang bisa dipertahankan:

* **Kontradiksi DI DALAM satu baris selalu cacat** — dua ukuran bersebelahan tidak terbaca sebagai
  hierarki, ia terbaca sebagai kelalaian. Dua baris campur itu disatukan, dan `#/sla-breaches`
  mengikuti tetangga modulnya.
* **Ukuran mana antar-layar adalah KEPUTUSAN NILAI** untuk seluruh aplikasi — tujuh baris 16 px
  lawan empat baris 22 px, di empat domain — dan mengubahnya mengubah apa yang angkanya KATAKAN
  (rupiah penuh menjadi bentuk ringkas), bukan hanya ukurannya. Itu dicatat untuk UI-7 dengan
  angkanya, bukan diputuskan diam-diam di paket domain terakhir.

Dan **di dalam kartu tidak disentuh sama sekali**: 16 px di sana adalah peran tersendiri yang UI-5
pertahankan dengan sengaja — 37 ubin lawan 3 di seluruh aplikasi.

## Yang berubah

| layar | sebelum | sesudah |
|---|---|---|
| `#/evm` | 3 × 22 px + 3 × 16 px | **6 × 22 px** (uangnya sudah ringkas; murni ukuran) |
| `#/tax-exports` | 2 × 22 px + 2 × 16 px | **4 × 22 px**, uang ringkas dengan angka penuh di baris konteks |
| `#/sla-breaches` | 2 × 16 px | **2 × 22 px** |

`moneyTile()` di `evm.js` mendapat opsi `hero` alih-alih diubah begitu saja — berkas yang sama
menggambar ubin uang DI DALAM kartu pada `#/d/projects/1`, dan di sana 16 px benar.

**Satu hal yang akan terlihat oleh siapa pun yang membandingkan tangkapan layar, dan itu disengaja:**
di `#/evm` ubin CPI berdiri lebih rendah daripada kelima tetangganya. Itu perbaikan UI-6b yang
bekerja — ubin itu punya anak keempat (kalimat basis `Σ EV ÷ Σ AC`), dan sampai UI-6b anak itu
tergambar MENIMPA kalimat peringatan di atasnya. Barisnya bisa diratakan lagi dengan membuat SELURUH
ubin dalam baris itu meminta empat trek (`.stat-row:has(> .stat > :nth-child(4)) > .stat`), tetapi
itu menambah ruang kosong di lima ubin untuk merapikan satu — dan tepi bawah yang lebih rendah itu
justru menarik mata ke satu-satunya ubin yang membawa peringatan. Dibiarkan, dengan sadar.

## Uji

| gerbang | hasil |
|---|---|
| `tests/Feature/Core` (SQLite) | **1.420 uji / 11.626 asersi**, 11 dilewati |
| `tests/Unit` | **630 uji / 2.201 asersi** |
| `tests/Feature/Core` (MySQL 8.0.46) | **1.420 uji / 11.633 asersi**, 9 dilewati |
| harness Playwright, 29 skenario gerbang | semua **ok**, keluar 0 — `docs/bukti-uji/results-ui-6d.json` |

**A/B terhadap UI-6c (`6cd9e68`), 15 rute:**

```
#/evm          beda=3   {font-size 3}          #/csat            beda=0
#/tax-exports  beda=4   {font-size 2, _ada 2}  #/d/projects/1    beda=0
#/sla-breaches beda=8   {font-size 2, _box 6}  #/stock           beda=0
                                               #/dashboard       beda=0    #/            beda=0
                                               #/periods         beda=0    laba rugi     beda=0
                                               #/harga-satuan    beda=0    #/profil      beda=0
                                               karyawan · tiket · aset     beda=0
```

**Dua belas layar tidak bergerak sepiksel pun**, termasuk ketiga daftar generik yang UI-6c sentuh —
bukti bahwa opsi `hero` tidak bocor ke ubin di dalam kartu.

**Kecocokan dan tema:** ketiga layar yang berubah diukur di 1440 / 1024 / 768 / 390 px × terang dan
gelap — **24 pengukuran**, semuanya `ukuran=['22px']`, nol nilai terpotong, nol gulir menyamping.

**S53 dibuktikan MERAH dua kali:**

| sabotase | yang dikatakan gerbang |
|---|---|
| satu ubin `#/evm` kembali ke `.value.sm` | `satu baris 6 ubin memakai 2 ukuran sekaligus {'22px': 5, '16px': 1} — ['PROYEK TERUKUR', …]` |
| satu ubin `#/tax-exports` kembali | `satu baris 4 ubin memakai 2 ukuran sekaligus {'22px': 3, '16px': 1} — ['SIAP DIEKSPOR', …]` |

S53 membawa penjaga anti-gerbang-hampa yang sama seperti S52: kalau sensusnya menemukan kurang dari
delapan baris, ia gagal — karena sensus yang tidak sampai ke layarnya tidak membuktikan apa pun.
Angka hari ini: **13 baris**.

`SHELL_VERSION` **31 → 32**. Gaya sebaris `views/` tetap **538**: paket ini menukar kelas, tidak
mencabut objek gaya.

## Yang sengaja tidak dikerjakan

* **Menyatukan ukuran antar-layar** (7 baris 16 px lawan 4 baris 22 px) — lihat alasannya di atas.
  Ia butuh keputusan tentang apa yang angka hero KATAKAN, bukan hanya seberapa besar ia digambar.
* **`profil.js` (22 gaya sebaris di berkas, 16 tergambar).** Ia layar pengaturan pribadi dengan
  tata letak sendiri; sapuannya jenis yang sama dengan `evm.js`/`project.js` — tambalan margin yang
  menunggu adopsi `.stack`. UI-7.
* **Baris ubin DI DALAM kartu.** 37 ubin pada 16 px lawan 3 pada 22 px; normanya jelas dan UI-5
  mempertahankannya dengan sengaja. Tidak ada yang perlu diperbaiki.
