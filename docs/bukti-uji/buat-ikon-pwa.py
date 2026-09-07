"""Ikon PWA (public/app/icons/) DIBANGKITKAN dari public/app/favicon.svg.

Tidak ada rasterizer di host ini — rsvg-convert, ImageMagick, Inkscape, Pillow dan
cairosvg semuanya tidak terpasang (diperiksa 7 Sep 2026), dan aturan paket melarang
menambah dependensi. Yang ADA adalah Chromium milik harness Playwright: mesin yang
sama yang akan menggambar ikon itu di layar orang. Skrip ini memotretnya.

    /root/.venv-playwright/bin/python docs/bukti-uji/buat-ikon-pwa.py

Tiga berkas, semuanya dari SATU sumber (favicon.svg, tidak disalin-tempel):
  icon-192.png / icon-512.png   ikon "any": lambang apa adanya, sudut membulat,
                                latar transparan (omit_background) supaya peluncur
                                yang memberi bingkainya sendiri tidak menumpuk kotak;
  icon-maskable-512.png         ikon "maskable": latar #1a56db PENUH BIDANG (peluncur
                                Android memotongnya menjadi lingkaran/squircle) dengan
                                lambang diperkecil ke 78 % dan DIPUSATKAN pada kotak
                                batasnya sendiri — zona aman maskable adalah lingkaran
                                berdiameter 80 % (jari-jari 205 px pada 512), dan setengah
                                diagonal lambang pada skala ini 137 px.

Ukurannya harus tetap cocok dengan yang DIUMUMKAN manifest: itu dipaku
tests/Feature/Core/PwaManifestTest (membaca IHDR tiap PNG).
"""
import pathlib
import re
import sys

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parents[2]
SRC = ROOT / "public/app/favicon.svg"
OUT = ROOT / "public/app/icons"

svg = SRC.read_text(encoding="utf-8").strip()
inner = re.sub(r"^<svg[^>]*>|</svg>\s*$", "", svg).strip()

PAGE = """<!doctype html><meta charset=utf-8>
<style>html,body{margin:0;padding:0;background:transparent}
svg{display:block;width:%(px)dpx;height:%(px)dpx}</style>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">%(body)s</svg>"""

# Lambang favicon.svg tidak dipusatkan pada viewBox-nya: kotak batasnya x 7..25,
# y 10,5..23, jadi titik tengahnya (16 · 16,75), bukan (16 · 16). Geseran di bawah
# memusatkan KOTAK BATAS itu, bukan viewBox: t = 16 - 0,78 x pusat.
MASKABLE = """<!doctype html><meta charset=utf-8>
<style>html,body{margin:0;padding:0;background:transparent}
svg{display:block;width:%(px)dpx;height:%(px)dpx}</style>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
<rect width="32" height="32" fill="#1a56db"/>
<g transform="translate(3.52 2.935) scale(0.78)">%(body)s</g>
</svg>"""

JOBS = [
    ("icon-192.png", 192, PAGE),
    ("icon-512.png", 512, PAGE),
    ("icon-maskable-512.png", 512, MASKABLE),
]

OUT.mkdir(parents=True, exist_ok=True)
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    for name, px, template in JOBS:
        page = browser.new_page(viewport={"width": px, "height": px}, device_scale_factor=1)
        page.set_content(template % {"px": px, "body": inner})
        page.screenshot(path=str(OUT / name), omit_background=True)
        page.close()
        print(f"{name}: {px}x{px}  {(OUT / name).stat().st_size} B")
    browser.close()

sys.exit(0)
