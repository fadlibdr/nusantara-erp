# Contoh berkas rekening koran

Dipakai untuk mencoba **Keuangan › Rekonsiliasi Bank › Impor** tanpa berkas bank
sungguhan. Keduanya cocok dengan data demo di `database/database.sqlite`.

## `rekening-koran-mandiri-2026-02.sta` — MT940

Rekening **Mandiri Proyek** (COA 1-1220), Februari 2026. Satu mutasi masuk
Rp 10.767.000.000 yang berpadanan dengan `RCV/2026/II/0001`; referensinya
(`CN 260227/4415`) sama dengan yang tercatat pada pembayaran itu, jadi
pencocokannya diusulkan dengan keyakinan tinggi. Setelah dicocokkan, rekening
ini cocok sepenuhnya.

Format MT940 tidak perlu pemetaan kolom — tata letaknya baku.

## `rekening-koran-bca-2026-04.csv` — CSV

Rekening **BCA Operasional** (COA 1-1210), April 2026. Pemetaan yang benar:

| Pengaturan | Nilai |
|---|---|
| Pemisah kolom | Titik koma ( ; ) |
| Format angka | Indonesia — 1.234.567,89 |
| Baris judul yang dilewati | 1 |
| Kolom tanggal | Kolom 1 · `dd/mm/yyyy` |
| Kolom keterangan | Kolom 2 |
| Mode nilai | Dua kolom: debit & kredit |
| Kolom debit | Kolom 4 |
| Kolom kredit | Kolom 5 |
| Kolom saldo | Kolom 6 |
| Periode | 2026-04-01 s/d 2026-04-30 |
| Saldo awal | 0 |
| Saldo akhir | -232795000 |

Berisi dua mutasi: pembayaran ke vendor yang berpadanan dengan `PAY/2026/IV/0001`,
dan **biaya admin Rp 250.000 yang belum dibukukan siapa pun**. Yang kedua sengaja
ada: setelah pencocokan selesai, rekonsiliasi tetap menampilkannya sebagai selisih
"ada di bank, belum dibukukan" — dan satu-satunya cara menghilangkannya adalah
membukukan voucher jurnal, yang dilakukan di layar Jurnal, bukan di sini.

Perhatikan pemisah **titik koma**: angka Indonesia memakai koma sebagai desimal,
jadi berkas berpemisah koma akan terpotong di tengah angka. Layar impor
memperingatkan bila kedua pilihan itu dipasangkan.
