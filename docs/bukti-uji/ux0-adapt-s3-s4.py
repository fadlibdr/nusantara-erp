#!/usr/bin/env python3
"""UX-0: turunkan salinan S3 dan S4 yang membidik formulir HALAMAN, bukan dialog.

Mengapa ada: sejak UI-4d (1067682) formulir PO adalah halaman `#/f/procurement/purchase-orders`,
sedangkan S3 dan S4 masih menunggu `.modal` setelah menekan Tambah. Pada main (UX-0, 3 Okt 2026)
keduanya MERAH dengan "waiting for locator(".modal") to be visible" — bukan karena aplikasinya
rusak, tetapi karena skenarionya membidik permukaan yang sudah tidak dipakai formulir berbaris.
Harness asli TIDAK diubah (paket ini hanya dokumen dan pengukuran); skrip ini menulis dua salinan
dengan SATU jenis penggantian selektor, hanya pada fase mengisi dan menyimpan formulir:

  .modal .modal-foot button  ->  .page-head .actions button   (tombol Simpan pindah ke kepala halaman)
  .modal                     ->  .form-page                   (wadah formulir)
  m.querySelector('.modal-body') -> m                         (halaman tidak punya wadah gulir dalam)

Bagian S3 sesudah simpan (tombol Ajukan di halaman detail dan dialog konfirmasinya) dan bagian S4
sesudah token dicabut (login ulang, toast Pulihkan, dialog Pulihkan) TIDAK diubah: itu memang dialog.

Pakai:
  python3 ux0-adapt-s3-s4.py <direktori-keluaran>
  ERP_BASE=... ERP_DB=... UXTEST_OUT=... python3 <direktori-keluaran>/harness-s3-page.py S3
"""
import os, sys

SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)), "harness-playwright.py")
out = sys.argv[1]
os.makedirs(out, exist_ok=True)
L = open(SRC, encoding="utf-8").read().split("\n")

def idx(prefix, start=0):
    for i in range(start, len(L)):
        if L[i].startswith(prefix) or L[i].lstrip().startswith(prefix):
            return i
    raise SystemExit(f"jangkar tidak ditemukan: {prefix!r} — harness berubah, tinjau ulang skrip ini")

def swap(s, simpan_saja=False):
    if simpan_saja:
        return s.replace(".modal .modal-foot button:has-text('Simpan')", ".page-head .actions button:has-text('Simpan')")
    s = s.replace("m.querySelector('.modal-body')", "m")
    s = s.replace(".modal .modal-foot button", ".page-head .actions button").replace(".modal-foot button", ".page-head .actions button")
    return s.replace(".modal", ".form-page")

# S3: dari `def s3(` sampai sebelum `t_saved = int(` (sesudah simpan; sesudahnya hanya halaman detail).
a, b = idx("def s3(pg):"), idx("t_saved = int(")
s3 = L[:]
for i in range(a, b):
    s3[i] = swap(s3[i])
open(os.path.join(out, "harness-s3-page.py"), "w", encoding="utf-8").write("\n".join(s3))

# S4: dari `def s4(` sampai baris `typed = ...` (mengetik), lalu SATU klik Simpan sesudah token dicabut.
a = idx("def s4(pg):")
t = idx("typed = pg.evaluate", a)
s4 = L[:]
for i in range(a, t + 1):
    s4[i] = swap(s4[i])
k = idx("click(pg, \".modal .modal-foot button:has-text('Simpan')\")", t)
s4[k] = swap(s4[k], simpan_saja=True)
open(os.path.join(out, "harness-s4-page.py"), "w", encoding="utf-8").write("\n".join(s4))
print("ditulis:", os.path.join(out, "harness-s3-page.py"), os.path.join(out, "harness-s4-page.py"))
