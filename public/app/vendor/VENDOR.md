# Pustaka vendor SPA (`public/app/vendor/`)

Manifest berkas pihak ketiga yang dilayani sebagai berkas statis oleh SPA. Berkas ini
dibaca `tests/Feature/Core/VendorManifestTest.php`: setiap berkas di folder ini harus punya
baris di tabel **Berkas** dengan sha256 yang sama, setiap baris tabel harus menunjuk berkas
yang ada, jumlah gzip seluruh folder ≤ 60 KB (ROADMAP-HASHMICRO Fase 1, target metrik), dan
tidak satu pun berkas di `public/app` boleh memuat skrip/stylesheet/modul/`fetch` dari CDN.

## Aturan (ROADMAP-HASHMICRO §5 keputusan #2)

1. **Tanpa CDN, tanpa npm saat runtime.** Semua yang dibutuhkan peramban ada di repositori
   dan dilayani dari asal yang sama. Uji `VendorManifestTest::test_no_cdn_loader_anywhere_under_app`
   memindai `*.html *.js *.css` di `public/app`.
2. **Hanya dua pustaka ini** — SortableJS dan sprite Lucide subset. Grafik ditulis sendiri
   (`js/charts.js`, 0 KB vendor). Pustaka lain butuh keputusan pemilik yang dicatat di ledger
   ROADMAP-HASHMICRO §5, lalu baris di sini + uji manifest yang hijau.
3. **Satu folder per pustaka per versi** (`<lib>@<ver>/`), hanya berkas yang dipakai +
   LICENSE. Memperbarui = folder baru, ubah rujukan (`ui.js` `LUCIDE_SPRITE`, pemuat Sortable
   di paket yang memakainya), hapus folder lama, perbarui tabel di bawah.
4. **Plafon**: jumlah gzip -9 seluruh folder ≤ 60 KB (61 440 byte). Terukur 5 Sep 2026:
   **21 206 byte** (lihat kolom gzip di tabel).

## Pustaka

| Pustaka | Versi | Sumber | sha256 tarball (seperti diunduh) | Lisensi | gzip -9 | Untuk apa |
|---|---|---|---|---|---|---|
| SortableJS | 1.15.7 | https://registry.npmjs.org/sortablejs/-/sortablejs-1.15.7.tgz | `66cbfc8fa18860783584fe0c346baf6cbf3399da72d23d21053425ba232abed4` | MIT (`sortablejs@1.15.7/LICENSE`, SPDX `MIT`) | 14 993 B (`Sortable.min.js`) | seret-lepas widget dasbor (P1-D) dan kartu kanban (P1-G), termasuk sentuh; dimuat malas oleh layar yang memakainya, bukan oleh shell |
| Lucide (lucide-static) | 1.41.0 | https://registry.npmjs.org/lucide-static/-/lucide-static-1.41.0.tgz | `d10f583e2076986ddcf79def168e6ce1e64f742fa06b6014347c59fa581aa6cb` | ISC (`lucide@1.41.0/LICENSE`, SPDX `ISC`; ikon turunan Feather MIT tercantum di berkas yang sama) | 4 045 B (`sprite.svg`) | sprite 79 simbol `<symbol id="lucide-<nama>" viewBox="0 0 24 24">` untuk `ui.js svgIcon()` — glyph `icon()` hari ini, 14 glyph grup NAV (launcher/beranda modul P1-C), dan kebutuhan Fase 1 (dasbor, kanban, gantt, grafik, PWA) |

Integritas tarball diverifikasi terhadap registry saat diunduh (5 Sep 2026): `dist.shasum`
sha1 `83a0bddc472117ee328dea20b2e6f490fed20f86` (sortablejs) dan
`56c437537404a446883ff611301ef2442835bece` (lucide-static), serta `dist.integrity` sha512
`Kk8wLQPl…u6ux0A==` dan `39fX7SH+…uhQSFg==` — ketiganya cocok.

## Berkas

Kolom sha256 adalah nilai yang diuji; ukuran adalah byte mentah dan gzip -9 (`gzip -9c < f | wc -c`, dari
stdin supaya nama berkas tidak ikut ke header gzip). Uji manifest mengukur ulang dengan `gzencode(…, 9)`
PHP — deflate zlib, bukan GNU gzip, jadi angkanya bisa selisih puluhan byte dari kolom ini; yang diuji
adalah jumlahnya terhadap plafon, bukan kesamaan kolom.

| Berkas | sha256 | byte | gzip -9 |
|---|---|---|---|
| `lucide@1.41.0/LICENSE` | `b495047bd93a9b06913511076f504daba17d5bbeb3e0650f3bb53a4220329c57` | 3208 | 1500 |
| `lucide@1.41.0/sprite.svg` | `3d01cd3c836247add5ab9ee9415324e129db79e0ed39ca79f3e20c83ba8d1502` | 23271 | 4045 |
| `sortablejs@1.15.7/LICENSE` | `e94dfc31e800d169257569db270457c9f028440c9ccae41e7eb78b2db18f1298` | 1106 | 668 |
| `sortablejs@1.15.7/Sortable.min.js` | `bf4241bc73fef7f11c59a283a69fe8051cdd31c6d8ff5a2b9ba219e7831fcf76` | 45478 | 14993 |

## Isi sprite Lucide (79 simbol)

Nama = nama kanonik Lucide 1.41.0 (nama lama seperti `alert-triangle`, `bar-chart-3`,
`help-circle`, `more-horizontal` TIDAK ikut — `svgIcon()` memetakan nama `icon()` lama ke
nama kanonik ini).

- **Glyph `icon()` hari ini (19)**: search, chevron-down, chevron-right, plus, x, menu, check,
  pencil, trash-2, arrow-left, refresh-cw, download, inbox, triangle-alert, log-out, printer,
  sun, moon, star.
- **Grup NAV (14, urutan schema.js)**: layout-dashboard (Ringkasan), handshake (Penjualan),
  calculator (Estimasi), drafting-compass (Engineering), hard-hat (Proyek), clipboard-check
  (Mutu), shopping-cart (Pengadaan), warehouse (Persediaan), file-signature (Subkontrak),
  landmark (Keuangan), users (SDM & Payroll), headset (Layanan), truck (Aset), settings (Sistem).
- **Fase 1 (46)**: home, layout-grid, kanban, chart-gantt, chart-bar, chart-line, chart-pie,
  filter, clock, bell, info, chevron-up, chevron-left, chevrons-left, chevrons-right, arrow-up,
  arrow-down, arrow-right, arrow-up-down, grip-vertical, wifi-off, wifi, external-link,
  calendar, file-text, upload, eye, copy, ellipsis, user, lock, circle-help, circle-check,
  circle-x, circle-alert, list, table, maximize-2, minimize-2, save, send, paperclip, camera,
  map-pin, history, sliders-horizontal.

Setiap `<symbol>` membawa `fill="none" stroke="currentColor" stroke-width="2"
stroke-linecap="round" stroke-linejoin="round"` sendiri (atribut itu ada di `<svg>` luar pada
berkas sumber Lucide; pada rujukan `<use href="…sprite.svg#id">` lintas berkas, leluhur di
berkas sprite tidak diwarisi, jadi harus ada di simbolnya).

## Cara memperbarui

Semua perintah dari akar repositori. Ganti versi sesuai kebutuhan; verifikasi tarball terhadap
`dist.shasum`/`dist.integrity` registry SEBELUM mengekstrak.

```sh
# 1. Unduh + verifikasi (contoh SortableJS)
V=1.15.7; curl -sSo /tmp/sortablejs-$V.tgz https://registry.npmjs.org/sortablejs/-/sortablejs-$V.tgz
curl -sS https://registry.npmjs.org/sortablejs | python3 -c "import sys,json;d=json.load(sys.stdin)['versions']['$V']['dist'];print(d['shasum']);print(d['integrity'])"
sha1sum /tmp/sortablejs-$V.tgz; echo "sha512-$(openssl dgst -sha512 -binary /tmp/sortablejs-$V.tgz | base64 -w0)"
sha256sum /tmp/sortablejs-$V.tgz            # → kolom "sha256 tarball" di atas
# 2. Ekstrak HANYA yang dipakai
mkdir -p public/app/vendor/sortablejs@$V
tar xzf /tmp/sortablejs-$V.tgz -C public/app/vendor/sortablejs@$V --strip-components=1 package/Sortable.min.js package/LICENSE

# Lucide: unduh + verifikasi seperti di atas (paket lucide-static), lalu bangun sprite subset.
# Skrip di bawah = satu-satunya cara membuat sprite.svg; daftar nama = daftar "Isi sprite" di atas.
LV=1.41.0; python3 - /tmp/lucide-static-$LV.tgz public/app/vendor/lucide@$LV <nama-ikon ...> <<'PY'
import os, re, sys, tarfile, xml.etree.ElementTree as ET
NS = 'http://www.w3.org/2000/svg'; ET.register_namespace('', NS)
tgz, out, names = sys.argv[1], sys.argv[2], sorted(set(sys.argv[3:])); os.makedirs(out, exist_ok=True); symbols = []
with tarfile.open(tgz, 'r:gz') as tar:
    version = re.search(r'"version":\s*"([^"]+)"', tar.extractfile('package/package.json').read().decode()).group(1)
    open(os.path.join(out, 'LICENSE'), 'wb').write(tar.extractfile('package/LICENSE').read())
    for name in names:
        root = ET.fromstring(tar.extractfile(f'package/icons/{name}.svg').read().decode()); assert root.get('viewBox') == '0 0 24 24', name
        parts = ''.join('<%s %s/>' % (c.tag.replace('{%s}' % NS, ''), ' '.join(f'{k}="{v}"' for k, v in c.attrib.items())) for c in root)
        symbols.append(f'<symbol id="lucide-{name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{parts}</symbol>')
sprite = ('<?xml version="1.0" encoding="UTF-8"?>\n<!-- lucide-static v%s subset (%d simbol) - ISC, lihat LICENSE di folder ini. Dibangun oleh perintah di public/app/vendor/VENDOR.md; jangan disunting tangan. -->\n<svg xmlns="%s">\n' % (version, len(symbols), NS)) + '\n'.join(symbols) + '\n</svg>\n'
ET.fromstring(sprite); open(os.path.join(out, 'sprite.svg'), 'w', encoding='utf-8').write(sprite); print(version, len(symbols), 'simbol')
PY

# 3. Perbarui tabel "Berkas" (sha256, byte, gzip) — keluaran ini disalin apa adanya
find public/app/vendor -type f ! -name VENDOR.md | sort | while read f; do printf '| `%s` | `%s` | %s | %s |\n' "${f#public/app/vendor/}" "$(sha256sum "$f" | cut -c1-64)" "$(wc -c < "$f")" "$(gzip -9c < "$f" | wc -c)"; done
# 4. Ubah rujukan versi (ui.js LUCIDE_SPRITE / pemuat Sortable), hapus folder versi lama, lalu:
vendor/bin/phpunit --no-progress --filter VendorManifestTest tests/Feature/Core
```
