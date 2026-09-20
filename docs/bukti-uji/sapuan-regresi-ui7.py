"""Sapuan regresi UI-7 — seluruh katalog rute, empat lebar, dua tema, dan kertas.

Instrumen ini BUKAN bagian dari himpunan gerbang rilis (itu harness-playwright.py).
Ia sapuan luas: 143 rute × 6 putaran = 858 pengukuran, dan yang dicarinya adalah
cacat yang hanya muncul pada kombinasi lebar/tema/media tertentu.

    python3 -m venv pw && pw/bin/pip install playwright
    pw/bin/python -m playwright install --with-deps chromium
    B=http://127.0.0.1:8000 OUT=<dir> ROUTES=docs/bukti-uji/rute-katalog.json \
      SHARD=0 SHARDS=4 pw/bin/python docs/bukti-uji/sapuan-regresi-ui7.py [nama-putaran ...]

`SHARD`/`SHARDS` memecah katalog antar-PROSES (lihat catatan thread di bawah).
Jalankan `SHARD=0 SHARDS=99999` sekali lebih dulu supaya SATU proses menulis
berkas keadaan sesi, baru pecahan-pecahannya paralel — sebab masuknya dibatasi
10 kali per menit per IP.

Tiga kekeliruan instrumen yang PERNAH melaporkan "nol temuan" dengan percaya
diri, dan penjaga yang menutup masing-masing, ada di komentar di tempatnya:
thread → pecahan proses; jeda tetap → penjaga siap-gambar + DOM tenang; masuk
per pecahan → satu berkas keadaan + penjaga sesi yang MEMBATALKAN sapuan.
"""
import os, json, sys, pathlib
from playwright.sync_api import sync_playwright

# Pecahan proses: API SYNC Playwright memakai greenlet dan TIDAK bisa dipakai
# lintas thread ("Cannot switch to a different thread"). Jadi paralelismenya
# proses, bukan thread — tiap proses menjalankan satu pecahan rute.
SHARD = int(os.environ.get("SHARD", "0"))
SHARDS = int(os.environ.get("SHARDS", "1"))

BASE = os.environ["B"]
OUT = os.environ["OUT"]
ROUTES = json.load(open(os.environ["ROUTES"]))
ALL = ["#/" + r for r in ROUTES["literal"]] + ["#/r/" + r for r in ROUTES["resources"]]

# ---------------------------------------------------------------- instrumen
# Tabrakan teks: instrumen v3 (kotak baris dipotong tiap leluhur yang menggulung).
# Kontras: latar EFEKTIF dicari dengan menaiki leluhur sampai ada yang tidak
# transparan — `getComputedStyle().backgroundColor` sebuah <span> hampir selalu
# rgba(0,0,0,0), jadi membandingkan terhadapnya akan melaporkan kontras palsu.
PROBE = r"""() => {
  // `checkVisibility()` IKUT, dan ia yang menutup kelas positif-palsu terakhir:
  // isi sebuah <details> yang TERTUTUP punya `content-visibility: hidden` pada
  // ::details-content — tidak digambar, tidak bisa di-hit-test — tetapi TETAP
  // punya kotak tata letak, jadi `getClientRects()` mengembalikan geometri
  // sungguhan. Terukur 20 Sep 2026: dua "tabrakan" terakhir di layar dokumen
  // adalah teks bantuan di dalam `.action-note` yang tertutup, jatuh di atas
  // judul "Riwayat Persetujuan" — di piksel, tidak di layar. `display: none`
  // dan `visibility: hidden` saja tidak melihatnya.
  const vis = (e) => { const cs = getComputedStyle(e);
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    if (e.getClientRects().length === 0) return false;
    return typeof e.checkVisibility === 'function' ? e.checkVisibility() : true; };

  const clipOf = (e) => { const b = [];
    for (let p = e.parentElement; p; p = p.parentElement) { const cs = getComputedStyle(p);
      if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') b.push(p.getBoundingClientRect()); }
    return b; };
  const clip = (rects, clips) => rects.map(r => {
    let x1 = r.x, y1 = r.y, x2 = r.x + r.width, y2 = r.y + r.height;
    for (const c of clips) { x1 = Math.max(x1, c.x); y1 = Math.max(y1, c.y);
      x2 = Math.min(x2, c.x + c.width); y2 = Math.min(y2, c.y + c.height); }
    return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
  }).filter(r => r.w > 1 && r.h > 1);

  const leaves = [...document.querySelectorAll('.main *')].filter(e =>
    e.children.length === 0 && (e.textContent || '').trim().length > 2 && vis(e));
  const items = leaves.map(e => ({ e, rects: clip([...e.getClientRects()], clipOf(e)),
    txt: (e.textContent || '').trim().slice(0, 40),
    cls: e.getAttribute('class') || e.tagName.toLowerCase() })).filter(i => i.rects.length);

  const tabrakan = [];
  for (let i = 0; i < items.length; i++) for (let j = i + 1; j < items.length; j++) {
    const a = items[i], b = items[j];
    if (a.e.contains(b.e) || b.e.contains(a.e)) continue;
    let worst = 0;
    for (const ra of a.rects) for (const rb of b.rects) {
      const ox = Math.min(ra.x + ra.w, rb.x + rb.w) - Math.max(ra.x, rb.x);
      const oy = Math.min(ra.y + ra.h, rb.y + rb.h) - Math.max(ra.y, rb.y);
      if (ox > 4 && oy > 4) { const mn = Math.min(ra.w * ra.h, rb.w * rb.h);
        if (mn > 0) worst = Math.max(worst, ox * oy / mn); }
    }
    if (worst > 0.35) tabrakan.push({ a: a.txt, b: b.txt, acls: a.cls, bcls: b.cls, r: +worst.toFixed(2) });
  }

  // ---- kontras
  const parse = (c) => { const m = c.match(/[\d.]+/g); return m ? m.map(Number) : null; };
  const lum = (r, g, b) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const bgOf = (e) => { for (let p = e; p; p = p.parentElement) {
      const c = parse(getComputedStyle(p).backgroundColor);
      if (c && (c.length < 4 || c[3] > 0.5)) return c; }
    const c = parse(getComputedStyle(document.body).backgroundColor); return c || [255, 255, 255]; };
  const kontras = [];
  for (const it of items) {
    const cs = getComputedStyle(it.e);
    const fg = parse(cs.color); if (!fg) continue;
    if (fg.length > 3 && fg[3] < 0.5) continue;
    const bg = bgOf(it.e);
    const l1 = lum(fg[0], fg[1], fg[2]), l2 = lum(bg[0], bg[1], bg[2]);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const px = parseFloat(cs.fontSize), wt = parseInt(cs.fontWeight) || 400;
    const besar = px >= 24 || (px >= 18.66 && wt >= 700);
    const butuh = besar ? 3.0 : 4.5;
    if (ratio < butuh - 0.02)
      kontras.push({ txt: it.txt, cls: it.cls, px, wt, rasio: +ratio.toFixed(2), butuh,
                     fg: cs.color, bg: `rgb(${bg.slice(0,3).join(',')})` });
  }

  // ---- meluber keluar induknya
  const meluber = [...document.querySelectorAll('.main .stat .value, .main td, .main th, .main .card-head h2')]
    .filter(vis).filter(e => {
      const p = e.parentElement; if (!p) return false;
      const pc = getComputedStyle(p);
      if (pc.overflowX !== 'visible') return false;
      return e.getBoundingClientRect().right > p.getBoundingClientRect().right + 1;
    }).map(e => (e.innerText || '').trim().slice(0, 24));

  // ---- sasaran sentuh
  // Yang dicatat harus cukup untuk BERTINDAK. Versi pertama menyimpan tinggi,
  // lebar dan teks saja — dan 575 temuan yang semuanya "16x16, teks kosong"
  // tidak memberi tahu apa pun tentang apa yang harus diperbaiki. Tag, kelas,
  // jenis dan induk adalah bedanya antara "ada 575 sasaran kecil" dan "kotak
  // centang tabel, tombol urut kepala kolom, dan <input> tanpa atribut type".
  const sasaran = [...document.querySelectorAll('.main button, .main a, .main input, .main select, .main [role=button]')]
    .filter(vis).map(e => { const r = e.getBoundingClientRect();
      return { h: Math.round(r.height), w: Math.round(r.width),
               tag: e.tagName.toLowerCase(), jenis: e.getAttribute('type') || '',
               cls: (e.getAttribute('class') || '').slice(0, 40),
               induk: ((e.parentElement && e.parentElement.getAttribute('class')) || '').slice(0, 30),
               txt: (e.innerText || e.getAttribute('aria-label') || '').trim().slice(0, 20) }; })
    .filter(e => e.h < 36 && e.h > 0);

  // Layar yang permintaannya GAGAL menggambar `errorState()` — sebuah
  // `.alert.error` dengan tombol "Coba lagi" — dan halaman seperti itu punya
  // nol tabrakan, nol kegagalan kontras dan nol luberan. Ia bukan halaman yang
  // LULUS, ia halaman yang tidak punya isi untuk gagal. Terukur 20 Sep 2026:
  // lima rute (#/sewa-vs-beli, #/po-outstanding, #/siap-tagih, #/sla-breaches,
  // #/retensi) tercatat tepat 16 elemen dan "bersih" di satu putaran sementara
  // putaran lain mengukur 125/93/67/50/23 — server `php -S` berulir tunggal
  // kewalahan oleh tiga peramban, dan aplikasinya menampilkan galat dengan
  // jujur. Yang tidak jujur adalah sapuannya.
  return {
    galat_layar: [...document.querySelectorAll('.main .alert.error')]
      .map(e => (e.innerText || '').trim().slice(0, 70)),
    ada_main: !!document.querySelector('.main'),
    n: document.querySelectorAll('.main *').length,
    gulirX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    lebar_doc: document.documentElement.scrollWidth,
    lebar_view: document.documentElement.clientWidth,
    tabrakan, kontras, meluber, sasaran,
    h1: ((document.querySelector('.main h1') || {}).innerText || '').trim().slice(0, 44),
  };
}"""

STATE_FILE = os.environ.get("STATE", f"{OUT}/state.json")

def cached_state(pw, base):
    """Masuk SEKALI untuk seluruh sapuan, bukan sekali per pecahan.

    iam/auth/login dibatasi 10 kali per menit per IP. Enam pecahan × tiga
    putaran berurutan = 18 kali masuk dalam beberapa menit, dan yang kena 429
    melanjutkan sebagai TAMU: setiap halaman lalu menampilkan layar masuk,
    `.main` kosong, dan sapuannya melaporkan 143 rute "kosong" dengan nol
    kegagalan kontras. Sebuah sapuan yang mengukur layar masuk akan selalu
    terlihat bersih.
    """
    f = pathlib.Path(STATE_FILE)
    if f.exists():
        return json.loads(f.read_text())
    st = login_state(pw, base)
    f.parent.mkdir(parents=True, exist_ok=True)
    f.write_text(json.dumps(st))
    return st

def login_state(pw, base):
    br = pw.chromium.launch()
    ctx = br.new_context(viewport={"width": 1440, "height": 900})
    pg = ctx.new_page()
    pg.goto(base + "/app/", wait_until="networkidle")
    pg.fill("input[type=email]", "admin@nusantara.test"); pg.fill("input[type=password]", "password")
    for _ in range(6):
        pg.click("button[type=submit]")
        try:
            pg.wait_for_selector("nav.nav", timeout=8000); break
        except Exception:
            pg.wait_for_timeout(5000)
    # Tunggu sampai TOKENNYA benar-benar ada sebelum memotret keadaan sesi.
    # `nav.nav` muncul sebelum aplikasi selesai menulis kredensialnya ke
    # localStorage, jadi memotret persis saat itu kadang menangkap sesi TAMU —
    # dan seluruh sapuan lalu mengukur layar masuk sambil melaporkan nol temuan.
    pg.wait_for_timeout(2500)
    for _ in range(20):
        if pg.evaluate("() => !!localStorage.getItem('nusantara_erp_token') "
                       "|| !!localStorage.getItem('nusantara_erp_user')"):
            break
        pg.wait_for_timeout(500)
    state = ctx.storage_state()
    punya = any(any(k["name"].startswith("nusantara_erp") for k in o.get("localStorage", []))
                for o in state.get("origins", []))
    if not punya:
        print("  PERINGATAN: keadaan sesi tidak memuat kredensial nusantara_erp")
    ctx.close(); br.close()
    return state

INFLIGHT = """
(() => { window.__inflight = 0;
  const f = window.fetch;
  window.fetch = function (...a) { window.__inflight++;
    return f.apply(this, a).finally(() => { window.__inflight--; }); };
  const open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function (...a) { window.__inflight++;
    this.addEventListener('loadend', () => { window.__inflight--; });
    return send.apply(this, a); };
  void open;
})();
"""

def run_pass(pw, state, label, width, height, scheme, media, touch, routes):
    mine = routes[SHARD::SHARDS]
    br = pw.chromium.launch()
    results = {}
    ctx = br.new_context(viewport={"width": width, "height": height}, storage_state=state,
                         color_scheme=scheme, has_touch=touch, is_mobile=touch)
    # Hitung permintaan yang MASIH TERBANG. Penjaga "jumlah elemen berhenti
    # berubah" saja TIDAK cukup: layar ini menggambar KERANGKA lebih dulu, dan
    # kerangka itu diam sempurna selama fetch-nya berjalan. Terukur 19 Sep 2026
    # pada sapuan enam putaran yang sudah "hijau": `#/r/finance/accounts`
    # tercatat 1.758 elemen di satu putaran dan 45 di putaran lain, `#/settings`
    # 1.541 lawan 27 — 51 dari 143 rute berbeda, sebagian 40 kali lipat. Yang
    # 45 itu kerangka yang tenang, dan sapuan melaporkannya "siap".
    ctx.add_init_script(INFLIGHT)
    pg = ctx.new_page()
    # Respons GAGAL per rute. `.alert.error` saja tidak cukup untuk memisahkan
    # "server tidak menjawab" dari "aplikasi punya sesuatu yang jujur untuk
    # dikatakan": `#/bank-recon` menggambar alert yang sama untuk kalimat
    # "Belum ada rekening koran … yang diimpor", yang BENAR pada basis data ini.
    # Yang membedakannya bukan DOM melainkan lalu lintasnya — 5xx atau permintaan
    # yang tidak sampai. Tanpa pemisahan ini penjaga galat layar menolak berjalan
    # atas kondisi data yang sah, dan penjaga yang selalu merah dihapus orang
    # berikutnya persis seperti gerbang yang tidak pernah merah.
    # 429 dan 408 IKUT, dan itu bukan kehati-hatian melainkan pengalaman: batas
    # laju API 120 permintaan/menit per kredensial (config/erp.php) dilampaui
    # oleh sapuan ini sendiri pada layar DOKUMEN, yang menembak beberapa endpoint
    # per halaman. Sebelas rute karena itu tercatat 12 elemen dan "bersih" —
    # halaman yang isinya sebuah alert "Batas laju API terlampaui". Penjaga yang
    # hanya menonton 5xx tidak melihatnya sama sekali.
    http_gagal = []
    pg.on("response", lambda r: http_gagal.append(f"{r.status} {r.url[-60:]}")
          if r.status >= 500 or r.status in (408, 429) else None)
    pg.on("requestfailed", lambda r: http_gagal.append(f"GAGAL {r.url[-60:]}"))
    if media == "print": pg.emulate_media(media="print")
    def ukur(r):
        try:
            # Tandai isi LAMA sebelum pindah. Tanpa penanda ini penjaga di bawah
            # ("`.main` berisi") benar SEKETIKA — karena DOM rute SEBELUMNYA
            # masih terpasang — dan sapuan bisa mengukur layar yang sama dua
            # kali sambil menamainya dua rute berbeda. Ditemukan 19 Sep 2026 di
            # probe A/B paket ini, yang melaporkan tinggi `.main` yang sama
            # persis untuk rute yang jelas berbeda. Sapuan enam putaran yang
            # sudah berjalan TIDAK terkena — dibuktikan dengan membandingkan h1
            # tiap rute antara dua putaran berpecahan berbeda: 143 dari 143
            # sama, yang mustahil bila salah satunya mengukur isi basi — tetapi
            # penjaga yang hanya SELAMAT dari sebuah jebakan belum menutupnya.
            pg.evaluate("() => { const m = document.querySelector('.main'); if (m) m.innerHTML = '<i data-basi></i>'; }")
            pg.goto(BASE + "/app/" + r, wait_until="networkidle", timeout=25000)
            # TUNGGU SAMPAI TERGAMBAR, jangan tunggu sekian milidetik.
            # Putaran pertama memakai `wait_for_timeout(1400)` dan melaporkan 48
            # rute "kosong" di tema gelap lawan 5 di terang — seluruhnya artefak:
            # dengan enam proses peramban berebut CPU, 1400 ms kadang habis
            # sebelum SPA menggambar. Layar yang sama, sendirian dengan jeda 4
            # detik, menggambar 204/47/214 elemen di KEDUA tema. Angka "nol
            # kegagalan kontras" dari putaran itu karena itu mengukur halaman
            # KOSONG, bukan halaman yang lolos.
            # Anggaran waktu yang MURAH HATI, dan disengaja: yang mahal bukan
            # menunggu melainkan salah menyimpulkan. Terukur 19 Sep 2026 dengan
            # EMPAT pecahan di mesin ini: 35 dari 143 rute tidak tergambar dalam
            # 24 s — bukan karena aplikasinya, sebab rute yang sama sendirian
            # menggambar 85/55/182 elemen dan server tidak mencatat satu 500 pun,
            # melainkan karena empat Chromium berebut CPU dengan empat pekerja
            # PHP. Dengan DUA pecahan dan anggaran ini mereka tergambar.
            # `belum_siap` TETAP dilaporkan, tidak pernah ditelan.
            siap = False
            for _ in range(40):
                if pg.evaluate("() => !document.querySelector('[data-basi]') "
                               "&& !!document.querySelector('.main') "
                               "&& document.querySelectorAll('.main *').length > 8 "
                               "&& !document.querySelector('.main .skeleton') "
                               "&& (window.__inflight || 0) === 0"):
                    siap = True; break
                pg.wait_for_timeout(500)
            # …dan TENANG, bukan hanya tergambar: layar ini menggambar
            # kerangkanya lebih dulu lalu memuat datanya. Tunggu jumlah elemen
            # berhenti berubah LIMA pemeriksaan berturut-turut, dan hitung
            # permintaan yang masih terbang sebagai "belum tenang" — kerangka
            # yang menunggu fetch diam sempurna, dan lima pemeriksaan atas
            # angka yang diam tetap lulus tanpa penghitung itu.
            tenang = False; last = -1; sama = 0
            for _ in range(60):
                n = pg.evaluate("() => document.querySelectorAll('.main *').length "
                                "+ 100000 * ((window.__inflight || 0) + "
                                "(document.querySelector('.main .skeleton') ? 1 : 0))")
                sama = sama + 1 if n == last else 0
                last = n
                if sama >= 5 and n < 100000: tenang = True; break
                pg.wait_for_timeout(400)
            siap = siap and tenang
            out = pg.evaluate(PROBE)
            out["siap"] = siap
            out["http_gagal"] = list(http_gagal)
            return out
        except Exception as ex:
            return {"ERROR": str(ex)[:120]}

    for r in mine:
        # Sampai TIGA kali, dan hanya untuk kegagalan INSTRUMEN/SERVER: cangkang
        # hilang, kehabisan waktu kesiapan, 5xx, atau lemparan. **BUKAN** untuk
        # `.alert.error`: aplikasi ini memakai alert yang sama untuk kalimat yang
        # JUJUR dan benar — "Saldo GL Bank BCA Operasional negatif", "Belum ada
        # rekening koran yang diimpor". Versi pertama mengulang atas keduanya
        # dan MERUSAK pengukurannya sendiri: percobaan 1 mengukur `#/dashboard`
        # dengan benar (420 elemen, siap), lalu percobaan 2 dijalankan — dan
        # `goto` ke hash yang SAMA tidak menggambar ulang apa pun, jadi `.main`
        # tetap berisi penanda basi dan hasil 1-elemen itulah yang tersimpan.
        # Enam putaran melaporkan `#/dashboard` "belum siap" karena itu.
        #
        # Dua penjaga lahir dari situ: percobaan ulang memaksa MUAT PENUH lewat
        # about:blank, dan hasil TERBAIK yang disimpan, tidak pernah yang terakhir.
        terbaik = None
        for percobaan in range(3):
            if percobaan: pg.goto("about:blank")
            http_gagal.clear()
            out = ukur(r)
            baik = (not out.get("ERROR") and out.get("ada_main") and out.get("siap")
                    and not out.get("http_gagal"))
            if terbaik is None or out.get("n", 0) > terbaik.get("n", 0):
                terbaik = out
            if baik:
                terbaik = out
                break
            if percobaan < 2: pg.wait_for_timeout(2000 + 3000 * percobaan)
        terbaik["percobaan"] = percobaan + 1
        results[r] = terbaik
    ctx.close(); br.close()
    pathlib.Path(f"{OUT}/sweep-{label}-{SHARD}.json").write_text(json.dumps(results, indent=1))
    # Cangkang yang tidak pernah terpasang tidak punya `.main` sama sekali, dan
    # probe lalu melaporkan n=0 dengan NOL temuan di setiap dimensi — sapuan
    # yang paling bersih adalah sapuan yang tidak melihat apa pun. Terjadi
    # 19 Sep 2026: putaran 390 melaporkan 143 rute bersih, ketiga-tiganya
    # dengan `.main` yang tidak ada. Ini FATAL, bukan catatan kaki.
    tanpa_main = [r for r, v in results.items() if not v.get("ERROR") and not v.get("ada_main")]
    gagal_layar = [r for r, v in results.items() if v.get("galat_layar")]
    gagal_http = [r for r, v in results.items() if v.get("http_gagal")]
    if gagal_http:
        print(f"  FATAL {label}/{SHARD}: {len(gagal_http)} rute masih menjawab 5xx/gagal sesudah tiga "
              f"percobaan — {gagal_http[:4]}; halaman tanpa isi tidak punya apa pun untuk gagal, "
              "jadi putaran ini akan terbaca bersih tanpa alasan")
        sys.exit(4)
    if len(tanpa_main) > max(2, len(results) // 10):
        print(f"  FATAL {label}/{SHARD}: {len(tanpa_main)} dari {len(results)} rute tanpa `.main` — "
              f"cangkang tidak pernah terpasang; putaran ini TIDAK mengukur apa pun")
        sys.exit(3)
    kosong = [r for r, v in results.items() if not v.get("ERROR") and v.get("n", 0) < 20]
    err = [r for r, v in results.items() if v.get("ERROR")]
    tab = sum(len(v.get("tabrakan", [])) for v in results.values())
    kon = sum(len(v.get("kontras", [])) for v in results.values())
    mel = sum(len(v.get("meluber", [])) for v in results.values())
    sas = sum(len(v.get("sasaran", [])) for v in results.values())
    gul = [r for r, v in results.items() if v.get("gulirX")]
    print(f"  {label:<16} rute={len(results):<4} galat={len(err):<3} kosong={len(kosong):<3} "
          f"pesan_galat={len(gagal_layar):<3} "
          f"tabrakan={tab:<4} kontras={kon:<5} meluber={mel:<4} sasaran<36={sas:<4} gulirX={len(gul)}")
    return results

PASSES = [
    ("1440-terang",  1440, 1000, "light", "screen", False),
    ("390-terang",    390,  844, "light", "screen", True),
    ("1440-gelap",   1440, 1000, "dark",  "screen", False),
    ("cetak-1440",   1440, 1000, "light", "print",  False),
    ("768-terang",    768,  900, "light", "screen", False),
    ("1024-terang",  1024,  800, "light", "screen", False),
]
want = sys.argv[1:] or [p[0] for p in PASSES]
with sync_playwright() as pw:
    state = cached_state(pw, BASE)
    print(f"  masuk sekali, keadaan sesi dipakai ulang · {len(ALL)} rute")
    # Penjaga sesi: satu halaman uji harus benar-benar MASUK. Tanpa ini sapuan
    # yang seluruhnya tertolak otentikasi melaporkan "nol temuan".
    # Tiga percobaan, bukan satu: penjaga ini pernah menjatuhkan seluruh
    # putaran 768 px karena satu dasbor lambat di bawah tiga peramban serentak,
    # dan putaran itu lalu TIDAK ADA di ringkasan sementara lima lainnya hadir.
    n = 0; masuk = False
    for percobaan in range(3):
        br = pw.chromium.launch()
        ctx = br.new_context(viewport={"width": 1440, "height": 900}, storage_state=state)
        pg = ctx.new_page()
        pg.goto(BASE + "/app/#/dashboard", wait_until="networkidle"); pg.wait_for_timeout(3000 + 3000 * percobaan)
        n = pg.evaluate("() => document.querySelectorAll('.main *').length")
        masuk = pg.evaluate("() => !document.querySelector('input[type=password]')")
        ctx.close(); br.close()
        if masuk and n >= 20: break
    if not masuk or n < 20:
        print(f"  SESI TIDAK BERLAKU (dashboard n={n}, masuk={masuk}) — hapus {STATE_FILE} dan ulangi")
        sys.exit(2)

    for label, w, h, scheme, media, touch in PASSES:
        if label in want:
            run_pass(pw, state, label, w, h, scheme, media, touch, ALL)
