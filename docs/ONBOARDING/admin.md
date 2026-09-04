# Onboarding minggu pertama — Administrator Sistem (`admin`)

**Peran akun:** `admin` · **Akun demo:** `admin@nusantara.test` (Administrator Sistem) ·
**Manual lengkap:** `docs/PANDUAN-ADMINISTRATOR.md` — seluruhnya milik Anda; mulailah
dari halaman **Rujukan cepat** dan ADMINISTRATOR §1. `docs/PANDUAN-PENGGUNA.md` bab 1, 2,
dan **14** menjelaskan apa yang orang lain lihat dan kapan mereka akan datang kepada Anda.

> Panduan ini **bukan** manual. Ia jalan masuk ke manual: siapa Anda di sistem, apa yang
> Anda lihat hari pertama, sepuluh pekerjaan yang benar-benar Anda kerjakan, aturan yang
> akan menolak Anda, dan daftar periksa. Setiap langkah menunjuk pasal yang
> menjelaskannya — **ADMINISTRATOR §** untuk panduan administrator, **PANDUAN §** untuk
> panduan pengguna. Yang ditulis di sini hanyalah yang benar-benar ada hari ini.

---

## 1. Siapa Anda di sistem

Anda memegang **seluruh 86 izin** — keempat belas modul dikali enam aksi, plus dua izin
direktur (ADMINISTRATOR §3.1, §3.2) — dan Anda diasumsikan bisa masuk ke server lewat
shell (ADMINISTRATOR §1). Anda **bukan** bagian dari rantai proses; Anda orang yang
membuat rantai itu bisa berjalan: akun dan peran, profil perusahaan dan pengaturan, master
data yang tidak punya importer, alarm pagi, cadangan, dan setiap tombol yang tidak
dipegang peran lain.

Tiga hal yang membuat akun Anda berbeda dari semua akun lain:

- **Setiap kontrol berbentuk "peran A tidak memegang izin B" tidak berlaku bagi Anda**,
  karena Anda memegang semua B. Anda satu-satunya login yang bisa menyetujui sebuah
  pembayaran lalu memposting pembayaran yang sama, menutup lalu membuka periode yang sama
  (ADMINISTRATOR §3.3, §6.2). Yang masih menahan Anda hanyalah maker-checker dan aturan
  pemegang laci.
- **Kotak masuk Anda menerima setiap kelompok alarm** — cadangan, tutup buku, kesembilan
  belas pengawas tenggat, dan pengajuan semua 28 jenis dokumen — tanpa penyaringan
  (ADMINISTRATOR §5.10). Kotak masuk yang penuh adalah kotak masuk yang berhenti dibaca.
- **Tiga keluarga tombol hari ini hanya bisa Anda tekan** (atau teknisi untuk yang pertama):
  `Posting ke Stok` dan seluruh tombol stok (PANDUAN §6.1); `Bayar Retensi` dan `Cairkan
  Uang Muka` di halaman SPK, yang menuntut `scm.post` **dan** `fin.approve` sekaligus
  (PANDUAN §8.6–§8.7); serta tombol simpan **Pengaturan** dan **Profil Perusahaan**
  (`core.update`, ADMINISTRATOR §3.3).

Tempat Anda terhadap rantai proses (ANALISIS-PROSES-BISNIS §1.4–§1.5): kendali yang sudah
dibangun — maker-checker, ambang direktur, gerbang anggaran, prakualifikasi, tiga arah,
sembilan belas tenggat — hanya bekerja bila **peran di produksi memegang izinnya**. Cabang
Engineering dan Mutu sempat tidak terjangkau di erp1 karena peran kehilangan `eng.*` /
`qc.*` (ANALISIS §1.4). Menjaga peran tetap sesuai seeder adalah pekerjaan Anda.

## 2. Hari pertama

**Masuk.** Buka https://erp1.pi2.co.id, halaman **"Masuk ke akun Anda"**, isi **Email**
dan **Kata sandi**, tekan **`Masuk`** (PANDUAN §1.1). Sesi berumur 12 jam. Halaman masuk
memuat blok **"Akun demo"** bawaan pemasangan; menanganinya adalah pekerjaan Anda
(ADMINISTRATOR §5.9, §12(a)).

**Sidebar Anda** memuat **seluruh empat belas kelompok**. Yang benar-benar milik Anda:

| Kelompok | Layar yang akan Anda buka |
|---|---|
| **Sistem** | **Pengguna** · **Peran & Hak Akses** · **Profil Perusahaan** · Impor Data Master · Impor Dokumen · **Pengaturan** |
| Keuangan | Periode Fiskal · Bagan Akun · Rekening Bank · Pajak — penyiapan yang tidak punya importer |
| Persediaan | Gudang · Kategori Item — FK nyata sebelum dokumen stok pertama |
| Ringkasan | Dasbor · **Tenggat** · Kalender |
| Sebelas kelompok lain | milik peran lain; Anda membukanya saat menjadi cadangan penyetuju atau pemosting |

**Dasbor Anda** menampilkan semua ubin — **Proyek berjalan**, **Piutang belum tertagih**,
**Hutang belum dibayar**, **Saldo bank**, **Termin siap ditagih**, **Tiket aktif** — dan
semua kartu, termasuk **Menunggu persetujuan Anda** untuk 11 jenis dokumen. Anda akan
tergoda menyetujui dari sana; pada susunan peran bawaan, untuk penawaran, BOQ/RAP,
PR/PO, subkontrak, payroll, dan opname stok, Anda dan direktur adalah satu-satunya
penyetuju (ADMINISTRATOR §3.2) — jadi kadang memang harus.

**Lonceng dan Tenggat.** Setiap pagi, berurutan: **08.00** `erp:backup-watch` (ke pemegang
`core.approve`: Anda dan direktur; di erp1 hari ini berbunyi *"Salinan cadangan offsite
belum dikonfigurasi"*), **08.15** `fin:close-watch` (*"Periode {label} belum ditutup"*, ke
pemegang `fin.post`: Anda dan petugas keuangan), **08.30** `erp:deadline-watch` (semua 19
tenggat; Anda melihat seluruhnya di **Ringkasan › Tenggat**). Menandai dibaca **membuka
pintu** kiriman berikutnya; alarm cadangan dan tutup buku berbunyi lagi besok sampai
sebabnya dibereskan. Keluaran CLI keenam perintah terjadwal dibuang ke `/dev/null`; baris
**BLIND** hanya terbaca bila perintahnya dijalankan tangan
(ADMINISTRATOR §5.1, §5.2, §5.8, §5.10, §5.11).

**Enam kalimat untuk semua orang** (PANDUAN §0) — dan apa artinya bagi Anda:

1. Menu tanpa izin tidak digambar — pelapor menyebutnya "rusak"; itu izin, bukan kerusakan.
2. `Ubah`/`Hapus` hilang begitu dokumen diajukan; obatnya `Tolak`, bukan ubah basis data.
3. Pengaju tidak boleh menyetujui dokumennya sendiri — Anda pun; sakelarnya di Pengaturan.
4. Yang terposting tidak bisa dibatalkan; ADMINISTRATOR §8.4 memuat empat kategorinya.
5. Tidak ada layar ganti sandi — tiap permintaan sandi datang ke Anda (ADMINISTRATOR §3.4).
6. Sesi 12 jam; token bertahan melewati ganti sandi — laptop hilang berarti **Nonaktifkan**.

## 3. Pekerjaan Anda

Setiap butir: pemicu → layar atau perintah → yang terjadi → pasal.

**1. Akun admin kedua, sebelum apa pun** — ADMINISTRATOR §3.4
Pemicu: hari pertama. Di erp1 hanya ada **satu** akun admin, dan formulir Ubah pengguna
**mengganti peran seutuhnya** — menyimpan dengan semua centang Peran kosong mencabut
seluruh peran, tanpa konfirmasi, termasuk peran admin Anda sendiri. Jalan pulangnya hanya
tinker di server. **Sistem › Pengguna › `Tambah Pengguna`**: buat admin kedua dulu, baru
sentuh akun yang lain.

**2. Membuat pengguna dan memberi peran** — ADMINISTRATOR §3.4, §3.6; PANDUAN §14.2
Pemicu: karyawan baru. **Sistem › Pengguna › `Tambah Pengguna`**: Nama, Email, Kata sandi
(minimal 8 karakter lewat layar), **Karyawan terkait** (dari master karyawan — tanpa itu
sakelar "Proyek saya" dan slip gaji tidak menemukan orangnya), **Peran**, Aktif. Tidak ada
pemberian izin per orang — permintaan yang benar selalu "tambahkan saya ke peran X".
Perubahan peran **tidak langsung terlihat** di layar orangnya: suruh muat ulang atau
keluar-masuk (ADMINISTRATOR §3.5). Peran baru lahir tanpa izin; centang per modul lalu
**`Simpan Hak Akses`** — menyimpan mengganti seluruh set. Jangan menjalankan ulang
`db:seed` di basis data hidup: ia mengembalikan kedua belas peran bawaan ke bentuk seeder
(ADMINISTRATOR §3.6).

**3. Kata sandi, akun keluar, laptop hilang** — ADMINISTRATOR §3.4, §3.5
Pemicu: permintaan lewat PANDUAN §14.1. Ganti sandi = isi kolom **Kata sandi** di formulir
Ubah (kosong berarti pertahankan). **Mengganti sandi tidak mengeluarkan orang itu** — token
bertahan 12 jam. Karyawan keluar atau laptop hilang: tombol **"Nonaktifkan"** — konfirmasi
*"Nonaktifkan pengguna ini? Semua token API-nya dicabut. Pengguna tidak pernah dihapus
permanen karena id-nya dipakai di dokumen."* — lalu, bila perlu, aktifkan kembali dengan
sandi baru. Emailnya tidak bisa dipakai ulang. Anda tidak bisa menonaktifkan diri sendiri.

**4. Profil perusahaan sebelum invoice pertama** — ADMINISTRATOR §4.2
Pemicu: sebelum invoice pertama dan ekspor pajak pertama. **Sistem › Profil Perusahaan**:
`legal_name` adalah kop setiap formulir; `npwp` ikut ke muatan e-Faktur/e-Bupot **dan tidak
ada yang memeriksanya** — di erp1 hari ini masih `01.234.567.8-012.000`, dummy seeder.
Logo tidak bisa diunggah dari layar; berkasnya ditaruh di server (PNG/JPG/GIF, ≤ 1 MiB)
dan yang salah format diam-diam tidak menghasilkan logo.

**5. Pengaturan dan penomoran** — ADMINISTRATOR §4.6, §4.8, §3.8
Pemicu: kebijakan direksi. **Sistem › Pengaturan**: pasangan tarif PPN yang harus bergerak
bersama; **dua ambang persetujuan direktur** — PO Rp 100.000.000 dan SPK Rp 200.000.000
pada bawaan — dan sakelar **"Wajib pemisahan tugas (maker-checker)"** di kelompok Proyek &
Persetujuan; 59 format penomoran, masing-masing wajib memuat `{Y}` dan salah satu
`{N3}/{N4}/{N5}` — format tanpa `{Y}` meledak pada 1 Januari. **Menyunting parameter hanya
memengaruhi dokumen yang dibuat sesudahnya.** Tangga award (100 juta / 1 miliar), bobot
penilaian penawaran, dan ambang tutup buku sengaja **tidak ada di layar** — mengubahnya
berarti menyunting `config/erp.php` dan men-deploy.

**6. Master yang tidak punya importer** — ADMINISTRATOR §4.1; PANDUAN §2.9
Pemicu: instalasi baru, atau modul yang mulai dipakai. Urutan yang dipaksa kode: bagan
akun dan kalender fiskal sebelum posting apa pun; kategori item sebelum item; **Gudang**
dan **Rekening Bank** sebelum dokumen stok atau pembayaran pertama — keduanya nol
tersemai, tanpa importer. Item, vendor, pelanggan, karyawan, lokasi tapak lewat **Sistem ›
Impor Data Master** (pratinjau dulu, simpan kemudian; pencocokan lewat kolom `kode`;
kolom yang ada tetapi kosong **ditulis kosong**). Proyek boleh menunjuk kontrak yang tidak
ada dan tidak ada yang mengatakannya (ADMINISTRATOR §4.1).

**7. Membaca alarm pagi, lalu tutup buku** — ADMINISTRATOR §5.6, §5.7, §6
Pemicu: lonceng pukul 08.00–08.30. Alarm cadangan: tujuh keadaan, judulnya menyebut yang
mana. *"Periode {label} belum ditutup"*: daftar periksa sebelas butir di **Keuangan ›
Periode Fiskal** — lima memblokir tanpa pengabaian, enam diakui dengan alasan ≥ 10
karakter. Dokumen menggantung yang sudah **diajukan** harus **ditolak dulu**
(ADMINISTRATOR §6.4). Pada perusahaan ini **penutupan tidak dapat dibatalkan** begitu run
PSAK 115 terposting (ADMINISTRATOR §6.1). Anda memegang tutup **dan** buka; memakainya
berdua meninggalkan jejak audit dua baris yang menunjukkan persis itu (ADMINISTRATOR §6.2)
— biarkan petugas keuangan menutup, manajer keuangan membuka.

**8. Cadangan: langkah yang hanya pemilik kerjakan** — ADMINISTRATOR §10.2, §10.3, §10.5
Pemicu: alarm *"belum dikonfigurasi"* setiap pagi. Tunjuk tujuan offsite di
`/etc/erp1/backup.conf` (format ketat: `KUNCI=nilai`, tanpa spasi, tanpa kutip, tanpa
CRLF), lalu buktikan ujung ke ujung sebagai root lewat `bash`: `bash
/var/www/erp1.pi2.co.id/deploy/backup-erp1.sh --offsite-only` dan `--restore-drill`.
**Salin kunci enkripsi ke luar mesin** — tanpa itu setiap salinan luar adalah derau.
Prosedur pemulihan sungguhan **belum terdokumentasi** (ADMINISTRATOR §10.5); lulus uji
pemulihan bukan pernah memulihkan.

**9. Tombol yang datang kepada Anda karena tidak ada orang lain** — PANDUAN §6.1, §8.6,
PANDUAN §8.7, §14.2
Pemicu: gudang tidak bisa memposting; manajer proyek tidak bisa
melepas retensi. `Posting ke Stok`, `Kirim`/`Terima` transfer, `Posting Retur`, `Batalkan
Penerimaan`, `Batalkan Bon` — pemegangnya admin dan teknisi. `Bayar Retensi` dan `Cairkan
Uang Muka` di halaman SPK — hanya Anda; satu klik menerbitkan tagihan vendor yang sudah
disetujui. Bila perusahaan ingin gudangnya memposting sendiri, itu perubahan peran
(ADMINISTRATOR §3), bukan kebiasaan admin memposting.

**10. Menjawab permintaan bantuan, dan merilis** — ADMINISTRATOR §11.1, §9.2, §1;
PANDUAN §14.5; DEPLOYMENT.md §4
Pemicu: pesan "tidak bisa". Minta **empat hal**: alamat
halaman lengkap (dengan bagian setelah `#`), kode dokumen, teks merah persis, tombol yang
ditekan. Lalu tabel gejala → sebab → pemeriksaan (ADMINISTRATOR §11.1). Tiga yang paling
sering: tombol cetak hilang karena **katalog cetak di-cache seumur sesi dan hanya
disegarkan saat login** — suruh keluar-masuk (ADMINISTRATOR §9.2); *"Halaman "…" tidak
dikenal."* setelah deploy — tab lama, paksa pemuatan dokumen; *"attempt to write a
readonly database"* — artisan pernah dijalankan sebagai root di pohon produksi. Setiap
perintah server: `cd /var/www/erp1.pi2.co.id` lalu `sudo -u www-data env HOME=/tmp php
artisan <perintah>` (ADMINISTRATOR §1). Rilis rutin mengikuti DEPLOYMENT.md §4; setelah
deploy yang menambah tabel, pastikan tabelnya benar-benar mendarat.

## 4. Yang akan menolak Anda

| Saat Anda | Penolakan (kata demi kata) | Pasal |
|---|---|---|
| mengubah atau menghapus peran admin | *"Role admin tidak dapat diubah."* / *"Role admin tidak dapat dihapus."* | ADMINISTRATOR §3.3 |
| menonaktifkan akun sendiri | *"Tidak dapat menonaktifkan akun sendiri."* | ADMINISTRATOR §3.4 |
| menghapus peran yang masih dipegang | *"Role masih dipakai oleh user — lepaskan dulu dari semua user."* | ADMINISTRATOR §3.6 |
| menyetujui dokumen yang Anda ajukan sendiri | *"{Dokumen} {KODE} diajukan oleh {Nama}; dokumen tidak boleh disetujui oleh pengajunya sendiri. Minta persetujuan pengguna lain pemegang izin {modul}.approve, atau matikan "Wajib pemisahan tugas" …"* | PANDUAN §2.5 |
| memposting bon di laci orang lain — **tanpa pengecualian admin** | *"Hanya pemegang kas kecil {kode} yang dapat {tindakan} — uang tunainya ada di laci pemegang, bukan di layar orang lain. Bila pemegangnya berganti, ubah dulu pemegang pada data kas kecilnya."* | ADMINISTRATOR §3.8 |
| menyimpan parameter yang ditetapkan saat instalasi | *"Parameter {kunci} ditetapkan saat instalasi di config/erp.php dan tidak dapat diubah dari layar ini; mengubahnya membutuhkan deploy."* | ADMINISTRATOR §4.6 |
| memindahkan akun jurnal otomatis yang masih bersaldo | *"Akun {kode} masih memiliki saldo {Rp}; memindahkannya akan meninggalkan saldo itu tanpa dokumen yang dapat menutupnya. Nolkan akun tersebut lewat jurnal terlebih dahulu."* | ADMINISTRATOR §4.6 |
| memasang `{PROJ}` pada jenis dokumen tanpa proyek | *"Mask penomoran {jenis} memakai token {PROJ}, tetapi tidak ada konteks proyek untuk dokumen ini — … Nomor tidak diterbitkan."* | ADMINISTRATOR §4.8 |
| membuka periode di bawah periode tertutup lain | *"Buka periode terbaru lebih dulu."* | ADMINISTRATOR §6.7 |
| membuka bulan yang sudah diukur PSAK 115 | *"Run yang sudah diposting tidak dapat dihitung ulang, jadi periode ini tidak dapat dibuka lagi — koreksi yang ditemukan hari ini dibukukan hari ini."* | ADMINISTRATOR §6.1 |
| mengimpor berkas yang kolom wajibnya hilang | *"Kolom wajib tidak ditemukan di berkas: <kolom>."* | PANDUAN §2.9 |
| menekan `Setujui` kedua pada award berjenjang | *"{Dokumen} {kode} sudah Anda setujui pada tingkat sebelumnya; persetujuan berjenjang menuntut penyetuju yang BERBEDA di tiap tingkat. …"* | PANDUAN §5.12 |
| menjalankan `erp:harden-demo-logins` tanpa terminal | kedua entri sandi kosong dan ditolak pagar panjang minimal — butuh TTY | ADMINISTRATOR §5.9 |

**Yang tidak ada, dan akan diminta orang kepada Anda** (ADMINISTRATOR §3.10,
PANDUAN §14.3): layar ganti sandi sendiri, "Profil Saya", izin per orang, daftar sesi
aktif, "keluar dari semua perangkat", 2FA, layar Log Audit (endpointnya ada:
`GET api/core/audit-log`), perintah artisan untuk membuat pengguna atau memberi peran.
**Perubahan peran seseorang dan perubahan izin sebuah peran tidak tercatat di jejak
audit** (ADMINISTRATOR §3.9) — catat sendiri.

## 5. Formulir yang Anda cetak

Izin Anda menggambar **seluruh 61** tombol cetak (PANDUAN §13.3), tetapi mencetak bukan
pekerjaan Anda; **merawat supaya cetakan orang lain benar** adalah pekerjaan Anda
(ADMINISTRATOR §9.4):

- **Kop** dibaca dari **Profil Perusahaan** — `legal_name`, alamat, NPWP, kota sebagai
  "tempat" sebelum tanggal (ADMINISTRATOR §4.2).
- **Katalog cetak disaring izin di server** dan **di-cache seumur sesi peramban** —
  tombol yang tidak ada berarti izin atau cache, hampir tidak pernah kerusakan
  (ADMINISTRATOR §9.2).
- Dialog cetak peramban harus menyalakan **"Grafik latar belakang"**; delapan belas
  formulir mendatar (ADMINISTRATOR §9.3).
- **Aturan kejujuran** sebagai instruksi operasional (ADMINISTRATOR §9.5; PANDUAN §13.5):
  sel bergaris kosong berarti *tidak tercatat*, bukan nol. Kosong per-dokumen = kolomnya
  kosong di basis data; kosong universal = ERP tidak punya kolom itu. Jangan "memperbaiki"
  lembar dengan mengisi nol di basis data.

Empat dokumen mengunduh PDF sungguhan alih-alih formulir rumah — invoice termin, PO, BAST,
slip gaji (PANDUAN §13.4).

## 6. Daftar periksa minggu pertama

- [ ] Ada **dua** akun aktif berperan `admin`, dan saya sudah masuk dengan salah satunya.
- [ ] Saya sudah membuat satu pengguna, memberinya satu peran, dan menyuruhnya muat ulang.
- [ ] Saya sudah menonaktifkan satu akun uji dan membaca konfirmasinya.
- [ ] **Profil Perusahaan** berisi NPWP sungguhan, bukan `01.234.567.8-012.000`.
- [ ] Saya sudah membuka **Pengaturan › Proyek & Persetujuan** dan tahu dua ambang
      direktur serta sakelar pemisahan tugas.
- [ ] Saya sudah membuka **Peran & Hak Akses** dan membandingkan kedua belas peran dengan
      ADMINISTRATOR §3.2 — termasuk `eng.*` dan `qc.*`.
- [ ] Gudang dan Rekening Bank sudah ada sebelum dokumen stok atau pembayaran pertama.
- [ ] Saya sudah membaca alarm pagi di lonceng dan tahu yang mana "belum dikonfigurasi".
- [ ] `/etc/erp1/backup.conf` menunjuk tujuan di luar mesin, `--restore-drill` berbunyi
      **RESTORE DRILL PASSED**, dan kunci enkripsinya tersimpan di luar server.
- [ ] Saya sudah menjalankan `erp:deadline-watch` dengan tangan dan membaca baris BLIND.
- [ ] Saya tahu bentuk baku perintah server dan tidak pernah menjalankan artisan sebagai
      root di pohon produksi.
- [ ] Saya sudah membaca PANDUAN §14.5 dan tahu empat hal yang saya minta dari pelapor.

## 7. Bila tersangkut

| Situasi | Tanyakan ke |
|---|---|
| Server, nginx, TLS, skrip deploy, gerbang demo erp1 | `docs/DEPLOYMENT.md` §3, §4, §5, §7.1 — berbahasa Inggris |
| Fakta yang hanya bisa dipastikan dari kode | nama berkasnya disebut di ADMINISTRATOR; serahkan ke pengembang |
| Keputusan kebijakan: ambang, pemisahan tugas, kata sandi demo, `inv.post` teknisi, log BBM, akrual alat, rumus TKDN | **pemilik / direktur** — ADMINISTRATOR §12(a)–(e) memuat setiap keputusan yang menunggu |
| Isi dokumen, angka, siapa yang menyetujui | **pemegang perannya** — Anda cadangan, bukan pemilik proses (PANDUAN §14.2) |
| Bulan tidak mau ditutup | layar Periode Fiskal menyebut butirnya; yang sudah diajukan minta ditolak manajer keuangan — ADMINISTRATOR §6.3–§6.4 |
| Pemulihan dari cadangan | **belum terdokumentasi** — ADMINISTRATOR §10.5 menyebut dua berkas yang harus dibaca untuk menyusunnya |

Eskalasi, dua baris: bila sebuah kalimat penolakan menyebut izin dan orangnya, itu jawaban
Anda kepada pelapor. Bila ia menyebut `config/erp.php` atau deploy, itu bukan pekerjaan
layar — jadwalkan rilis, jangan menyunting basis data hidup.

---

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung; menggabungkan dan men-deploy-nya keputusan pemilik):
> layar **Tugas Saya** dengan satu kotak masuk untuk semua jenis dokumen; draf formulir
> bertahan di peramban saat sesi habis; catatan persetujuan diketik langsung di halaman
> tanpa dialog; **ganti kata sandi sendiri** — yang akan mengurangi permintaan
> ADMINISTRATOR §3.4 kepada Anda. Sampai rilis itu tayang, panduan ini yang berlaku.
