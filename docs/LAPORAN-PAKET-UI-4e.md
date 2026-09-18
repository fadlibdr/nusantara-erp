# Laporan Paket UI-4e — papan kanban akhirnya punya jalan keyboard

> Paket kelima UI-4. Menutup satu-satunya butir UI-4 yang `.desc` layarnya sendiri akui hilang.

---

## Pengakuan yang ditutup

Sejak 8 Sep 2026 deskripsi papan memuat kalimat ini, dan komentar di atasnya menjelaskan mengapa:

> *"Kalimat ketiga ada untuk orang yang TIDAK BISA MENYERET: papan ini tidak punya jalan keyboard
> untuk memindahkan kartu (diukur 8 Sep 2026 — panah, Spasi dan Tab tidak memindahkan apa pun), dan
> jalan yang memang ada — tombol aksi di halaman dokumennya — harus tertulis di tempat orang
> membacanya."*

Sekarang jalannya ada:

| tombol | akibat |
|---|---|
| ↑ ↓ | fokus naik/turun di dalam kolom |
| ← → | fokus ke kolom sebelah, pada posisi terdekat |
| **Ctrl / ⌘ / Alt + ← →** | **memindahkan kartunya** |
| Enter · Spasi | membuka dokumennya — tidak berubah |

Enter dan Spasi **sengaja tidak** dipakai untuk "angkat kartu" seperti pola kanban yang lazim:
keduanya sudah membuka dokumen, itu perilaku yang didokumentasikan dan diuji, dan menukar maknanya
di satu layar membuat tombol yang sama berarti dua hal berbeda di dua tempat.

---

## Satu jalur keputusan, bukan dua

Yang dibangun di sini hanyalah **bentuk peristiwa** yang SortableJS berikan — `item`, `from`, `to`,
`oldIndex` — lalu diserahkan ke `onDrop` yang sudah ada. Keputusannya tetap miliknya: `refuse()`,
aksi yang dijalankan lewat `runAction`, catatan persetujuan inline, alasan wajib, dan pengembalian
kartu saat gagal.

Menyalin kebijakan itu ke jalur kedua berarti dua salinan yang akan hanyut — dan yang hanyut di sini
adalah **siapa boleh menyetujui apa**. Diverifikasi: perpindahan yang ditolak lewat papan ketik
memberi kalimat yang **sama persis** dengan yang ditolak lewat seretan
(*"Prospek LEAD-0002 tidak bisa dipindahkan ke Menang lewat tahap: …"*).

`BoardWiringTest` kini memaku itu: `moveCardByKeyboard` harus memanggil `onDrop`.

---

## Fokus, dan dua asumsi saya yang salah

Inti paket ini ternyata bukan perpindahannya — itu bekerja pada percobaan pertama — melainkan
**fokus**, dan saya salah menebaknya dua kali.

**Salah 1: "memindahkan elemen di DOM tidak melepas fokusnya."** Saya menulis itu sebagai komentar.
Diukur: di Chromium, memindahkan elemen yang sedang difokus dengan `appendChild`/`insertBefore`
**melepas fokusnya**. Sesudah Ctrl+panah, `document.activeElement` kembali ke `<body>` — pemakai
papan ketik terlempar ke puncak dokumen tepat sesudah melakukan sesuatu yang berhasil.

**Salah 2: menitipkan id kartu di `ctx`.** Perbaikan pertama menyimpan `ctx.focusCardId` sebelum
perpindahan dan memulihkannya saat render. Fokusnya tetap hilang, tanpa satu pun galat — karena
`ctx` **dibuat baru pada setiap render** (`paint(body, { … })` di `renderBoard`), jadi yang dibaca
render berikutnya adalah objek yang belum pernah disetel. Id-nya kini dibawa lewat **argumen
`reload`**.

Kedua jalannya berbeda dan keduanya diuji:

| hasil | siapa memulihkan fokus |
|---|---|
| ditolak / gagal | `putBack()` mengembalikan node yang **sama** → `node.isConnected` masih benar → difokus lagi di tempat |
| berhasil | `ctx.reload()` menggantinya dengan elemen **baru** → `renderLanes` memulihkannya lewat id |

Terukur sesudah perbaikan: perpindahan berhasil membawa fokus ke kolom tujuan
(`{id: 2, kolom: 'proposal'}`), dan perpindahan yang ditolak meninggalkan fokus di kartunya.

---

## Jalannya tertulis, bukan rahasia

Deskripsi papan diperbarui — sebuah pintasan yang hanya diketahui penemunya bukan jalan keyboard,
ia rahasia. `BoardWiringTest` menuntut deskripsinya menyebut panah **dan** pintasan pemindahnya.

---

## Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.420 / 11.626**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S8 S14 S18 S21 S43 S44 S47` + **S48** | hijau, keluar 0 — `results-ui-4e.json` |
| `SHELL_VERSION` | 25 → **26** |

**S48 baru** (83 skenario) memaku delapan syarat — termasuk bahwa panah **polos** hanya memindahkan
fokus dan tidak pernah kartunya — dan dibuktikan **merah** dengan mencabut pemulihan fokusnya:

```
GAGAL: fokus hilang sesudah perpindahan dengan papan ketik,
       fokus hilang sesudah perpindahan kedua (jalur ditolak/putBack)
```

**Dua asersi `BoardWiringTest` diperbarui, bukan dilonggarkan.** Keduanya memaku EJAAN lama
(`event.key !== ' '` dan kalimat `"Tanpa menyeret:"`), bukan perilakunya. Penggantinya menuntut
lebih: jalan keyboardnya harus ada, harus lewat `onDrop`, harus memulihkan fokus, dan harus
tertulis di layar.

---

## Yang sengaja tidak dikerjakan

* **Memindahkan kartu naik/turun di dalam kolom.** Urutan di dalam kolom tidak disimpan di mana pun
  — papan menggambar apa yang server pulangkan — jadi "memindahkan" ke atas tidak berarti apa-apa
  sesudah muat ulang berikutnya.
* **Pola "angkat lalu jatuhkan" (Spasi untuk mengangkat).** Butuh menukar makna Spasi, yang sudah
  membuka dokumen di sini dan di setiap layar lain.
* **Pengumuman `aria-live` saat kartu pindah.** Toastnya sudah mengatakan hasilnya dan sudah dibaca
  pembaca layar; menambah pengumuman kedua berarti hal yang sama dibacakan dua kali.
