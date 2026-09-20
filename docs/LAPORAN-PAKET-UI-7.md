# Laporan Paket UI-7 — sapuan regresi: instrumen yang harus diperbaiki DUA BELAS kali sebelum ia jujur

> Fase terakhir. Responsif · cetak · PWA · papan ketik dan kontras · gerbang yang tidak menggerbangi ·
> dan empat keputusan yang UI-6 ukur lalu serahkan ke sini.

---

## 1. Sapuan: 325 rute × 6 putaran, dalam DUA himpunan

Katalog sapuan sampai tinjauan pra-gabung memuat **143 rute**: 48 berjalur-literal + 95 layar
daftar. Tinjauan itu menunjuk lubangnya, dan lubangnya besar — **tidak satu pun rute `#/d/`,
`#/f/`, `#/b/` atau `#/m/` pernah dibuka oleh gerbang mana pun di seluruh kampanye ini.** Layar
dokumen justru yang UI-4 jadikan halaman penuh dan yang empat paket UI-6 habiskan waktunya.

Katalog kedua karena itu dibangun, dan ia tidak dikarang: id dokumennya diambil dari API sumber
dayanya sendiri, dan enam sumber daya ber-`noDetail: true` DILEWATI — aplikasi memang tidak pernah
menautkannya, dan memasukkannya berarti mengarang rute lalu melaporkan jawaban jujur server
("The GET method is not supported…") sebagai cacat aplikasi. Versi pertama katalog ini melakukan
tepat itu untuk lima rute.

| himpunan | rute | isinya |
|---|---|---|
| **permukaan** | 143 | 48 berjalur-literal + 95 layar daftar |
| **dalam** | 177 | 14 beranda modul + **69 dokumen** + 91 formulir halaman + 3 papan |

Yang diukur per halaman: tabrakan teks (instrumen v3 UI-6b, kotak baris dipotong tiap leluhur yang
menggulung), kontras WCAG terhadap latar EFEKTIF, elemen yang meluber keluar induknya, gulir
menyamping, dan sasaran sentuh.

**Permukaan — 143 rute, keenam putaran:**

| putaran | rute | belum siap | tabrakan | kontras < AA | meluber | gulir-X | elemen terukur |
|---|---|---|---|---|---|---|---|
| 1440 terang | 143 | 0 | 0 | 0 | 0 | 0 | 20.322 |
| 1440 gelap | 143 | 0 | 0 | 0 | 0 | 0 | 20.322 |
| 1024 terang | 143 | 0 | 0 | 0 | 0 | 0 | 20.322 |
| 768 terang | 143 | 0 | 0 | 0 | 0 | 0 | 20.322 |
| 390 ponsel + sentuh | 143 | 0 | 0 | 0 | 0 | 0 | 20.322 |
| **cetak 1440** | 143 | 0 | 0 | 0 | 0 | **0** | 20.322 |

**Dalam — 177 rute, keenam putaran, SESUDAH §3 ditutup:**

| putaran | rute | belum siap | tabrakan | kontras < AA | meluber | gulir-X | elemen terukur |
|---|---|---|---|---|---|---|---|
| 1440 terang | 177 | 0 | 0 (dari **16**) | 0 | 0 | 0 | 18.229 |
| 1440 gelap | 177 | 0 | 0 (dari **16**) | 0 | 0 | 0 | 18.229 |
| 1024 terang | 177 | 0 | 0 (dari **9**) | 0 | 0 | 0 | 18.229 |
| 768 terang | 177 | 0 | 0 (dari **1**) | 0 | 0 | 0 | 18.229 |
| 390 ponsel + sentuh | 177 | 0 | 0 | 0 | 0 | 0 | 18.229 |
| **cetak 1440** | 177 | 0 | 0 | 0 | 0 | 0 (dari **8**) | 18.229 |

**Angka-angka ini direproduksi dua kali berturut-turut, byte-identik di setiap dimensi** — dan itu
bukan basa-basi, sebab ringkasan yang berdiri di sini sebelumnya TIDAK bisa. Ia melaporkan enam
putaran dengan hanya **tiga** nilai `elemen_terukur` berbeda, dua pasang di antaranya sama persis
pada lebar dan media yang berbeda. Sebabnya bukan pengukuran melainkan penggabungnya: ia membaca
`sweep-<putaran>-<pecahan>.json` tanpa pernah menghapusnya lebih dulu, jadi putaran yang pecahannya
gagal menulis diam-diam menggabungkan berkas **putaran sebelumnya** — dan hasilnya terbaca lengkap.
Bentuk yang persis sama dengan jebakan `results.json` yang MENGGABUNG, yang sudah tercatat sebagai
pelajaran sejak UI-5. Saya menulisnya lagi, satu lapisan di atas.

Sensus elemen per rute kini dipakai sebagai penjaga: **0 dari 143 dan 0 dari 177 rute berbeda
jumlah elemennya antar putaran layar** — DOM tidak berubah karena lebar atau tema, jadi selisih
apa pun di sana adalah cacat instrumen, bukan temuan. Satu-satunya rute yang censusnya bergerak
antar-JALANNYA adalah `#/home` (190 → 218), dan sebabnya jujur: ia menggambar ubin "Terakhir
dibuka", jadi riwayat penjelajahan instrumen ini sendiri adalah datanya.

Dua rute permukaan membawa `.alert.error` di keenam putaran, dan keduanya BENAR: `#/dashboard`
("Saldo GL Bank BCA Operasional negatif") dan `#/bank-recon` ("Belum ada rekening koran … yang
diimpor"). Aplikasi ini memang berkata "gagal" alih-alih menggambar nol — itu kosakata kegagalan
jujur yang audit awal catat sebagai hal yang TIDAK boleh dirapikan.

## 2. Instrumennya sendiri salah DUA BELAS kali — sembilan di antaranya "bersih"

Angka di §1 baru bisa dipercaya sesudah dua belas koreksi. Sembilan di antaranya **melaporkan nol
temuan dengan percaya diri**, dua melaporkan temuan yang tidak ada, dan hanya satu gagal dengan
keras. Itu perbandingan yang patut dicatat sendiri: sebuah instrumen pengukur cacat hampir selalu
rusak ke arah HIJAU, karena setiap cara sebuah halaman bisa gagal tergambar juga menghapus
segala hal yang bisa ditemukan padanya.

| # | kekeliruan | bagaimana ia terlihat | penjaganya sekarang |
|---|---|---|---|
| 1 | thread lintas greenlet | **mati keras** `Cannot switch to a different thread` | pecahan PROSES |
| 2 | jeda tetap 1.400 ms | "48 layar rusak di tema gelap" — halaman KOSONG | penjaga siap-gambar + `belum_siap` dilaporkan |
| 3 | masuk sekali per pecahan → 429 | 143 rute "kosong", nol temuan | satu berkas keadaan sesi + penjaga sesi |
| 4 | penjaga "`.main` berisi" | DOM rute SEBELUMNYA masih terpasang | tandai `.main`, tunggu penanda HILANG |
| 5 | penggabung tanpa `rm -f` | enam putaran, **tiga** nilai sensus | hapus pecahan tiap putaran + sensus per rute dibandingkan |
| 6 | "jumlah elemen berhenti berubah" | **kerangka** diam sempurna saat fetch-nya jalan | penghitung permintaan DALAM PENERBANGAN + tolak `.skeleton` |
| 7 | cangkang tidak pernah boot | `.main` tidak ada → n=0 → nol temuan | `ada_main` di probe, putaran keluar **FATAL** |
| 8 | layar GALAT | 16 elemen, nol temuan di setiap dimensi | `galat_layar` dilaporkan, rute diulang |
| 9 | ulangan atas pesan JUJUR | pengukuran BENAR dibuang, diganti yang rusak | ulang hanya untuk kegagalan instrumen; simpan yang TERBAIK |
| 10 | `goto` ke hash yang SAMA | tidak menggambar ulang apa pun → penanda basi | ulangan memaksa muat penuh lewat `about:blank` |
| 11 | batas laju API 120/menit | 11 rute dokumen "bersih" berisi pesan throttle | 429 dan 408 ikut dihitung gagal |
| 12 | `content-visibility: hidden` | dua tabrakan PALSU di piksel, bukan di layar | `checkVisibility()` |

Empat yang terakhir ditemukan hanya karena katalog rute DALAM dibuka (§1), dan masing-masing
pantas dijelaskan:

* **#9 dan #10 bersama-sama MERUSAK data yang sudah benar.** `#/dashboard` terukur sempurna pada
  percobaan pertama — 420 elemen, siap — tetapi ia membawa `.alert.error` berbunyi "Saldo GL Bank
  BCA Operasional negatif (Rp -232.545.000)". Itu ubin dasbor yang berkata GAGAL alih-alih
  menggambar nol, persis kosakata jujur yang audit awal larang dirapikan. Syarat ulangan saya
  membacanya sebagai kegagalan, lalu percobaan kedua `goto` ke hash yang sama — yang tidak
  menggambar ulang apa pun — dan yang tersimpan adalah penanda basi: **satu elemen**. Keenam
  putaran karena itu melaporkan `#/dashboard` "belum siap", dan sensusnya kehilangan 420 elemen.
* **#11 adalah sapuan yang mengalahkan dirinya sendiri.** Layar dokumen menembak beberapa endpoint
  per halaman; 120 permintaan/menit per kredensial terlampaui, dan sebelas rute tercatat 12 elemen
  dan "bersih" — isinya sebuah alert "Batas laju API terlampaui". Penjaga yang hanya menonton 5xx
  tidak melihat 429 sama sekali. Server ujinya kini dijalankan dengan `API_RATE_LIMIT` dinaikkan,
  dan itu **dicatat di berkas buktinya** karena ia satu-satunya hal yang berbeda dari produksi.
* **#12 adalah satu-satunya kekeliruan yang membuat laporan TERLALU buruk.** Isi `<details>` yang
  TERTUTUP memakai `content-visibility: hidden` pada `::details-content`: tidak digambar, tidak
  bisa di-hit-test — tetapi **tetap punya kotak tata letak**, jadi `getClientRects()` mengembalikan
  geometri sungguhan. Dua "tabrakan" terakhir adalah teks bantuan `.action-note` yang jatuh di atas
  judul "Riwayat Persetujuan" di koordinat piksel, dan tidak pernah di layar. `display: none` dan
  `visibility: hidden` tidak melihatnya; `checkVisibility()` melihatnya.

Dan satu kekeliruan ke-tiga-belas yang ada di KATALOG, bukan di instrumen: versi pertama katalog
dalam memasukkan lima rute dokumen untuk sumber daya ber-`noDetail: true`. Aplikasi tidak pernah
menautkannya dan API-nya memang tidak punya rute `show`, jadi jawabannya — "The GET method is not
supported for route api/assets/equipment-logs/2" — adalah server yang BENAR menjawab permintaan
yang saya karang. Sempat saya catat sebagai cacat aplikasi sebelum diperiksa.

Instrumennya sendiri ikut disimpan — `docs/bukti-uji/sapuan-regresi-ui7.py` dengan katalog rutenya.
Angka yang tidak bisa dijalankan ulang orang lain adalah angka yang harus dipercaya begitu saja.

## 3. `#/asset-utilization` — layar yang tidak pernah dibuka kampanye ini

Satu-satunya rute yang menggulung menyamping di kertas. Mengejarnya menemukan layar yang tidak
pernah muncul di daftar sasaran paket mana pun — ia punya **nol gaya sebaris**, jadi tidak ada
sapuan yang pernah menyebutnya.

Ia merefleksikan `Object.keys(rows[0])` dan menebak kolom uang dengan `/(value|cost|amount|rate)/`.
Tebakan itu meleset, dan tiga cacat lain menumpang di belakangnya:

| | sebelum | sesudah |
|---|---|---|
| kolom uang yang lolos tebakan | `505000000` | **Rp 505.000.000** |
| kepala kolom dari nama kunci | `INTERNAL CHARGE SUGGESTION` | **USULAN BEBAN INTERNAL** |
| enum mentah | `active` | **Aktif** |
| tanggal ISO mentah | `2026-03-02` | **02 Mar 2026** |

505.000.000 = 202 hari × Rp 2.500.000. Ia uang, tergambar sebagai bilangan bulat telanjang **di
sebelah** saudaranya `daily_rate_internal` yang tampil benar — di layar berbahasa Indonesia dengan
empat belas kepala kolom berbahasa Inggris.

Perbaikannya **aditif**: kunci yang dikenal mendapat label dan jenis, kunci yang tidak dikenal jatuh
ke perilaku lama. Sifat yang membuat refleksi ini berharga — kolom baru dari server tetap muncul —
tidak dibuang.

## 3a. Layar DOKUMEN memuntahkan JSON mentah — 16 tabrakan dan 8 luberan kertas, satu sebab

Katalog rute dalam (§1) membuka 69 layar dokumen untuk pertama kalinya, dan temuan pertamanya
bukan gaya. `detail.js` menggambar larik OBJEK sebagai satu lencana per entri dengan
`JSON.stringify(entry)` sebagai labelnya:

```js
value.map((entry) => badge(typeof entry === 'object' ? JSON.stringify(entry) : String(entry), 'primary'))
```

Terukur di viewport 1.440 px:

| rute | medan | lencana terlebar |
|---|---|---|
| `#/d/procurement/work-orders/1` | Billings | **3.396 px** |
| `#/d/crm/tender-packages/1` | Documents | 1.572 px |
| `#/d/quality/inspections/1` | Results | 1.141 px |
| `#/d/assets/deployments/4` | Equipment Logs | 1.320 px |

Satu sebab, dua gejala yang tidak terlihat berhubungan sampai keduanya diukur: **16 tabrakan teks**
di layar (9 pada lebar 1.440, 9 pada 1.024, 1 pada 768, nol pada 390 — makin lebar layarnya makin
jauh luberannya menjangkau tetangganya) dan **8 rute yang menggulung menyamping DI KERTAS**, lembar
1.440 px yang isinya sampai 3.905 px. §4 menutup luberan kertas untuk kepala tabel; ini sumber yang
sama sekali lain, dan ia tidak akan pernah muncul di sapuan permukaan.

Nama medannya sendiri ikut bercerita: "Billings", "Results", "Equipment Logs", "Documents" — label
auto-Inggris yang `titleize()` hasilkan untuk kunci tanpa entri di kamus `LABELS`. Dan `titleize()`
**sudah menulis `console.warn` untuk setiap kunci semacam itu sejak lama**; tidak ada yang pernah
membacanya, karena tidak ada gerbang yang pernah membuka layar ini.

Perbaikannya sejalan dengan §3 dan dengan aturan yang layar ini pegang sendiri — tetap REFLEKTIF,
tidak menukar refleksi dengan kamus per sumber daya yang akan diam-diam menyembunyikan bentuk baru:
lencana menampilkan **properti string pertama yang bukan kunci id**, dengan JSON utuhnya pindah ke
`title` — pola "potong, simpan di `title`" yang sel tabel pakai sejak UI-2. Tidak ada yang hilang.
Jarak dan **lebar maksimum** pindah ke `.auto-badges` di `app.css`; yang kedua itu penjaga
struktural, bukan kosmetik: bentuk apa pun yang server kirim besok tidak akan bisa lagi melebar
3.396 px dan menimpa baris di bawahnya.

Sesudahnya, keenam putaran atas 177 rute dalam: **tabrakan 16 → 0, gulir-X kertas 8 → 0.**

## 4. Kepala tabel boleh membungkus di kertas

Tabelnya 1.474 px di lembar 1.440 px, dan lebarnya dikuasai **KEPALA**, bukan data: "USULAN BEBAN
INTERNAL" 182 px untuk memuat "Rp 505.000.000" yang butuh ~120 px.

`table.data th { white-space: nowrap }` benar di layar — tabel lebar menggulung di dalam
`.table-wrap` dan kepala ragged terbaca buruk. Di KERTAS blok cetak baru saja mencabut wrapper itu,
jadi kepala yang tidak boleh membungkus MEMAKSA tabelnya melewati tepi lembar. Satu aturan
print-only menutupnya, dan ia menolong setiap tabel lebar, bukan hanya yang ini: **1.474 → 1.438 px**.

## 5. Sasaran sentuh — dan tabel yang akhirnya berjumlah sama dengan bagiannya

Di `pointer: coarse`, `.btn.sm` dinaikkan ke 36 px sejak lama — tetapi `.btn` biasa tetap 34 px.
**Varian "kecil" karena itu LEBIH BESAR daripada varian normal, tepat di perangkat tempat ukuran
sasaran paling penting.** Lantai 36 px diperluas ke `.btn`, `.btn.icon`, medan isian, `<select>`,
`.check-row`, dan `.combo .combo-input` — yang terakhir butuh barisnya sendiri karena
`.combo .combo-input` (0,2,1) menang atas selektor jenis.

Dua cacat di aturan saya sendiri ikut tertutup, keduanya berpola sama — **pagar yang menyebut
anggotanya**:

* **Gaya sebaris mengalahkan media query.** `list.js` menulis tinggi pemilih halaman sebaris,
  dengan alasan tertulis "ini geometri, bukan tipografi". Alasan itu melewatkan bahwa lantai
  sentuh HIDUP di `@media (pointer: coarse)` — dan gaya sebaris menang atas media query mana pun.
  Satu kontrol, 70 layar daftar, lantai yang tidak pernah berlaku. Geometrinya sekarang
  `.pager select` di `app.css`, dengan barisnya sendiri di blok coarse (0,1,1 lawan 0,0,1).
* **Daftar jenis bocor pada jenis berikutnya.** `input[type=text], [type=password], …` tidak
  pernah cocok dengan `time`, `tel`, atau `<input>` yang ditulis tanpa `type` sama sekali — yang
  sah dan berarti `text`. Sekarang `input:not([type=checkbox]):not([type=radio]):not([type=file])
  :not([type=range]):not([type=hidden])`.

### Angka lama DICABUT, bukan dikoreksi

Laporan ini pernah berbunyi **"568 → 4"**, lalu **"545 → 496, 411 → 6"**. Ketiga pasang angka itu
diukur oleh instrumen yang §2 tunjukkan salah dua belas kali, dan tinjauan pra-gabung menemukan
tabelnya **tidak berjumlah sama dengan bagiannya sendiri**. Angka-angka itu dicabut. Yang berdiri
di bawah diukur ulang dari nol, atas kedua himpunan rute, dan **dua kali berturut-turut dengan
hasil byte-identik**.

Dua di antaranya bisa direkonsiliasi dengan angka lama, dan itu memperkuat keduanya:

* **496 → 513** pada permukaan. Selisih 17 adalah medan berimbuhan + satu `<select>` di matriks
  persetujuan `#/settings` — layar yang putaran lama catat 27 elemen padahal isinya 1.541.
* **6 → 60** di bawah 24 px. Selisih 54 adalah kotak centang BADAN `td.pick`, yang aturan cabang
  ini sempat naikkan ke 24 px sampai tinjauan pra-gabung menemukan bahwa aturan itu juga menaikkan
  tinggi barisnya (32 → 39,5 px padat) dan menabrak kepadatan beku pemilik. Mereka kembali ke
  ukuran BAWAAN peramban — pengecualian "user agent default" WCAG 2.5.8 — dan karena itu muncul
  lagi di hitungan.

### 390 px, pointer kasar — 325 rute

| kelas | jumlah | tinggi | keadaan |
|---|---|---|---|
| `.th-sort` tombol urut kepala kolom | 296 | 24 | dinaikkan ke ambang WCAG |
| kotak centang `.check-row` | 128 | 24 | dinaikkan |
| kotak centang `th.pick` (kepala) | 27 | 24 | dinaikkan |
| `button.star` favorit di beranda modul | 143 | 27 | di atas 24, di bawah 36 |
| medan isian & `<select>` di dalam `table.lines` / `table.data td` | 179 | 31–34 | sengaja: tingginya MENGUKUR tinggi baris |
| `.fs-secondary`, `.chip-x` | 4 | 26–28 | di atas 24 |
| **kotak centang BADAN** — `td.pick` 54 · baris formulir 94 · lepas 20 | **168** | **16** | ukuran bawaan peramban — pengecualian 2.5.8 |
| entri agenda `#/kalender` | 3 | 20 | kepadatan kalender = keputusan tersendiri |
| `input[type=file]` | 2 | 22 | kontrol NATIF |
| tautan sebaris di dalam kalimat | 2 | 14–15 | dikecualikan eksplisit oleh 2.5.8 |

**952 di bawah 36 px · 175 di bawah 24 px**, dan 296 + 128 + 27 + 143 + 179 + 4 + 168 + 3 + 2 + 2
berjumlah tepat 952 — itu satu-satunya klaim yang tabel sebelumnya tidak bisa buat.

Ke-175 yang di bawah ambang WCAG 2.5.8 terbagi jujur: **168 kotak centang berukuran bawaan
peramban** (aplikasi ini tidak menyetelnya di badan tabel — pengecualian "user agent default",
dan sasaran yang dibuka orang adalah BARISNYA), **2 tautan sebaris** (dikecualikan eksplisit),
**2 `input[type=file]`** (menyetel tingginya memotong tombol bawaan peramban), dan **3 entri
agenda `#/kalender` pada 20 px** — yang terakhir itu satu-satunya yang aplikasi ini gambar
sendiri dan tidak ada pengecualian menaunginya. Menaikkannya mengubah kisi bulan pada 390 px,
jadi ia keputusan kepadatan kalender, dicatat di sini dengan angkanya alih-alih diselesaikan
diam-diam di paket terakhir.

Untuk kontrol DI DALAM tabel lantainya **24 px, bukan 36**, dan angkanya diukur: pada 36 px tinggi
baris kepala 42 → 62 px, pada 24 px ia 42 → **50 px**, sementara baris ISI tidak bergeser
sepersepuluh piksel pun pada keduanya. Yang dibayar hanya kepala tabel, di layar sentuh saja;
kepadatan yang pemilik bekukan tidak tersentuh — diverifikasi byte-identik terhadap `main` pada
ketiga kepadatan (padat 32 · normal 38,5 · lega 48, baris berkotak-centang 32/43,5/48).

## 6. Gerbang yang tidak menggerbangi: 7 → 4

Lihat butir 5 rencana kampanye untuk daftar lengkapnya. Ringkasnya: **S8**, **S20** dan **S20m** kini
mengembalikan `ok`, masing-masing dibuktikan merah lewat sabotase; empat sisanya sengaja dibiarkan
mencatat karena tinjauan 28 agen **menolak enam dari tujuh usulan** — hanyut data, gerbang mati, dan
saringan yang menanggung beban. Menulis gerbang lemah untuk keempatnya menukar satu kebohongan
dengan kebohongan lain.

## 7. Empat keputusan yang UI-6 serahkan ke sini

Keempatnya datang dengan angkanya dari paket sebelumnya, dan keempatnya dijawab dengan mengukur
lagi — dua di antaranya **membantah catatan saya sendiri**.

### 7.1 Ukuran `.value` antar-layar — DUA ukuran tetap, dan sensusnya harus diulang

Sensus lama menyapu 143 rute dan menemukan "tujuh baris 22 px lawan enam baris 16 px". Sensus baru
menyapu **325 rute** — kedua katalog §1 — dan angkanya lain sama sekali:

| | lama (143 rute) | baru (325 rute) |
|---|---|---|
| baris ubin tingkat atas | 13 | **39** |
| seragam 22 px | 7 | **8** |
| seragam 16 px | 6 | **31** |
| CAMPUR di dalam satu baris | 0 | **0** |

Tujuh belas dari 31 baris 16 px adalah layar DOKUMEN, dan itu bukan kebetulan: `summaryStrip()`
(`views/detail.js:553`) menggambar bilah ini untuk setiap sumber daya yang menyatakan
`detail.summary` — **empat belas sumber daya**, jadi setiap dokumen mereka membawanya. Sensus lama
tidak bisa melihat satu pun karena tidak ada rute `#/d/` di katalognya; ia toh MENGUTIP
`#/d/procurement/purchase-orders/1` sebagai buktinya. Kutipan itu benar; sumbernya bukan sensus itu.

Yang membelah kedua ukuran tetap sama, dan tetap bukan layar melainkan **bentuk angkanya**: 22 px
membawa bentuk ringkas/hitungan (`Rp 33,68 M`, `55%`, `1/2`), 16 px membawa rupiah PENUH yang
direkonsiliasi orang terhadap kertas.

**Dan pembenarannya berubah.** Laporan ini pernah menulis bahwa objeksi "22 px memaksa bentuk
ringkas" TIDAK berdiri, karena pada 1440 dan 1280 px keenam baris itu muat — "0 dari 21 ubin".
Diukur ulang atas ke-31 baris (122 ubin), dengan cara yang sama:

| lebar | ubin yang pecah jadi dua baris bila dipaksa 22 px |
|---|---|
| 1440 px | **31 dari 122** |
| 1280 px | 16 dari 122 |
| 1024 px | 38 dari 122 |
| 390 px | 47 dari 122 |

Objeksinya **berdiri**: pada lebar meja paling lapang pun seperempat ubinnya membungkus. (1280 px
lebih baik daripada 1440 px karena kisinya memberi lebih sedikit kolom yang lebih lebar —
kontra-intuitif, dan itulah gunanya mengukur alih-alih menalar.)

Alasan kedua tidak berubah dan tetap yang lebih dalam: slot itu kadang berisi **kalimat** — "Belum
ada penilaian" di `#/csat` — karena `views/csat.js` memegang aturan tertulis bahwa nol penilaian
adalah kalimat, bukan angka. Slot ini bukan "sebuah angka" melainkan **jawabannya, dalam bentuk apa
pun jawaban itu datang**; satu ukuran akan memaksakan satu bentuk.

**Keputusan: dua ukuran tetap** — sekarang dengan sensus yang mencakup layar yang paling banyak
memakainya, dan dengan pembenaran yang diukur alih-alih diasumsikan.

### 7.2 dan 7.3 Sapuan tambalan margin dan `.card-body > p` — satu jawaban, dan sebuah koreksi

Rencana menyebut keduanya butir terpisah dan menjawab keduanya dengan "adopsi `.stack` pada badan
kartu". Diukur, **jawaban itu tidak bisa dipakai apa adanya**: 202 pasang saudara di dalam kartu
berjarak NOL hari ini, dan hampir seluruhnya struktur kartu itu sendiri (`.card > .list-body` 95,
`.card > .card-body` 47, `.card > .table-wrap` 24). `.stack` di `.card` akan menyisipkan 12 px di
antara kepala kartu dan tabelnya. Delapan belas lagi berjarak nol DI DALAM `.card-body` dan memang
harus rapat — keterangan di bawah nilainya, label di atas medannya.

Yang benar-benar cacat ada di tempat lain, dan ia lebih besar daripada yang dicatat UI-6b:

| | terukur |
|---|---|
| badan kartu yang berakhir dengan 14 px EKSTRA di bawah anak terakhirnya | **26** (25 paragraf + satu `dl.kv`) |
| …di atas padding kartunya sendiri | 30 px lawan 16 px pada kartu tetangga |
| `p.muted` yang berakhir pada 0 px karena ditambal `style: { margin: 0 }` | 13 |
| `p.muted` yang berakhir pada 14 px karena tidak ditambal | 6 |
| jarak berbeda antar-saudara di dalam kartu | **tujuh**: 4 · 6 · 8 · 10 · 11 · 12 · 14 px |

Tidak seorang pun memutuskan 14 px itu: ia margin bawaan peramban (`1em`), yang tidak ada di skala
mana pun dan ditambahkan pada padding kartu.

**Koreksi terhadap UI-6b.** Catatan itu berkata "42 paragraf terukur, nol di antaranya masih
bermargin". Yang diukurnya hanya paragraf **anak-tunggal** — dan paragraf bersaudara, yang justru
mayoritas, tidak pernah masuk hitungan. Ini kesalahan yang persis sama dengan yang dikoreksi
UI-6d pada sensus ubinnya: mengukur sampel yang nyaman, lalu menulis kesimpulannya untuk populasi.

Perbaikannya tiga baris aturan, bukan migrasi:

```css
.card-body > p, .card-body > dl { margin: 0 0 var(--space-3); }
.card-body > p:last-child, .card-body > dl:last-child { margin-bottom: 0; }
.divided > * + * { margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--border); }
```

`.divided` menutup butir 2: satu ide visual — garis yang memisahkan blok bertumpuk — yang sampai
paket ini ditulis ulang setiap kali dibutuhkan, dengan jarak berbeda setiap kali (`.info-group`
16/12 · `.form-section` 20/18 · `.absensi-side` 10/10 · `periods.js` **11/11 SEBARIS, satu objek
gaya per baris daftar**). Yang dipindahkan hanya yang sebaris dan berulang; `periods.js` kehilangan
empat properti gaya per baris dan sebuah `index` yang hanya ada untuk memutuskan "baris pertama
tidak bergaris".

Dua bilah ringkasan yang menulis ulang `.card-foot` sebaris (`custom.js`, `kaskecil.js`) memakai
kelas yang sudah ada, dan bilah simpan lengket `settings.js` turun dari `11px 14px` ke `12px 16px`.

Kedua bilah itu **tidak bisa dicapai dengan data demo** — tabel `fin_kasbons` kosong dan kedua
pembayaran sudah `posted`, jadi kartu alokasinya tidak pernah digambar. Kesetaraannya karena itu
diukur langsung terhadap `app.css` pada DOM sintetis: dari sembilan properti terhitung, tujuh
IDENTIK dan yang berbeda hanya yang memang dituju — `padding` 11px → 12px, `align-items` `normal`
→ `center`, tinggi 43 → 45 px. Menyebut sebuah perubahan "terverifikasi" karena layarnya tidak
bisa dibuka adalah bentuk lain dari gerbang hampa.

**Yang TIDAK disapu, dan kenapa:** `app.css` sendiri memakai 11 px delapan kali sebagai padding
komponen (`.filters`, `.alert`, `.toast`, `.offline-ribbon`, `.fin-line.total`, `.login-brand`,
`.module-card`, `.palette-field`), sebagian disalin persis dari prototipe. Menggesernya menggeser
tinggi baris yang S21/S53 pakukan — itu keputusan kepadatan tersendiri, bukan sapuan jarak, dan
paket regresi bukan tempatnya. Klaim "11 px tidak ada di mana pun" yang sempat saya tulis di
komentar CSS karena itu dicabut sebelum sempat terbit: ia hanya benar untuk lapisan view.

### 7.4 Sumbu grafik pada deret KONSTAN — satu tick, pada nilainya sendiri

`#/harga-satuan` menggambar dua titik yang keduanya Rp 62.000 di atas sumbu ber-tick
`Rp 61,23 rb · Rp 61,61 rb · Rp 62 rb · Rp 62,39 rb · Rp 62,78 rb`. Pita ±1,3 % itu lahir dari
ruang 25 % yang menjaga garis datar tidak menempel di tepi plot — ruang yang memang perlu — lalu
**dilabeli**, di layar yang justru dibuat untuk membaca pergerakan harga.

Yang dicabut karena itu bukan pitanya melainkan labelnya: `lineChart` menerima `yTicks`, dan deret
datar memakai satu tick pada nilainya sendiri. Terukur sesudahnya: **5 label sumbu → 1**
(`Rp 62 rb`), 6 garis kisi → 2, sementara `marks` dan `<title>` tetap 2/2 — batasan keras yang
baru saja digerbangi S20 tidak tersentuh. Sumbu tidak-dari-nol itu sendiri tetap disengaja dan
tertulis untuk pengguna (`PANDUAN-PENGGUNA` §4.6); yang diperbaiki hanya kasus rentang NOL.

## 8. Yang sengaja tidak dikerjakan

* **Menyatukan ukuran ubin antar-layar** — lihat 7.1: diukur, dan alasannya menolak.
* **Padding komponen 11 px di `app.css`** (delapan situs) — lihat 7.2: keputusan kepadatan.
* **`.absensi-side` (10/10) ke `.divided`** — garisnya juga memisahkan blok PERTAMA dari kepala
  kartunya, jadi konversinya bukan penggantian setara; menukar 10 px dengan 12 px di satu layar
  lapangan tidak membayar risikonya.
* **Empat skenario yang masih hanya MENCATAT** (S7 · S19 · S22 · S22m) — lihat §6: tinjauan 28
  agen menolak enam dari tujuh usulan, dan menulis gerbang lemah menukar satu kebohongan dengan
  kebohongan lain.
* **Laporan Bebas** (`custom.js`, 2.429 baris) — terbuka sejak UI-6a; ia pembangun laporan generik
  dan prototipenya menuntut `.builder` + `.pick-list` yang belum ada. Paket ini hanya menyentuh
  bilah ringkasannya.
* **Kamus `LABELS` untuk kunci API yang belum punya entri** — lihat §10(b). §3a menutup JSON
  mentahnya; nama medannya tetap auto-Inggris di layar dokumen, dan melengkapinya adalah pekerjaan
  per sumber daya lintas 69 layar, bukan ekor sebuah sapuan regresi.
* **Menambahkan katalog rute DALAM ke himpunan gerbang rilis** — sapuan ini sekarang membukanya
  (§1) dan menemukan §3a di sana, tetapi menjadikannya GERBANG menuntut id dokumen yang stabil
  lintas basis data. Gerbang yang merah pada basis data lain akan dihapus orang berikutnya, dan
  itu alasan yang sama yang menolak enam dari tujuh usulan gerbang di §6.

## 9. Verifikasi

| gerbang | hasil |
|---|---|
| Sapuan **permukaan**, 143 rute × 6 putaran | `belum_siap` 0 · tabrakan 0 · kontras 0 · meluber 0 · gulir-X 0 — termasuk di kertas |
| Sapuan **dalam**, 177 rute × 6 putaran | tabrakan **16 → 0** · gulir-X kertas **8 → 0** · kontras 0 · meluber 0 (§3a) |
| Reproduksi sapuan | kedua himpunan dijalankan **dua kali berturut-turut, byte-identik** di setiap dimensi |
| Sensus elemen per rute antar-putaran | **0 dari 143** dan **0 dari 177** rute berbeda — DOM tidak bergerak karena lebar/tema |
| Sasaran sentuh 390 px, 325 rute | **952** di bawah 36 px · **175** di bawah 24 px, dan ke-175 itu terurai habis (§5) |
| A/B geometri, 18 rute × 4 lebar | **40 dari 72 identik piksel-demi-piksel**; 32 sisanya bergeser persis di tempat yang dituju |
| Derau instrumen A/B | dua potret dari server yang SAMA: **0 elemen berbeda** di enam rute |
| Kepadatan beku pemilik di `pointer: coarse` | byte-identik terhadap `main` di ketiga profil — padat 32 · normal 38,5 · lega 48 |
| `.card-foot` yang layarnya tak bisa dibuka data demo | 7 dari 9 properti terhitung IDENTIK; yang berbeda hanya padding 11→12, `align-items`, tinggi 43→45 |
| Harness gerbang rilis, 29 skenario | HASIL-HARNESS |
| `tests/Feature/Core` SQLite | **1.421 lulus**, 11 dilewati, 11.630 asersi, 0 gagal |
| `tests/Unit` SQLite | **630 lulus**, 2.201 asersi, 0 gagal |
| `Core` di **MySQL 8** | **1.449 lulus**, 9 dilewati, 11.788 asersi, 0 gagal |
| Gaya sebaris `views/` | objek **536 → 535** (plafon `SpaInlineStyleBudgetTest` diturunkan) |
| `SHELL_VERSION` | **33 → 34** |

Plafon gaya sebaris menghitung OBJEK, dan paket ini menunjukkan lagi mengapa itu proksi lemah:
`.auto-badges` mencabut tiga properti geometri dari `detail.js` dan plafonnya hanya turun satu.

Bukti sapuan ada di `docs/bukti-uji/results-ui-7-sapuan.json` — kedua himpunan, keenam putaran,
plus rincian per KELAS sasaran sentuh. Data PER RUTE putaran 1440 terang ikut disimpan
(`sapuan-ui7-permukaan-1440-terang.json`, `sapuan-ui7-dalam-1440-terang.json`), jadi setiap angka
ringkas di laporan ini bisa dibongkar ulang tanpa menjalankan apa pun — itu tepat yang tidak bisa
dilakukan terhadap ringkasan sebelumnya. Instrumennya `docs/bukti-uji/sapuan-regresi-ui7.py`,
katalognya `rute-katalog.json` (permukaan) dan `rute-katalog-dalam.json` (dalam).

**Satu hal yang berbeda dari produksi, dan dicatat karena ia satu-satunya:** server uji dijalankan
dengan `API_RATE_LIMIT` dinaikkan. Sapuan atas layar dokumen menembak beberapa endpoint per
halaman dan melampaui batas 120/menit milik aplikasi sendiri (§2 #11). Yang dinaikkan hanya
pembatas laju; tidak ada yang berubah pada apa yang digambar.

## 10. Dua hal yang sapuan ini munculkan dan paket ini TIDAK tutup

**(a) `.th-sort` 17 px di lebar MEJA.** Lantai 24 px untuk kontrol tabel (§5) hanya berlaku di
`pointer: coarse`. Di lebar meja angkanya, atas 325 rute: **636 sasaran di bawah 24 px pada 1440
terang** (identik pada gelap, 1024 dan 768 — geometri ini tidak bergantung lebar) dan **552 di
kertas**. Ia terurai habis menjadi:

| kelas | meja | kertas | naungan WCAG 2.5.8 |
|---|---|---|---|
| kotak centang 16 px | 322 | 240 | **ada** — ukuran bawaan peramban, aplikasi tidak menyetelnya |
| `.th-sort` 17 px | 306 | 304 | **tidak ada** — digambar aplikasi sendiri |
| `.fs-secondary` `#/kalender` 20 px | 3 | 3 | tidak ada |
| `input[type=file]` 22 px | 2 | 2 | kontrol natif |
| `.chip-x` 18 px | 1 | 1 | tidak ada |
| tautan sebaris | 2 | 2 | **ada** — dikecualikan eksplisit |

`.th-sort` adalah celah AA di lebar meja, dan menutupnya berarti menaikkan tinggi baris KEPALA di
95 layar daftar sekaligus — keputusan kepadatan, jenis yang pemilik pegang, bukan sapuan regresi.
Di layar SENTUH ia sudah 24 px. Dicatat dengan angkanya, tidak diam-diam diperbaiki di paket
terakhir. (`.chip-x` 18 px bergabung ke daftar ini; ia tidak pernah disebut sebelumnya.)

**(b) Kunci API tanpa entri `LABELS`.** §3a menutup JSON mentahnya, tetapi nama medannya tetap
auto-Inggris — "Billings", "Results", "Equipment Logs", "Documents". `titleize()` sudah menulis
`console.warn` untuk setiap kunci semacam itu; melengkapi kamusnya adalah pekerjaan per sumber
daya di seluruh 69 layar dokumen, dan itu paket tersendiri, bukan ekor sapuan regresi.
