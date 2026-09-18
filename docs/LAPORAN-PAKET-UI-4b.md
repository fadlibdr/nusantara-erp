# Laporan Paket UI-4b — seleksi dan aksi massal: satu putaran yang tidak berhenti pada kegagalan

> Paket kedua UI-4 (UI sumber daya generik). Chip filter sudah ditutup UI-4a; tampilan
> tersimpan, formulir halaman penuh, kepala dokumen dan jalan keyboard papan kanban menyusul.

---

## Yang ditutup

Kolom centang, bilah seleksi, dan satu putaran **berurutan** yang menjalankan aksi — yang sudah
dideklarasikan `schema.js` — pada tiap baris terpilih. Kegagalan tidak menghentikan sisanya, dan
tiap kegagalan menyebut **nomor dokumennya**.

---

## Ia tidak digambar di mana-mana

Ini keputusan pertama paket ini, dan ia diukur:

| | jumlah |
|---|---|
| sumber daya di `RESOURCES` | **97** |
| yang mendeklarasikan aksi baris | 38 (**78** aksi) |
| yang **mendapat bilah seleksi** | **21** (**26** aksi) |
| yang tidak mendapat apa pun | **76** |

Di 76 layar sisanya tidak ada satu pun kotak centang. Aturannya sama dengan setujui massal di
`#/tugas`, tempat `batch_cap` kosong berarti **nol** kotak centang: sebuah kotak yang bisa dicentang
lalu tidak menawarkan apa pun adalah janji yang tidak ditepati.

### Delapan penanda yang melarang massal

`isBulkAction()` membacanya dari deklarasi aksinya sendiri, **bukan dari daftar putih nama aksi** —
sebuah daftar nama akan diam saat aksi ke-79 lahir, dan diamnya berarti "boleh massal". Setiap
penanda di bawah ada karena ia menggambar sesuatu **per dokumen**:

| penanda | mengapa | terbuang |
|---|---|---|
| `fields` | satu modal per dokumen; seluruh guna massal adalah tidak menjawab sepuluh modal yang sama | 38 |
| `confirmResubmit` | dialog susulan yang dipicu 422, di tengah putaran, pada dokumen yang tidak sedang dilihat | 8 |
| `boardOnly` | tombol yang ada **hanya untuk ditolak** (papan pipeline) | 6 |
| `navigateTo` / `navigateToResult` | berpindah halaman per dokumen: yang terakhir menang, sembilan sisanya tak pernah terlihat | 4 + 4 |
| `opens` | membuka formulir resource lain — bukan POST sama sekali | 3 |
| `inlineNote` | panel catatan hidup di bilah aksi baris | 1 |
| `printForm` | satu tab cetak per dokumen; yang kedua diblokir peramban sebagai popup | 0 |

### Dan satu pengecualian yang bukan soal bentuk

**Pintu keputusan dokumen** (`POST {id}/approve|reject`, 5 aksi) tidak pernah massal di sini —
lewat `session.isDecisionDoor()`, predikat yang sudah ada, bukan kosakata baru.

Setujui massal sudah punya **satu** rumah: `#/tugas`. Rumah itu memegang plafonnya
(`meta.batch_cap`, matriks persetujuan §22/§23) dan spanduk delegasi yang menyebut a.n. siapa
keputusan itu tercatat. Bilah seleksi di layar daftar yang juga bisa menyetujui berarti jalur
persetujuan massal **kedua yang tidak melihat plafon itu** — persis lubang yang ASESMEN-UX §490
sebut: *"persetujuan bernilai ratusan juta memang harus dibuka satu per satu — itu bukan gesekan,
itu kendali."*

---

## `runAction()` tidak bisa diputar, dan itu temuan paketnya

Laporan UI-4a memperkirakan eksekusi massal lewat `runAction()` yang sudah ada. Ia tidak bisa, dan
alasannya ada di kontrak fungsinya sendiri:

* ia **selalu resolve `undefined`** dan tidak pernah melempar — batal, 422, 403 dan galat jaringan
  pulang dengan nilai yang sama persis dengan keberhasilan. Sebuah putaran di atasnya **tidak bisa
  membedakan dokumen yang terposting dari yang ditolak**;
* ia menerbitkan toast keberhasilannya sendiri per panggilan — sepuluh dokumen = sepuluh toast, di
  mana yang dibutuhkan satu kalimat yang menyebut mana yang gagal;
* dan untuk `approve`/`reject` ia memanggil `offerNext()`, satu permintaan `core/inbox` per dokumen.

Maka yang dibagi adalah **permintaannya, bukan pembungkusnya**. `actions.js` kini mengekspor dua
hal, dan `runAction()` sendiri dibangun di atas keduanya:

```
actionPath(action, row, def)              // satu-satunya tempat {id} diganti
postAction(action, row, def, payload)     // kirim, batalkan cache, PULANGKAN hasilnya — dan ia melempar
```

Satu jalur ke server, satu pembatalan cache, dua pemanggil yang masing-masing memutuskan cara
bicaranya sendiri. Sebuah salinan `{id}` di lapisan daftar akan hanyut diam-diam: aksi berikutnya
yang memakai bentuk path lain akan benar lewat tombol baris dan salah lewat bilah seleksi, tanpa
satu pun galat.

---

## Angka pada tombol adalah angka yang akan dikerjakan

Bukan jumlah yang dicentang. Keduanya berbeda begitu satu baris terpilih tidak berada dalam keadaan
yang tepat untuk aksi itu — dan `action.when` menggerbangi **69 dari 78** aksi, jadi perbedaan itu
bukan kasus tepi.

Terukur pada berita acara kunjungan, layar dengan **dua** aksi massal yang berlaku pada keadaan yang
berbeda:

| dicentang | tombol | title |
|---|---|---|
| 2 (satu draf, satu diajukan) | `Ajukan (1)` | "1 dari 2 baris terpilih tidak dalam keadaan yang tepat untuk "Ajukan" dan akan dilewati." |
| | `Kembalikan ke Draf (1)` | idem |

"Posting (3)" yang memposting dua adalah tombol yang berbohong tepat sebelum menulis jurnal.

Baris yang tidak punya satu pun aksi yang berlaku mendapat kotak **mati dengan alasannya di
`title`**, bukan sel kosong: sel kosong terbaca sebagai gangguan render, kotak mati yang menyebutkan
alasannya terbaca sebagai keadaan dokumen — dan itu memang yang sedang dikatakannya.

---

## Satu tombol yang labelnya tidak tergambar sama sekali

Ditemukan dengan **melihat tangkapan layarnya**, bukan dari uji yang hijau.

Bilah seleksi mengambil gayanya dari prototipe, yang menutup semua tombol di dalamnya dengan
`.selbar .btn { background: var(--surface); }`. Di prototipe tombol-tombol itu bervarian *outline*,
jadi aturan itu benar. Di sini tombol aksinya `.btn.primary` — satu-satunya varian yang menggambar
**latarnya sendiri** dan menulis hurufnya dengan `--primary-fg`, yaitu putih. Aturan yang menyapu
semua `.btn` memberinya latar putih di bawah huruf putih:

| | sebelum | sesudah |
|---|---|---|
| `Ajukan (1)` | putih di atas putih — **kotak kosong** | huruf putih di atas `--primary` |
| `Batalkan pilihan` (ghost) | benar | benar |

DOM-nya sempurna sepanjang waktu. `innerText` menjawab `"Ajukan (1)"`, tombolnya hidup, `title`-nya
terisi, dan **S45 lolos** — karena gerbangnya membaca teks, dan teksnya memang ada. Ini keluarga
cacat yang sama dengan em dash diam di P1-D: uji struktural tidak bisa melihatnya, karena tidak ada
yang salah secara struktural.

Gerbangnya karena itu kini menanyakan **warna terhitung** kedua sisi ke peramban, per tombol di
bilah seleksi: `getComputedStyle(b).color` tidak boleh sama dengan `backgroundColor`-nya. Aturan
CSS-nya dipersempit jadi `.selbar .btn:not(.primary)`.

---

## Konfirmasi sekali, dan selalu

Satu dialog untuk seluruh batch, **termasuk untuk aksi yang lewat tombol barisnya tidak
berkonfirmasi**. Bukan karena lebih banyak dialog lebih aman — P1-G membuang modal catatan
persetujuan justru karena itu — melainkan karena kekeliruannya berbeda **jenis**: satu klik keliru
berharga satu dokumen yang halamannya masih terbuka, satu klik massal keliru berharga seluruh
halaman, dan tidak ada satu pun aksi di sini yang punya jalan kembali.

Dialog itu juga satu-satunya tempat orang membaca **daftar nomor** yang akan disentuh:

```
Posting Jurnal 3 jurnal
Posting jurnal ini ke buku besar? Jurnal terposting tidak dapat diubah. Tindakan ini
berjalan satu per satu pada 3 dokumen: JV/2026/09/0007, JV/2026/09/0006, JV/2026/09/0005.
[Batal] [Posting Jurnal 3]
```

`action.confirm` **berbentuk fungsi baris sengaja tidak dipanggil**: ia menulis kalimat untuk satu
dokumen ("Surat penagihan ke-2 INV/… akan dicetak"), dan memanggilnya untuk baris pertama lalu
memasangnya di atas sepuluh nomor adalah kalimat yang salah untuk sembilan di antaranya. Yang
berbentuk string adalah akibat yang berlaku untuk semuanya, jadi ia ikut dicetak — kalimat di atas
adalah milik `finance/journals`.

---

## Seleksi mati di setiap muatan

Halaman lain, filter lain, muat ulang. Sebuah centang yang bertahan melewati perubahan filter
menunjuk dokumen yang **tidak ada lagi di layar**, dan bilah seleksi akan dengan tenang menawarkan
"Posting (3)" untuk tiga baris yang tidak bisa dilihat siapa pun sebelum menekannya.

"Pilih semua" karena itu berarti semua baris **halaman ini** yang bisa dikenai aksi — bukan seluruh
hasil filter. Daftar ini tidak pernah memegang baris yang tidak dimuatnya, dan sebuah centang yang
diam-diam berarti 1.400 dokumen adalah kuasa yang tidak pernah diminta siapa pun. Sebagian terpilih
menggambar keadaan **ketiga** (`indeterminate`), bukan "tidak terpilih": kotak kosong di atas tiga
baris tercentang mengundang klik yang membuang pilihan itu.

---

## Verifikasi

Fixture-nya memaksa hal yang benar: **maker-checker** modul keuangan menolak pengaju memposting
jurnalnya sendiri, jadi jurnal dibuat admin dan diposting `finance-manager` — persis pemisahan yang
dijalani orang.

Satu dokumen sengaja dibuat **basi**: diposting lewat API setelah daftarnya tergambar di layar.
Itulah kegagalan yang harus nyata, dan itu juga keadaan yang paling sering terjadi sungguhan —
daftar yang sudah tidak mutakhir.

| | hasil |
|---|---|
| Layar tanpa aksi massal (`crm/customers`) | nol kolom centang, nol bilah — **tidak ada simpulnya sama sekali** |
| Layar dengan aksi (3 baris, 1 tak berlaku) | 3 kotak, **1 mati** dengan alasan menyebut kodenya |
| Belum ada yang dicentang | bilah **tersembunyi**, bukan kosong-tapi-memakan-ruang |
| 2 dicentang, 2 aksi | `Ajukan (1)` · `Kembalikan ke Draf (1)`, keduanya hidup |
| Mencentang kotak | URL tetap `/r/servicedesk/field-reports` — **tidak** membuka dokumen |
| Ganti filter | seleksi kosong, bilah tersembunyi lagi |
| 3 draf dipilih, 1 dibuat basi | **2 terposting, 1 gagal** |
| Toast gagal | `1 gagal — JV/2026/09/0006: Journal JV/2026/09/0006 is already posted.` |
| Keadaan akhir di server | ketiga fixture `posted` — putaran **tidak** berhenti di kegagalan |
| Setelah dijalankan | seleksi kosong, daftar dimuat ulang |

### Ukuran

| | 1440 px | 390 px |
|---|---|---|
| gulir samping halaman / `.main` | **nol** | **nol** |
| tinggi bilah seleksi | 45 px | **117 px** (membungkus tiga baris) |
| lebar kolom centang | 35 px | 35 px |
| sasaran kotak centang | 16 × 16 px | 16 × 16 px |
| `Ajukan (1)` | huruf `#ffffff` di atas `#1a56db` | sama |

117 px di ponsel dibiarkan, dengan alasan yang sama dengan baris chip UI-4a: tidak seperti bilah
aksi kepala halaman yang selalu ada, bilah ini **hanya muncul saat ada yang dipilih** — dan saat itu
ia menjawab pertanyaan "apa yang akan terjadi pada baris-baris ini".

Kotak centang 16 × 16 px lebih kecil dari sasaran sentuh 24 px yang dianjurkan; ia mewarisi
`input[type=checkbox]` yang sudah dipakai seluruh aplikasi, dan mengubahnya menyentuh setiap
formulir. Sel `td.pick` selebar 35 px memberi ruang tekan yang lebih longgar daripada kotaknya,
tetapi ini bukan perbaikan yang sama — dicatat, tidak diperbaiki di paket ini.

Satu gerbang S45 sendiri lahir salah, dan DB bersih yang menangkapnya. Versi pertamanya menuntut
kotak "pilih semua" berkeadaan `indeterminate` tanpa syarat sesudah dua baris dicentang — dan ia
hijau, karena sisa fixture run sebelumnya membuat halaman itu punya empat baris yang bisa dicentang.
Pada salinan DB yang baru, dua baris itu adalah **seluruh** yang bisa dicentang, keadaan yang benar
adalah "tercentang penuh", dan gerbangnya memerahkan kode yang benar. Ia kini menghitung
`kotak − kotak_mati` dan menuntut keadaan yang sesuai, bukan angka tetap.

### Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.414 / 11.600**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S8 S14 S18 S21 S21m S43 S44 S45` | **hijau, keluar 0** — `results-ui-4b.json` |
| `SHELL_VERSION` | 22 → **23** |

**S45 baru** memaku syaratnya di tiga layar dengan tiga peran, dan dibuktikan **merah dua kali**:

```
# sabotase 1 — label memakai jumlah yang dicentang, bukan yang berlaku
GAGAL: tombol 'Ajukan (2)' — dua baris tercentang, tetapi hanya SATU yang dalam
       keadaan tepat: labelnya harus (1)
STATUS KELUAR: 1

# sabotase 2 — `break` pada cabang gagal
GAGAL: JV/2026/09/0017 berhasil diposting tetapi tidak disebut di toast mana pun,
       putaran berhenti pada kegagalan — masih draf: ['JV/2026/09/0017']
STATUS KELUAR: 1
```

Dua sabotase, bukan satu, karena dua syarat paket ini berdiri sendiri: **tombol yang jujur sebelum
ditekan**, dan **putaran yang jujur sesudahnya**. Keduanya dipulihkan byte-per-byte (md5 sama).

---

## Yang ditemukan dan tidak diperbaiki di sini

Pesan penolakan server untuk jurnal yang sudah terposting berbahasa **Inggris** —
`Journal JV/2026/09/0006 is already posted.` — dan kini ia tampil apa adanya di toast berbahasa
Indonesia. Toast massal memang harus menyebut kalimat server apa adanya (dialah yang tahu
sebabnya); yang salah adalah kalimatnya, dan itu milik modul Finance, bukan paket cangkang.
Ia tidak disentuh di sini supaya paket ini tidak menyeret perubahan perilaku API.

---

## Yang sengaja tidak dikerjakan

* **Tampilan tersimpan** (`.chips#views` di prototipe) — UI-4c. Hari ini keadaannya hanya ada di
  URL; menyimpannya butuh tempat menyimpan, dan itu keputusan (preferensi server vs lokal), bukan
  gaya.
* **Seleksi lintas halaman.** Ditolak, bukan ditunda: lihat "Seleksi mati di setiap muatan".
* **Plafon nilai untuk aksi massal non-persetujuan.** `batch_cap` adalah aturan *persetujuan*, dan
  26 aksi di sini bukan persetujuan. Memberi mereka plafon yang sama berarti memakai satu angka
  registri untuk dua arti.
* **Formulir halaman penuh** — keputusan pemilik #2, paketnya sendiri.
