# Laporan Paket P1-G (ROADMAP-HASHMICRO Fase 1) — Kanban atas resource ber-enum status

Branch: `feat/phase1-g` (dari `feat/phase1-f`) · 6 September 2026

> Status jujur: **dibangun, diuji, dan diukur di peramban dua kali** (S25 dijalankan berulang
> untuk membuktikan ia idempoten). **Verifikasi adversarial belum dijalankan.** Tidak ada
> migrasi, tidak ada endpoint baru, tidak ada dependensi baru.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-G → status)

| Klausa kontrak (baris 193) | Status | Bukti |
|---|---|---|
| Blok `board:` di schema.js (kolom + `moves` → kunci aksi yang SUDAH ADA) | ✅ | dua papan: `procurement/purchase-requisitions`, `quality/ncr` |
| `views/board.js` memakai `runAction()` | ✅ | `BoardWiringTest` menolak `api.post/put/del` di board.js |
| Drop terlarang = kartu kembali + kalimatnya | ✅ | terukur S25: *"PR PR/2026/III/0002 tidak bisa dipindah ke Disetujui: aksi Setujui tidak tersedia untuk Anda."* |
| 0 endpoint baru | ✅ | tidak ada rute server yang ditambah; `git diff` Modules/ kosong |
| Aksi `post` (jurnal/stok) tidak pernah di papan | ✅ | dipaku per RESOURCE, bukan per kunci — lihat § Larangan |
| Harness S25 "papan PR" | ✅ | 12 syarat hijau, dua jalan berturut-turut |

## Yang benar-benar dibangun

Papan adalah **tampilan kedua atas daftar yang sudah ada**, di `#/b/<resource>`, dengan gerbang
izin yang persis sama (`def.viewPerm || {module}.view`). Menambah papan = satu blok `board:` di
entri RESOURCES + satu baris NAV. Tidak ada tabel, tidak ada endpoint, tidak ada aturan transisi
kedua.

```js
board: {
  enum: 'documentStatus',
  lanes: ['draft', 'submitted', 'approved', 'rejected'],
  moves: { submitted: 'submit', approved: 'approve', rejected: 'reject' },
}
```

**Dua papan, dengan sengaja.** PR adalah contoh ROADMAP sendiri (dan S25 menamainya); NCR ada untuk
membuktikan kontrak `board:` bekerja di luar `documentStatus` — empat nilai `ncrStatus` seluruhnya
tercapai dan ketiga aksinya memetakan tepat ke ketiga transisi majunya, jadi tidak ada kolom mati.

## Kenapa `runAction()` adalah seluruh paket ini

ROADMAP menuntut drop lewat `runAction()` "jalur tombol yang sama — catatan inline, maker-checker,
confirm-resubmit". Survei menemukan bahwa itu bukan sekadar rapi, melainkan **satu-satunya cara**:
enam perilaku ikut secara gratis, dan sebuah papan yang menulis statusnya sendiri kehilangan
keenamnya sekaligus, diam-diam.

Terukur pada jalan yang berhasil (S25, akun direktur): panel catatan persetujuan yang **sama**
dengan bilah aksi, toast `PR/2026/III/0002 disetujui.` dari peta kata kerja bersama, **dan**
`Berikutnya menunggu Anda (3)` — tawaran dokumen berikutnya yang hidup di dalam `runAction` dan
tidak akan pernah muncul di papan yang memanggil API sendiri.

**Tiga jebakan `runAction` yang harus ditangani pemanggilnya**, dan ketiganya ada di kode ini:

1. **Ia tidak memeriksa `perm` maupun `when`.** Predikat itu hidup di `actionButtons()`. Papan
   mengambilnya UTUH, dalam urutan yang sama — memakai predikat kedua saja akan menawarkan
   perpindahan yang tombolnya sendiri sembunyikan.
2. **Panel catatan inline dibangun `actionButtons()`, bukan `runAction()`.** Memanggil `runAction`
   tanpa opsi `inline` menghilangkan catatan persetujuan **diam-diam**. `inlineNote()` karena itu
   **diekspor** dari actions.js dan dipakai apa adanya — bukan disalin — supaya papan dan bilah
   aksi tidak pernah bisa berselisih tentang apa yang tersimpan di riwayat persetujuan.
3. **Ia selalu resolve `undefined` dan tidak pernah melempar.** Batal, 422 dan berhasil tidak bisa
   dibedakan dari nilai kembaliannya; yang menandakan berhasil hanyalah `onDone` yang menyala. Dan
   `onDone` **dilewati** untuk aksi ber-`navigateTo` — jadi aksi seperti itu dilarang menjadi
   `moves`, dipaku uji.

## Dua penolakan yang berbeda, dan keduanya ada

| | kapan diketahui | contoh | yang terjadi |
|---|---|---|---|
| **Izin / `when`** | sebelum permintaan apa pun | pengadaan tidak memegang `prc.approve` | kartu kembali + kalimat yang menyebut dokumen, kolom tujuan, dan aksi yang kurang |
| **Aturan server** | hanya dengan mencoba | maker-checker, tangga persetujuan, ambang direktur, prasyarat BAST | dicoba, `runAction` menampilkan kalimat servernya, kartu kembali |

Yang kedua **tidak boleh ditebak klien**: `approvals` di-load hanya pada detail, dan tidak ada medan
`can_approve` di muatan daftar mana pun. Papan yang mencoba mengabu-abukan kolom untuk maker-checker
akan salah pada dokumen yang justru paling penting.

## Mengembalikan kartu adalah pekerjaan tangan

**SortableJS tidak punya API batal.** `onEnd` menyala SETELAH DOM dipindahkan, dan tidak satu pun
metode instansnya mengembalikan kartu. Satu-satunya jalan adalah idiom pustakanya sendiri: simpan
tetangga di kolom asal **sebelum apa pun yang bisa gagal**, lalu `insertBefore`/`appendChild`.
Karena itu pula:

- **`sort: false`** — papan ini tentang KOLOM; membiarkan pengurutan di dalam kolom menambah satu
  bentuk pembatalan lagi yang indeksnya bergeser (`from.children[oldIndex]` benar untuk drop antar
  kolom, salah untuk penataan ulang di dalam satu kolom).
- **`if (from === to) return;`** — tanpanya papan mengirim satu persetujuan setiap kali orang
  mengangkat kartu lalu meletakkannya lagi.
- **Kartu tidak pernah menjadi `trigger`** — `withBusy()` mengosongkan `innerHTML` node yang
  diberikan padanya, dan kartu yang dikosongkan tidak kembali.
- **`min-height` pada kolom** — SortableJS hanya bisa menerima kartu ke kolom yang punya tinggi.

## Larangan: `post` adalah tentang AKIBAT, bukan tentang kunci

Survei menemukan **lima resource yang menyembunyikan posting ke buku besar atau ke stok di balik
kunci bernama `approve`/`acknowledge`**: `inventory/stock-adjustments`, `finance/ar-invoices`,
`finance/ap-bills`, `hr/payroll-runs`, `servicedesk/field-reports`. Sebuah larangan yang memeriksa
`key !== 'post'` akan meloloskan kelimanya. `BoardWiringTest` karena itu melarang **resource**-nya,
disebut namanya, dan juga menolak kunci `post` untuk resource mana pun.

## Uji

- baru: `BoardWiringTest` (7 uji / 69 asersi) — setiap kolom adalah nilai enum sungguhan; setiap
  `moves` menunjuk kolom papan itu DAN kunci aksi yang ada (janji "0 endpoint baru", sebagai uji);
  tidak ada `moves` ke aksi ber-`opens` (tanpa `path`) atau ber-`navigateTo`; tidak ada papan atas
  resource yang memposting; board.js memakai `runAction` dan tidak punya jalur tulis kedua; dan
  kartu yang ditolak benar-benar dikembalikan (keempat baris idiomnya dipaku).
- diperbarui: `NavRouteRegistryTest` kini mengenal keluarga wildcard **kedua** (`b/<resource>`),
  yang butuh DUA hal — entri RESOURCES **dan** blok `board:` di dalamnya.
- `tests/Feature/Core` + `tests/Feature/Iam`: **865 hijau / 6.701 asersi** (11 dilewati, 161 s).
  Suite penuh dan MySQL: (diisi).

**Harness S25** (Chromium 1440×1000): 12 syarat hijau, **dua jalan berturut-turut**. Ia memasang
prasyaratnya sendiri di awal dan memulihkan keadaannya di akhir lewat sqlite (pola
`decide_onboarding`), karena tidak ada — dan tidak boleh ada — endpoint yang membatalkan
persetujuan; sebuah skenario yang bergantung pada keadaan yang ditinggalkan jalan sebelumnya hijau
sekali lalu merah selamanya.

## Deviasi

1. **Dua papan, bukan satu.** ROADMAP tidak menyebut jumlahnya; papan kedua (NCR) ada untuk
   membuktikan kontrak `board:` bukan cetakan satu resource. Biayanya satu blok tujuh baris.
2. **`inlineNote()` diekspor dari actions.js.** Ia sebelumnya privat modul. Alternatifnya menyalin
   panelnya ke board.js, yaitu dua tempat yang bisa berselisih tentang apa yang tersimpan di
   riwayat persetujuan.
3. **Catatan inline ditawarkan dalam dialog, bukan panel yang menetap.** Pada bilah aksi panel itu
   sudah ada di layar sebelum tombol ditekan; sebuah gerakan seret tidak punya tempat seperti itu.
   Panel yang SAMA dibuka dalam satu dialog sebelum aksinya berjalan.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Tidak ada verifikasi adversarial.** Putaran pertama P1-F menemukan 20 cacat sungguhan; paket
   ini belum melewati satu putaran pun.
2. **Suite penuh dan MySQL belum dijalankan** untuk paket ini; Core+Iam hijau.
3. **Ponsel belum diukur.** CSS papan punya titik potong 760 px (kolom 78vw, satu baris yang
   menggulir mendatar), tetapi S25 hanya berjalan di 1440×1000. Seret-lepas sentuh — yang
   SortableJS dukung dan yang menjadi alasan pustaka ini di-vendor — **belum pernah dicoba**.
4. **Data demo tipis**: dua PR (satu `submitted`, satu `approved`) dan satu NCR (`closed`). Kolom
   *Draf*, *Ditolak*, dan tiga dari empat kolom NCR **belum pernah berisi kartu**; jalur "drop ke
   Ditolak membuka dialog alasan wajib" belum pernah dijalankan.
5. **`confirmResubmit` belum pernah dipicu dari papan.** Ia hidup di dalam `runAction` dan diuji
   di sana, tetapi rantai tiga tahap PO (kualifikasi → deviasi harga → over-budget) hanya ada pada
   `procurement/purchase-orders`, yang **tidak** berpapan — PO berpindah kolom sendiri ketika GRN
   menerima penuh, dan papan yang kartunya bergerak tanpa ada yang menyentuhnya butuh cerita
   penyegaran yang belum ditulis.
