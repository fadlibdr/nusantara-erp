import json, os, re, time, sqlite3, struct, zlib, base64, traceback, urllib.request
from datetime import date, timedelta
from playwright.sync_api import sync_playwright

# Jalur dibaca dari env dengan literal asli sebagai bawaan: harness ini ditulis di
# sandbox /home/claude (2 Sep 2026) dan harus tetap jalan tanpa ubahan di sana,
# sedangkan di mesin lain aplikasi dilayani dari basis data coretan (S4 menghapus
# token langsung di berkas sqlite, jadi DB harus berkas yang sama dengan yang
# dilayani php -S — bukan database/database.sqlite berisi data demo hidup).
#   ERP_BASE   asal server, tanpa garis miring akhir (bawaan http://127.0.0.1:8000)
#   ERP_DB     berkas sqlite yang dilayani server itu
#   UXTEST_OUT folder results.json + tangkapan layar
ORIGIN = os.environ.get("ERP_BASE", "http://127.0.0.1:8000").rstrip("/")
BASE = ORIGIN + "/app/"
API = ORIGIN + "/api/"
SPA_EVIDENCE = os.path.dirname(os.path.abspath(__file__))
DB = os.environ.get("ERP_DB", "/home/claude/nusantara-erp/database/database.sqlite")
OUT = os.environ.get("UXTEST_OUT", "/home/claude/uxtest")
R = {}          # results
CLICKS = [0]    # click counter for the current scenario
# Skenario yang JATUH (ERROR atau ok:false). Dibaca di akhir: sebuah harness yang selalu
# keluar dengan status 0 tidak bisa dipakai gerbang apa pun (verifikasi kedua P1-D).
FAILED = []

def api(path, token, method="GET", body=None):
    req = urllib.request.Request(API + path, method=method, headers={
        "Authorization": f"Bearer {token}", "Accept": "application/json", "Content-Type": "application/json"})
    data = json.dumps(body).encode() if body is not None else None
    try:
        with urllib.request.urlopen(req, data=data, timeout=30) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "{}")

def token_for(email):
    s, d = api("iam/auth/login", "", "POST", {"email": email, "password": "password"})
    return d["data"]["token"]

def click(page, sel, **kw):
    CLICKS[0] += 1
    page.click(sel, **kw)

def login(page, email, onboarding="decide"):
    """Masuk lewat halaman masuk sungguhan.

    `onboarding="decide"` (bawaan): keputusan onboarding pengguna ini ditetapkan di sqlite SEBELUM
    masuk. Pada salinan DB hidup semua users.onboarding_status masih NULL, dan app.js membuka panduan
    sendiri (maybeShowOnboarding) — langkah 1 memanggil visit(dashboard) → navigate('dashboard'),
    jadi layar yang diukur skenario bisa berganti menjadi dasbor DI TENGAH pengukuran. Diukur
    6 Sep 2026: S8 pada DB yang belum memutuskan melaporkan "ok" dengan btn_sm_height 28 dan
    page_head_buttons ['Muat ulang'] — angka DASBOR, bukan daftar PO (0 / ['Muat ulang','Tambah PO']).
    Keputusannya dipasang di satu tempat ini, bukan ditaburkan per skenario: skenario berikutnya yang
    ditulis orang lain ikut aman tanpa harus tahu balapan ini ada.
    `onboarding=None` hanya untuk S18/S19 — merekalah yang MENGUJI panduan itu dan mulai dari NULL.
    Satu-satunya skenario lain yang tidak lewat sini adalah S10: seluruhnya API lewat token_for(),
    tanpa sesi peramban, jadi tidak ada panduan yang bisa terbuka.
    """
    if onboarding == "decide":
        decide_onboarding(email)
    page.goto(BASE)
    page.wait_for_selector("input[type=email]", timeout=15000)
    page.fill("input[type=email]", email)
    page.fill("input[type=password]", "password")
    # iam/auth/login dibatasi throttle per IP; S5 memasukkan 11 peran dalam ~30 s dan satu di antaranya
    # kena 429 (diamati 4 Sep 2026: teknisi/sales/finance bergantian, POST-nya tidak pernah sampai ke
    # log php -S, toast-nya sudah lenyap saat 15 s habis). Ditunggu sesuai Retry-After lalu diklik
    # lagi — yang dilakukan orang juga; tidak masuk hitungan klik skenario.
    # Enam percobaan, bukan tiga (P1-C, 6 Sep 2026): S22 + S22m + S22r berurutan memasukkan 23 sesi,
    # dan tiga percobaan habis di tengah S22m — skenarionya mati di wait_for_selector('nav.nav')
    # dengan sebab yang tidak ada hubungannya dengan yang diujinya.
    for _ in range(6):
        with page.expect_response(lambda r: "iam/auth/login" in r.url, timeout=15000) as info:
            page.click("button[type=submit]")
        if info.value.status != 429:
            break
        wait = int(info.value.headers.get("retry-after", "60")) + 1
        print(f"  login {email}: 429 throttle, menunggu {wait} s")
        page.wait_for_timeout(wait * 1000)
    page.wait_for_selector("nav.nav", timeout=15000)
    page.wait_for_timeout(1200)

def toasts(page):
    return page.evaluate("() => [...document.querySelectorAll('.toast')].map(t => t.innerText.trim())")

def nav_click(page, href):
    """Klik tautan sidebar. Sejak T2.5 grup tertutup bawaan (kecuali Ringkasan dan grup rute aktif),
    jadi pada profil baru grupnya dibuka dulu — dan klik itu DIHITUNG: itulah yang dilakukan orang
    yang pertama kali masuk. Setelah preferensinya tersimpan (localStorage) klik ini hilang.
    Grup pintasan (Favorit/Terakhir dibuka, data-kind) dilewati: pada profil baru keduanya kosong."""
    group = f"nav.nav .nav-group:not([data-kind]):has(a[href='{href}'])"
    opened = False
    if page.locator(group).count() and page.locator(group).first.get_attribute("data-open") == "false":
        click(page, f"{group} > button"); page.wait_for_timeout(150); opened = True
    click(page, f"nav.nav a[href='{href}']")
    return opened

def assert_screen(page, route, h1=None):
    """Layar yang DIUKUR harus layar yang dimaksud — kalau tidak, skenarionya JATUH, bukan mencatat
    angka halaman lain sebagai "ok" (verifikasi P1-B putaran 2, 6 Sep 2026: S8 mencatat angka dasbor).
    Dipakai pada skenario yang menuju sebuah rute lalu mengukur sesuatu yang juga ADA di dasbor
    (tombol .btn.sm, lencana, tinggi baris); yang menunggu `h1:has-text('<kode>')` sudah jatuh sendiri."""
    got = page.evaluate("""() => ({ hash: location.hash, h1: (document.querySelector('.page-head h1')||{}).innerText || null,
        dock: !!document.querySelector('.onboarding-dock') })""")
    if got["hash"] != route or (h1 is not None and (not got["h1"] or h1 not in got["h1"])):
        raise AssertionError(f"layar salah: diminta {route}" + (f" (h1 memuat {h1!r})" if h1 else "")
                             + f", terukur {got['hash']} h1={got['h1']!r}"
                             + (" — panel onboarding terbuka dan memindah halaman" if got["dock"] else ""))
    return got

def scenario(name):
    def deco(fn):
        def wrapper(*a, **k):
            CLICKS[0] = 0
            t0 = time.time()
            try:
                R[name] = fn(*a, **k) or {}
            except Exception as e:
                R[name] = {"ERROR": str(e)[:400], "trace": traceback.format_exc()[-600:]}
            R[name]["_ms"] = int((time.time() - t0) * 1000)
            R[name]["_clicks"] = CLICKS[0]
            # SKIPPED dicetak apa adanya: sebuah skenario yang tidak menemukan fixture-nya bukan "ok"
            # (verifikasi P1-B putaran 3, 6 Sep 2026 — S16 pada salinan DB hidup).
            #
            # …dan `ok: False` juga bukan "ok". Sampai verifikasi kedua P1-D baris ini hanya melihat
            # ERROR dan SKIPPED, jadi sebuah skenario yang MENJATUHKAN syaratnya sendiri tetap
            # tercetak hijau: terukur pada S23_setup_drawer, yang mencetak "ok" sementara
            # results.json memuat "ok": false (stored_preference null, tabel preferensi belum ada di
            # salinan DB). Laporan paket yang menulis "empat bagian, semuanya hijau" bersandar pada
            # baris ini, jadi baris inilah yang harus jujur.
            failed = R[name].get("ok") is False
            state = (
                R[name].get("ERROR")
                or (f"SKIPPED: {R[name]['SKIPPED']}" if "SKIPPED" in R[name] else None)
                or ("GAGAL: " + ", ".join(R[name].get("failed_checks") or ["syarat ok"]) if failed else "ok")
            )
            print(f"[{name}] {state} {R[name]['_ms']}ms clicks={CLICKS[0]}")
            if R[name].get("ERROR") or failed:
                FAILED.append(name)
        # Nama PANJANG dibawa pada fungsinya supaya runner bisa menerimanya
        # sebagai alias. Nama itulah yang tercetak di setiap laporan dan
        # menjadi kunci di results-*.json; sampai 9 Sep 2026 ia TIDAK bisa
        # dipakai memanggil skenarionya, dan panggilan dengan nama itu
        # dilewati diam-diam (lihat penjaga di runner).
        wrapper.scenario_name = name
        return wrapper
    return deco

# Panel catatan persetujuan inline (T2.3): <details class="action-note"> di dalam .page-head .actions,
# summary = pelipatnya, textarea baru terlihat setelah dibuka. null bila build ini belum memilikinya.
# checkVisibility(), bukan offsetParent: isi <details> tertutup dirender Chromium dengan
# content-visibility: hidden, jadi offsetParent-nya tetap ada (terukur 4 Sep 2026: lebar 22 px).
NOTE_PANEL = """() => { const d=document.querySelector('.page-head .actions details.action-note'); if (!d) return null;
    const t=d.querySelector('textarea'); return { toggle: d.querySelector('summary').innerText.trim(), open: d.open,
    textarea_visible: !!(t && t.checkVisibility()), label: (d.querySelector('.field > label')||{}).innerText || null,
    help: (d.querySelector('.field .help')||{}).innerText || null, width: t ? Math.round(t.getBoundingClientRect().width) : null,
    focused: !!t && document.activeElement === t } }"""

# --------------------------------------------------------------- scenarios

# Kartu "Menunggu persetujuan Anda" + tautan Tugas Saya + izin `.approve` yang dipegang, dibaca dari
# sesi peramban sendiri (localStorage nusantara_erp_user) — bukan lewat token_for(): iam/auth/login
# dibatasi 10 kali per menit per IP, dan S10 + S1 + S2 + S3 + S4 sudah memakai 9 di menit pertama;
# masuk ke-11 (masuk ulang S4, tanpa ulang otomatis) akan kena 429.
CARD_AND_LINK = """() => ({ approvals_card: [...document.querySelectorAll('.card')].some(c => /Menunggu persetujuan/.test(c.innerText)),
    tugas_link: !!document.querySelector("nav.nav a[href='#/tugas']"),
    cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText.replace(/\\s*\\(\\d+\\)/, '')),
    approve_perms: (JSON.parse(localStorage.getItem('nusantara_erp_user') || '{}').permissions || []).filter(p => p.endsWith('.approve')).sort() })"""

def after_login(reqs):
    """Permintaan /api/ sesudah POST iam/auth/login terakhir = satu kali buka dasbor (iam/auth/me
    penyegaran izin ikut dihitung; halaman masuk sendiri hanya memanggil demo-accounts sebelumnya)."""
    i = max((k for k, r in enumerate(reqs) if r == "iam/auth/login"), default=-1)
    return reqs[i + 1:]

@scenario("S1_inbox_truth")
def s1(pg):
    """Dashboard approval card vs. server truth for the direktur — then the same dashboard for a role
    that approves nothing (T2.11)."""
    reqs = []
    pg.on("request", lambda r: reqs.append(r.url.split("/api/")[-1]) if "/api/" in r.url else None)
    tok = token_for("direktur@nusantara.test")
    s, me = api("iam/auth/me", tok)
    perms = set(me.get("data", {}).get("permissions", []))
    types = ["crm/quotations","crm/contract-change-orders","estimation/boqs","estimation/cost-budgets","projects/bast",
             "projects/work-permits","projects/overtime-permits","projects/gate-passes","engineering/ipp","quality/inspections",
             "projects/progress-measurements","procurement/purchase-requisitions","procurement/purchase-orders",
             "procurement/award-decisions","procurement/work-orders","inventory/stock-adjustments","subcontract/subcontracts",
             "subcontract/addenda","subcontract/progress-claims","subcontract/handovers","subcontract/labor-contracts",
             "subcontract/labor-claims","finance/ar-invoices","finance/ap-bills","finance/payments","hr/payroll-runs","hr/leave-requests"]
    server = {}
    for t in types:
        s, d = api(f"{t}?status=submitted&per_page=50", tok)
        n = (d.get("meta") or {}).get("total", len(d.get("data") or [])) if s == 200 else f"HTTP {s}"
        if n not in (0, "HTTP 403"):
            server[t] = n
    login(pg, "direktur@nusantara.test")
    pg.wait_for_timeout(1500)
    card = pg.evaluate("""() => { const c=[...document.querySelectorAll('.card')].find(c=>/Menunggu persetujuan/.test(c.innerText));
        return { title: c.querySelector('h2').innerText, rows: [...c.querySelectorAll('tbody tr')].map(r => r.innerText.split('\\n')[0]),
                 width: c.getBoundingClientRect().width, height: c.getBoundingClientRect().height,
                 seeAll: !!c.querySelector('.card-foot'), rowHeights: [...c.querySelectorAll('tbody tr')].map(r=>Math.round(r.getBoundingClientRect().height)) }; }""")
    out = {"direktur_approve_perms": sorted(p for p in perms if p.endswith(".approve")),
           "server_submitted_visible_to_direktur": server, "dashboard_card": card}
    calls = after_login(reqs)
    out["direktur"] = {**pg.evaluate(CARD_AND_LINK), "dashboard_api_calls": len(calls), "dashboard_api_sample": calls[:24]}
    # T2.11 — peran tanpa satu pun izin `.approve`: GET core/inbox menyaring per `<awalan>.approve`
    # (ApprovalQueue::pending), jadi kotaknya SELALU kosong. Diukur 2 Sep 2026 (S5 › cards): kartu
    # tergambar untuk 11/11 peran demo, 8 di antaranya tidak menyetujui apa pun. warehouse dipilih
    # karena izinnya inv.* tanpa approve (RoleSeeder) dan tidak dipakai skenario lain.
    reqs.clear()
    pg.context.clear_cookies(); pg.goto(BASE); pg.evaluate("() => localStorage.clear()")
    login(pg, "warehouse@nusantara.test")
    pg.wait_for_timeout(1500)
    calls = after_login(reqs)
    out["warehouse"] = {**pg.evaluate(CARD_AND_LINK), "dashboard_api_calls": len(calls), "dashboard_api_sample": calls[:24]}
    pg.screenshot(path=f"{OUT}/s1-warehouse-dashboard.png")
    return out

@scenario("S2_approve_loop")
def s2(pg):
    """Approve the seeded PR from the dashboard, counting every click and API call."""
    reqs = []
    pg.on("request", lambda r: reqs.append(r.url.split("/api/")[-1]) if "/api/" in r.url else None)
    login(pg, "direktur@nusantara.test")
    pg.wait_for_timeout(1500)
    reqs.clear()
    t0 = time.time()
    first_code = pg.evaluate("() => { const c=[...document.querySelectorAll('.card')].find(c=>/Menunggu persetujuan/.test(c.innerText)); return c.querySelector('tbody tr').innerText.split('\\n')[0] }")
    click(pg, f"tr.clickable:has-text('{first_code}')")
    pg.wait_for_selector(f".page-head h1:has-text('{first_code}')", timeout=15000)
    pg.wait_for_timeout(800)
    t_detail = int((time.time() - t0) * 1000)
    bar = pg.evaluate("() => [...document.querySelectorAll('.page-head .actions button')].map(b => (b.innerText.trim() || b.title))")
    has_ubah = "Ubah" in bar
    status_text = pg.evaluate("() => (document.querySelector('.page-head .badge')||{}).innerText")
    explain = pg.evaluate("() => { const h=document.querySelector('.page-head'); const n=h.nextElementSibling; return n ? n.innerText.slice(0,200) : null }")
    pg.screenshot(path=f"{OUT}/s2-detail.png")
    # T2.3: catatan persetujuan dilipat di bilah aksi (details/summary), bukan modal. Dibaca
    # SEBELUM Setujui — sesudahnya halaman dimuat ulang dan panelnya hilang bersama tombolnya.
    note_inline = pg.evaluate(NOTE_PANEL)
    click(pg, ".page-head .actions button:has-text('Setujui')")
    # Setujui memutus langsung sejak T2.3; modal catatan hanya ada pada build lama. Tunggu mana
    # yang datang lebih dulu — modal, atau toast keputusan — dan klik Setujui di modal HANYA bila
    # modalnya benar-benar muncul, supaya hitungan klik jujur pada kedua build (2 Sep 2026: 3 klik
    # per dokumen, satu di antaranya Setujui kedua di modal itu).
    pg.wait_for_selector(".modal, .toast:has-text('disetujui'), .toast.err", timeout=10000)
    modal_opened = bool(pg.locator(".modal").count())
    modal_fields = modal_buttons = None
    if modal_opened:
        modal_fields = pg.evaluate("() => [...document.querySelectorAll('.modal .field > label')].map(l => l.innerText.trim())")
        modal_buttons = pg.evaluate("() => [...document.querySelectorAll('.modal .modal-foot button')].map(b => b.innerText.trim())")
        pg.screenshot(path=f"{OUT}/s2-modal.png")
        click(pg, ".modal .modal-foot button:has-text('Setujui')")
    pg.wait_for_selector(".toast", timeout=15000)
    pg.wait_for_timeout(600)
    t_done = int((time.time() - t0) * 1000)
    toast = toasts(pg)
    after = pg.evaluate("() => ({ url: location.hash, status: (document.querySelector('.page-head .badge')||{}).innerText, nextOffer: [...document.querySelectorAll('.toast')].map(t=>t.innerText.slice(0,140)), strip: (document.querySelector('.page-head + .alert')||{}).innerText })")
    if pg.locator(".toast button:has-text('Buka')").count():
        click(pg, ".toast button:has-text('Buka')"); pg.wait_for_timeout(1500)
        after["opened_next"] = pg.evaluate("() => (document.querySelector('.page-head h1')||{}).innerText")
    pg.screenshot(path=f"{OUT}/s2-after.png")
    click(pg, ".page-head .actions button[title='Kembali']")
    pg.wait_for_timeout(2500)
    api_calls = len(reqs)
    # T2.6 — bilah aksi pada PO: dokumen dengan keluaran terbanyak (PDF dompdf + formulir rumah
    # Pesanan Pembelian + XLSX-nya). Diukur SESUDAH putaran persetujuan di atas dan dengan klik yang
    # dihitung terpisah (po_bar.clicks), supaya _clicks dan api_calls_detail_to_back tetap angka
    # putaran itu (T2.3: 2 klik per dokumen).
    po_bar = po_action_bar(pg)
    return {"detail_ms": t_detail, "approve_total_ms": t_done, "action_bar": bar, "ubah_visible_on_submitted": has_ubah,
            "status_badge": status_text, "explanation_under_title": explain, "approve_modal_opened": modal_opened,
            "approve_modal_fields": modal_fields, "approve_modal_buttons": modal_buttons, "approve_note_inline": note_inline,
            "toast": toast, "after": after,
            "api_calls_detail_to_back": api_calls, "api_calls_sample": reqs[:40], "po_bar": po_bar}

BAR = "() => [...document.querySelectorAll('.page-head .actions button')].map(b => (b.innerText.trim() || b.title))"

def read_action_bar(page, shot):
    """Tombol di .page-head .actions (rumus yang sama dengan action_bar S2), tombol .primary, isi tiap
    zona bila bilahnya berzona, lalu menu Cetak bila ada: dibuka dengan satu klik (dihitung di
    `clicks`), isinya dibaca, Escape harus menutupnya dan mengembalikan fokus ke tombolnya."""
    out = {"action_bar": page.evaluate(BAR),
           "primary": page.evaluate("() => [...document.querySelectorAll('.page-head .actions button.primary')].map(b => b.innerText.trim() || b.title)"),
           "zones": page.evaluate("() => [...document.querySelectorAll('.page-head .actions .zone')].map(z => [...z.querySelectorAll('button')].map(b => b.innerText.trim() || b.title))"),
           "clicks": 0}
    page.screenshot(path=f"{OUT}/{shot}.png")
    trigger = ".page-head .actions button[aria-haspopup='menu']"
    if page.locator(trigger).count():
        page.click(trigger); out["clicks"] += 1; page.wait_for_timeout(250)
        out["menu"] = page.evaluate("""() => { const m=document.querySelector('[role=menu]'); if (!m) return null;
            const items=[...m.querySelectorAll('[role=menuitem]')];
            return { items: items.map(b => b.innerText.trim()), focused_first: document.activeElement === items[0],
                     expanded: document.querySelector(".page-head .actions button[aria-haspopup='menu']").getAttribute('aria-expanded') } }""")
        page.screenshot(path=f"{OUT}/{shot}-menu.png")
        page.keyboard.press("Escape"); page.wait_for_timeout(150)
        out["after_escape"] = page.evaluate("""() => ({ menu_open: !!document.querySelector('[role=menu]'),
            focus_on_trigger: document.activeElement === document.querySelector(".page-head .actions button[aria-haspopup='menu']"),
            bar_buttons: document.querySelectorAll('.page-head .actions button').length })""")
    return out

def po_action_bar(pg):
    """T2.6: PO dibuat lewat API sebagai procurement, lalu dibaca DUA kali. (a) Sebagai procurement pada
    drafnya, di konteks peramban terpisah supaya sesi direktur di `pg` tidak tersentuh: bilah terpenuh —
    2 Sep 2026: Kembali · Cetak halaman · PDF · Cetak Pesanan Pembelian · XLSX · Ubah · Ajukan = 7
    tombol setara. (b) Diajukan lewat API (mendarat paling akhir di antrean — pengajuan terbaru — jadi
    tidak menggeser dokumen yang diambil S2/S13) dan dibuka sebagai direktur yang masih masuk — prc.view
    ada padanya, jadi formulir rumahnya ikut: Kembali · Cetak halaman · PDF · Cetak Pesanan Pembelian ·
    XLSX · Setujui · Tolak, 7 lagi. Catatan: sebelum katalog cetak diperbaiki (T2.6, printcatalog.js
    membaca .data pada array yang sudah dibuka api.get) kedua bilah itu 5 — tombol formulir rumah dan
    XLSX tidak pernah tergambar, kolom "Sesudah" 2 Sep 2026 pun tanpa keduanya."""
    tok = token_for("procurement@nusantara.test")
    s, d = api("procurement/vendors?status=active&per_page=20", tok)
    vendor = next((v for v in d["data"] if v.get("vendor_type") in (None, "supplier")), None)
    if vendor is None:   # StopIteration menyembunyikan sebabnya (verifikasi P1-B putaran 3)
        raise AssertionError(f"tidak ada vendor bertipe supplier/kosong di antara {len(d['data'])} vendor — "
                             "fixture RFQ/PR tidak bisa dibuat pada basis data ini")
    s, d = api("procurement/purchase-orders", tok, "POST", {"vendor_id": vendor["id"], "order_date": "2026-09-02",
               "expected_date": "2026-09-16",  # wajib sejak T3.5
               "pr_bypass_reason": "UJI-UX — pembelian langsung tanpa PR",  # wajib sejak T3.8 (PO tanpa PR)
               "items": [{"description": "UJI-UX bilah aksi", "qty": 1, "unit": "unit", "unit_price": 2500000}]})
    po_id, po_code = d["data"]["id"], d["data"]["code"]
    out = {"po": po_code}
    ctx = pg.context.browser.new_context(viewport={"width": 1440, "height": 900})
    try:
        p2 = ctx.new_page()
        login(p2, "procurement@nusantara.test")
        p2.goto(BASE + f"#/d/procurement/purchase-orders/{po_id}")
        p2.wait_for_selector(f".page-head h1:has-text('{po_code}')", timeout=15000); p2.wait_for_timeout(800)
        out["as_procurement_draft"] = read_action_bar(p2, "s2-po-bar-procurement")
    finally:
        ctx.close()
    s, d = api(f"procurement/purchase-orders/{po_id}/submit", tok, "POST", {})
    out["submit_http"] = s
    pg.goto(BASE + f"#/d/procurement/purchase-orders/{po_id}")
    pg.wait_for_selector(f".page-head h1:has-text('{po_code}')", timeout=15000); pg.wait_for_timeout(800)
    out["as_direktur_submitted"] = read_action_bar(pg, "s2-po-bar-direktur")
    return out

@scenario("S3_create_po")
def s3(pg):
    """Procurement creates a 2-line PO through the real form; captures validation text and submit toast."""
    reqs = []
    pg.on("request", lambda r: reqs.append(r.url.split("/api/")[-1]) if "/api/" in r.url else None)
    login(pg, "procurement@nusantara.test")
    nav_group_opened = nav_click(pg, "#/r/procurement/purchase-orders")
    pg.wait_for_selector(".page-head h1", timeout=15000)
    pg.wait_for_timeout(1200)
    t0 = time.time()
    click(pg, ".page-head .actions button:has-text('Tambah')")
    pg.wait_for_selector(".modal", timeout=10000)
    pg.wait_for_timeout(1500)
    form = pg.evaluate("""() => { const m=document.querySelector('.modal'); return {
        fields: [...m.querySelectorAll('.field > label')].map(l=>l.innerText.trim()),
        lineCols: [...m.querySelectorAll('table.lines th')].map(t=>t.innerText.trim()),
        width: m.getBoundingClientRect().width, bodyScroll: m.querySelector('.modal-body').scrollHeight,
        bodyClient: m.querySelector('.modal-body').clientHeight,
        linesTop: (m.querySelector('table.lines')||{getBoundingClientRect:()=>({top:null})}).getBoundingClientRect().top,
        foot: [...m.querySelectorAll('.modal-foot button')].map(b=>b.innerText.trim()) } }""")
    pg.screenshot(path=f"{OUT}/s3-form-empty.png")
    # 1) Save empty -> client-side validation
    click(pg, ".modal .modal-foot button:has-text('Simpan')")
    pg.wait_for_timeout(700)
    client_errors = pg.evaluate("() => [...document.querySelectorAll('.modal .field .err, .modal td .err')].map(e=>e.innerText.trim()).filter(Boolean)")
    pg.screenshot(path=f"{OUT}/s3-client-errors.png")
    # 2) Fill header: vendor via combobox, date, then lines
    def pick_combo(label_text, typed):
        f = pg.locator(".modal .field", has=pg.locator("label", has_text=label_text)).first
        inp = f.locator("input.combo-input, select").first
        tag = inp.evaluate("e => e.tagName")
        if tag == "SELECT":
            inp.select_option(index=1); CLICKS[0] += 1
        else:
            inp.click(); CLICKS[0] += 1
            inp.type(typed, delay=20)
            pg.wait_for_selector(".combo-pop .combo-opt", timeout=8000)
            pg.keyboard.press("ArrowDown"); pg.keyboard.press("Enter")
    pick_combo("Vendor", "PT")
    pick_combo("Proyek", "Gedung")
    # dates
    for lab in ["Tanggal pesanan", "Tanggal", "Tanggal kirim", "Tanggal terima"]:
        loc = pg.locator(".modal .field", has=pg.locator("label", has_text=lab))
        if loc.count():
            d = loc.first.locator("input[type=date]")
            if d.count() and not d.first.input_value():
                d.first.fill("2026-09-02")
    # "Perkiraan kirim" wajib sejak T3.5 (ANALISIS-PROSES D1) — tanpa isian ini Simpan berhenti di klien. Diisi
    # 14 hari dari hari ini, bukan 2026-09-02: Tanggal PO defaultToday dan server memeriksa after_or_equal begitu
    # tanggalnya ada (4 Sep 2026: "Perkiraan kirim harus pada atau setelah Tanggal PO." dengan tanggal tetap).
    pg.locator(".modal .field", has=pg.locator("label", has_text="Perkiraan kirim")).first.locator("input[type=date]").first.fill(
        time.strftime("%Y-%m-%d", time.localtime(time.time() + 14 * 86400)))
    # "Alasan tanpa PR" wajib sejak T3.8 (ANALISIS-PROSES E3): skenario ini tidak memilih PR, jadi field-nya tampil
    # (visibleWhen) dan Simpan berhenti di klien tanpa isian ini. Sebuah isian, bukan klik.
    pg.locator(".modal .field", has=pg.locator("label", has_text="Alasan tanpa PR")).first.locator("textarea").first.fill(
        "UJI-UX — pembelian langsung tanpa PR")
    # lines: add 2 rows
    rows = pg.locator(".modal table.lines tbody tr")
    while rows.count() < 2:
        click(pg, ".modal button:has-text('Tambah baris')")
        pg.wait_for_timeout(200)
    line_inputs = pg.evaluate("() => [...document.querySelectorAll('.modal table.lines tbody tr:first-child td')].map(td => { const i=td.querySelector('input,select,textarea'); return i ? (i.className||i.tagName)+':'+(i.type||'') : 'na' })")
    def fill_line(row, i, qty):
        combo = row.locator("td:nth-child(1) input.combo-input")
        if combo.count():
            combo.first.click(); CLICKS[0] += 1
            combo.first.type("kabel" if i == 0 else "cctv", delay=20)
            try:
                pg.wait_for_selector(".combo-pop .combo-opt", timeout=5000)
                pg.keyboard.press("ArrowDown"); pg.keyboard.press("Enter")
            except Exception:
                pg.keyboard.press("Escape")
        row.locator("td:nth-child(2) input").first.fill(f"UJI-UX baris {i+1}")
        row.locator("td:nth-child(3) input").first.fill(qty)
        row.locator("td:nth-child(4) input").first.fill("unit")
        row.locator("td:nth-child(5) input").first.fill("1500000")
    for i in range(2):
        fill_line(rows.nth(i), i, "0" if i == 0 else "10")
    pg.screenshot(path=f"{OUT}/s3-form-filled.png")
    # 3) Save with qty 0 on line 1 -> server 422 text as rendered
    click(pg, ".modal .modal-foot button:has-text('Simpan')")
    pg.wait_for_timeout(1500)
    server_errors = pg.evaluate("() => [...document.querySelectorAll('.modal .field .err, .modal td .err')].map(e=>e.innerText.trim()).filter(Boolean)")
    toast_422 = toasts(pg)
    pg.screenshot(path=f"{OUT}/s3-server-errors.png")
    # 4) Fix qty and save
    rows.nth(0).locator("td:nth-child(3) input").first.fill("5")
    click(pg, ".modal .modal-foot button:has-text('Simpan')")
    try:
        pg.wait_for_selector(".modal", state="detached", timeout=15000)
        saved = True
    except Exception:
        saved = False
        pg.screenshot(path=f"{OUT}/s3-save-failed.png")
    pg.wait_for_timeout(1200)
    t_saved = int((time.time() - t0) * 1000)
    toast_save = toasts(pg)
    where = pg.evaluate("() => ({hash: location.hash, h1: (document.querySelector('.page-head h1')||{}).innerText})")
    result = {"form": form, "client_errors": client_errors, "line_inputs_row1": line_inputs,
              "server_errors_rendered": server_errors, "toast_on_422": toast_422, "saved": saved,
              "toast_after_save": toast_save, "landing_after_save": where, "create_ms": t_saved, "api_calls": len(reqs),
              "nav_group_opened": nav_group_opened}
    if not saved:
        return result
    # 5) Submit (Ajukan) from wherever we landed
    if "d/" not in (where.get("hash") or ""):
        click(pg, "tr.clickable:has-text('UJI-UX'), tr.clickable:first-child")
        pg.wait_for_selector(".page-head .actions", timeout=10000)
        pg.wait_for_timeout(800)
    bar = pg.evaluate("() => [...document.querySelectorAll('.page-head .actions button')].map(b => (b.innerText.trim() || b.title))")
    code = pg.evaluate("() => (document.querySelector('.page-head h1')||{}).innerText")
    pg.screenshot(path=f"{OUT}/s3-po-detail.png")
    if "Ajukan" in bar:
        click(pg, ".page-head .actions button:has-text('Ajukan')")
        pg.wait_for_timeout(1200)
        if pg.locator(".modal").count():
            result["submit_modal_fields"] = pg.evaluate("() => [...document.querySelectorAll('.modal .field > label')].map(l=>l.innerText.trim())")
            click(pg, ".modal .modal-foot button.primary, .modal .modal-foot button:has-text('Ajukan')")
        pg.wait_for_selector(".toast", timeout=15000)
        pg.wait_for_timeout(600)
        result["toast_after_submit"] = toasts(pg)
        result["status_after_submit"] = pg.evaluate("() => (document.querySelector('.page-head .badge')||{}).innerText")
        result["bar_after_submit"] = pg.evaluate("() => [...document.querySelectorAll('.page-head .actions button')].map(b => (b.innerText.trim() || b.title))")
        pg.screenshot(path=f"{OUT}/s3-after-submit.png")
    result["po_code"] = code
    result["detail_action_bar"] = bar
    result["total_ms_create_to_submit"] = int((time.time() - t0) * 1000)
    return result

@scenario("S4_session_loss")
def s4(pg):
    """Type into a PO form, revoke the token server-side, attempt save: what does the user see, what survives?"""
    login(pg, "procurement@nusantara.test")
    pg.goto(BASE + "#/r/procurement/purchase-orders")
    pg.wait_for_selector(".page-head .actions button:has-text('Tambah')", timeout=15000)
    click(pg, ".page-head .actions button:has-text('Tambah')")
    pg.wait_for_selector(".modal", timeout=10000)
    pg.wait_for_timeout(1000)
    ta = pg.locator(".modal textarea").first
    if ta.count():
        ta.fill("UJI-UX — isian yang akan hilang bila sesi berakhir.")
    vf = pg.locator(".modal .field", has=pg.locator("label", has_text="Vendor")).first.locator("input.combo-input").first
    vf.click(); CLICKS[0] += 1; vf.type("PT", delay=20)
    pg.wait_for_selector(".combo-pop .combo-opt", timeout=8000); pg.keyboard.press("ArrowDown"); pg.keyboard.press("Enter")
    rows = pg.locator(".modal table.lines tbody tr")
    while rows.count() < 3:
        click(pg, ".modal button:has-text('Tambah baris')"); pg.wait_for_timeout(150)
    # Perkiraan kirim wajib sejak T3.5: tanpa isian ini Simpan berhenti di klien dan 401-nya tidak pernah terjadi.
    pg.locator(".modal .field", has=pg.locator("label", has_text="Perkiraan kirim")).first.locator("input[type=date]").first.fill(
        time.strftime("%Y-%m-%d", time.localtime(time.time() + 14 * 86400)))
    # Alasan tanpa PR wajib sejak T3.8: tanpa isian ini Simpan berhenti di klien dan 401-nya tidak pernah terjadi.
    pg.locator(".modal .field", has=pg.locator("label", has_text="Alasan tanpa PR")).first.locator("textarea").first.fill(
        "UJI-UX — pembelian langsung tanpa PR")
    for i in range(3):
        r = rows.nth(i)
        r.locator("td:nth-child(2) input").first.fill(f"UJI-UX baris {i+1}")
        r.locator("td:nth-child(3) input").first.fill("1")
        r.locator("td:nth-child(5) input").first.fill("1000")
    typed = pg.evaluate("() => [...document.querySelectorAll('.modal input, .modal textarea')].filter(e=>e.value).length")
    # revoke the token: delete personal access tokens of this user
    con = sqlite3.connect(DB)
    n = con.execute("DELETE FROM personal_access_tokens WHERE tokenable_id IN (SELECT id FROM users WHERE email='procurement@nusantara.test')").rowcount
    con.commit(); con.close()
    click(pg, ".modal .modal-foot button:has-text('Simpan')")
    pg.wait_for_timeout(2500)
    state = pg.evaluate("""() => ({ loginVisible: !!document.querySelector('.login'), modalVisible: !!document.querySelector('.modal'),
        banner: (document.querySelector('.login .alert')||{}).innerText || null, toasts: [...document.querySelectorAll('.toast')].map(t=>t.innerText),
        localStorageKeys: Object.keys(localStorage) })""")
    pg.screenshot(path=f"{OUT}/s4-after-revoke.png")
    # what the user must do now: try to reach the login form
    state["login_form_behind_overlay"] = pg.evaluate("() => { const l=document.querySelector('.login button[type=submit]'); if(!l) return null; const r=l.getBoundingClientRect(); const top=document.elementFromPoint(r.x+r.width/2, r.y+r.height/2); return top ? (top.closest('.overlay') ? 'blocked by overlay' : 'reachable') : 'offscreen' }")
    pg.keyboard.press("Escape"); pg.wait_for_timeout(600)
    state["after_escape"] = pg.evaluate("() => ({ modals: document.querySelectorAll('.modal').length, dialogText: [...document.querySelectorAll('.modal')].map(m=>m.innerText.slice(0,160)) })")
    pg.screenshot(path=f"{OUT}/s4-after-escape.png")
    discard = pg.locator(".modal .modal-foot button", has_text="Buang")
    if discard.count():
        state["dirty_prompt_buttons"] = pg.evaluate("() => [...document.querySelectorAll('.modal .modal-foot button')].map(b=>b.innerText.trim())")
        click(pg, ".modal .modal-foot button:has-text('Buang')"); pg.wait_for_timeout(600)
    state["modal_after_discard"] = pg.locator(".modal").count()
    if pg.locator(".login").count():
        pg.fill(".login input[type=email]", "procurement@nusantara.test"); pg.fill(".login input[type=password]", "password")
        click(pg, ".login button[type=submit]")
        pg.wait_for_selector("nav.nav", timeout=15000); pg.wait_for_timeout(1500)
        state["after_relogin"] = pg.evaluate("() => ({hash: location.hash, modal: !!document.querySelector('.modal'), recoveryOffer: /pulih|draf|belum tersimpan/i.test(document.body.innerText), toast: [...document.querySelectorAll('.toast')].map(t=>t.innerText.slice(0,120))})")
        pg.screenshot(path=f"{OUT}/s4-after-relogin.png")
        if pg.locator(".toast button:has-text('Pulihkan')").count():
            click(pg, ".toast button:has-text('Pulihkan')")
            pg.wait_for_selector(".modal", timeout=10000); pg.wait_for_timeout(800)
            if pg.locator(".modal .modal-foot button:has-text('Pulihkan')").count():
                click(pg, ".modal .modal-foot button:has-text('Pulihkan')"); pg.wait_for_timeout(1500)
            state["restored"] = pg.evaluate("() => ({ title: (document.querySelector('.modal-head h2')||{}).innerText, filled: [...document.querySelectorAll('.modal input, .modal textarea')].filter(e=>e.value).length, lines: document.querySelectorAll('.modal table.lines tbody tr').length, vendor: (document.querySelector('.modal .combo-input')||{}).value, textarea: (document.querySelector('.modal textarea')||{}).value })")
            pg.screenshot(path=f"{OUT}/s4-restored.png")
    state["fields_typed_before_expiry"] = typed
    return {"tokens_revoked": n, **state}

@scenario("S5_nav_per_role")
def s5(pg):
    out = {}
    for u in ["admin","direktur","project-manager","site-manager","estimator","procurement","warehouse","finance","hr","sales","teknisi"]:
        pg.context.clear_cookies()
        pg.goto(BASE); pg.evaluate("() => localStorage.clear()")
        try:
            login(pg, f"{u}@nusantara.test")
        except Exception as e:
            out[u] = {"ERROR": str(e)[:120], "screen": pg.evaluate("() => document.body.innerText.slice(0,200)")}; continue
        out[u] = pg.evaluate("""() => { const nav=document.querySelector('nav.nav'); const groups=[...nav.querySelectorAll('.nav-group')];
            return { groups: groups.length, links: nav.querySelectorAll('.nav-items a').length, navHeightPx: nav.scrollHeight,
                     viewportsTall: +(nav.scrollHeight / innerHeight).toFixed(1),
                     // scrollHeight punya lantai = tinggi kolom grid (.shell min-height + isi dasbor, 1289 px untuk
                     // admin 4 Sep 2026); tinggi ISI sidebar sendiri dijumlahkan di sini supaya angka di bawah lantai itu terbaca.
                     navContentPx: [...nav.children].reduce((sum, node) => sum + node.offsetHeight, 0) + 38,
                     open_groups: groups.filter(g => g.dataset.open === 'true').map(g => g.querySelector('button').innerText.trim()),
                     shortcut_groups: nav.querySelectorAll('.nav-group[data-kind]').length,
                     dividers: [...nav.querySelectorAll('.nav-divider')].map(d => d.innerText.trim()),
                     biggest: groups.map(g => [g.querySelector('button').innerText.trim(), g.querySelectorAll('a').length]).sort((a,b)=>b[1]-a[1]).slice(0,3),
                     stats: document.querySelectorAll('.stat').length, cards: [...document.querySelectorAll('.card h2')].map(h=>h.innerText.replace(/\\s*\\(\\d+\\)/,'')) } }""")
    return out

@scenario("S6_mobile_lapangan")
def s6(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    login(pg, "site-manager@nusantara.test")
    pg.wait_for_timeout(1000)
    pg.screenshot(path=f"{OUT}/s6-mobile-dashboard.png")
    t0 = time.time()
    click(pg, ".header .menu-toggle")
    pg.wait_for_timeout(500)
    drawer = pg.evaluate("""() => { const nav=document.querySelector('nav.nav'); const a=nav.querySelector("a[href='#/lapangan']");
        const r=a.getBoundingClientRect(); const shown=a.checkVisibility(); return { links: nav.querySelectorAll('.nav-items a').length, drawerHeight: nav.scrollHeight,
        lapanganTop: shown ? r.top : null, linkVisible: shown, visibleWithoutScroll: shown && r.top >= 0 && r.bottom <= innerHeight, groupsAbove: [...nav.querySelectorAll('.nav-group')].findIndex(g=>g.contains(a)) } }""")
    pg.screenshot(path=f"{OUT}/s6-mobile-drawer.png")
    drawer["group_opened"] = nav_click(pg, "#/lapangan")
    pg.wait_for_timeout(1500)
    lap = pg.evaluate("() => ({ hash: location.hash, h1: (document.querySelector('.page-head h1')||{}).innerText, bigButtons: document.querySelectorAll('.btn.lg').length, text: document.querySelector('main').innerText.slice(0,300) })")
    pg.screenshot(path=f"{OUT}/s6-mobile-lapangan.png")
    # "Tombol besar" ada juga di dasbor lapangan: hash-nya dicatat sejak dulu, tetapi tidak pernah
    # dijadikan syarat — sekarang iya (verifikasi P1-B putaran 2, 6 Sep 2026).
    assert_screen(pg, "#/lapangan")
    ctx.close()
    return {"taps_to_lapangan": CLICKS[0], "ms": int((time.time()-t0)*1000), **drawer, "lapangan": lap}

@scenario("S7_status_colors")
def s7(pg):
    login(pg, "admin@nusantara.test")
    out = {}
    for key, route in [("ncr","#/r/quality/ncr"), ("k3","#/r/projects/safety-incidents"), ("defects","#/defects"), ("tickets","#/r/servicedesk/tickets")]:
        pg.goto(BASE + route); pg.wait_for_timeout(1800)
        # Halaman yang salah tidak punya table.data → daftar KOSONG, yang di sini terbaca persis seperti
        # "daftar ini memang tanpa lencana" (verifikasi P1-B putaran 2, 6 Sep 2026).
        assert_screen(pg, route)
        out[key] = pg.evaluate("() => [...new Set([...document.querySelectorAll('table.data .badge')].map(b => b.innerText.trim()+' → '+[...b.classList].filter(c=>['green','red','amber','blue','primary'].includes(c)).join('/')))]")
        # T2.8 — lencana di kepala halaman detail juga diukur: di sanalah statusTone
        # melukis 'open' (detail.js), sedangkan daftar NCR/K3/defect semula menulis
        # statusnya sebagai teks polos tanpa lencana (diukur 4 Sep 2026: ncr → []).
        if pg.locator("tr.clickable").count():
            click(pg, "tr.clickable >> nth=0"); pg.wait_for_selector(".page-head h1", timeout=15000); pg.wait_for_timeout(1200)
            out[key + "_detail"] = pg.evaluate("() => { const b=document.querySelector('.page-head .badge'); return { h1: (document.querySelector('.page-head h1')||{}).innerText, badge: b ? b.innerText.trim()+' → '+[...b.classList].filter(c=>['green','red','amber','blue','primary'].includes(c)).join('/') : null } }")
    return out

S8_MEASURE = """() => { const cs=(s)=>getComputedStyle(document.querySelector(s)); const th=cs('table.data th'); const sm=document.querySelector('.btn.sm');
        const root=getComputedStyle(document.documentElement);
        const lum=(hex)=>{const c=hex.match(/\\w\\w/g).map(x=>parseInt(x,16)/255).map(v=>v<=.03928?v/12.92:((v+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2]};
        const cr=(a,b)=>{const l1=lum(a),l2=lum(b);return +(((Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05)).toFixed(2))};
        const v=(n)=>root.getPropertyValue(n).trim();
        return { theme: document.documentElement.dataset.theme || 'system', th_font: th.fontSize, th_color: th.color, muted_token: v('--muted'), bg: v('--bg'), surface2: v('--surface-2'),
                 contrast_muted_on_bg: cr(v('--muted'), v('--bg')), contrast_muted_on_surface2: cr(v('--muted'), v('--surface-2')),
                 contrast_success_badge: cr(v('--success'), v('--success-soft')),
                 btn_sm_height: sm ? sm.getBoundingClientRect().height : null,
                 smallest_font_px: Math.min(...[...document.querySelectorAll('body *')].map(e=>parseFloat(getComputedStyle(e).fontSize)).filter(Boolean)),
                 page_head_buttons: [...document.querySelectorAll('.page-head .actions button')].map(b=>b.innerText.trim()||b.title) } }"""

@scenario("S8_styles")
def s8(pg):
    # P1-B: dijalankan di DUA tema. Kunci datar = tema terang (bentuk lama, pembaca lama tetap
    # jalan); `dark` = pengukuran yang sama di tema gelap (data-theme di <html>, mekanisme S20).
    login(pg, "admin@nusantara.test")
    pg.goto(BASE + "#/r/procurement/purchase-orders"); pg.wait_for_timeout(1800)
    # Semua yang diukur di bawah juga ADA di dasbor (tabel, .btn.sm, tombol kepala halaman), jadi
    # halaman yang salah lolos tanpa suara — dijatuhkan di sini (verifikasi P1-B putaran 2, 6 Sep 2026).
    screen = assert_screen(pg, "#/r/procurement/purchase-orders", "Pesanan Pembelian")
    pg.evaluate("() => { document.documentElement.dataset.theme = 'light'; }"); pg.wait_for_timeout(150)
    out = {"screen": screen, **pg.evaluate(S8_MEASURE)}
    pg.evaluate("() => { document.documentElement.dataset.theme = 'dark'; }"); pg.wait_for_timeout(150)
    out["dark"] = pg.evaluate(S8_MEASURE)
    pg.evaluate("() => { delete document.documentElement.dataset.theme; }")
    return out

@scenario("S9_account_menu")
def s9(pg):
    # T2.7 — halaman masuk dulu: baris "Lupa kata sandi?" datang dari server
    # (GET iam/auth/password-help), bukan tebakan SPA. Diukur 2 Sep 2026: menu
    # akun hanya Tutup · Keluar, tidak ada ganti sandi mandiri.
    pg.goto(BASE); pg.wait_for_selector("input[type=email]", timeout=15000); pg.wait_for_timeout(900)
    help_line = pg.evaluate("() => (document.querySelector('.login .password-help')||{}).innerText || null")
    login(pg, "finance@nusantara.test")
    click(pg, ".userchip")
    pg.wait_for_timeout(700)
    items = pg.evaluate("() => [...document.querySelectorAll('.modal button, .modal a, .menu button, [role=menu] *')].map(e=>e.innerText.trim()).filter(Boolean)")
    pg.screenshot(path=f"{OUT}/s9-account.png")
    out = {"login_password_help": help_line, "account_menu_items": items}
    if pg.locator(".modal button:has-text('Ganti kata sandi')").count():
        click(pg, ".modal button:has-text('Ganti kata sandi')"); pg.wait_for_timeout(600)
        out["change_password_modal"] = pg.evaluate("() => ({ title: (document.querySelector('.modal-head h2')||{}).innerText, labels: [...document.querySelectorAll('.modal .field > label')].map(l=>l.innerText.trim()), buttons: [...document.querySelectorAll('.modal .modal-foot button')].map(b=>b.innerText.trim()), help: [...document.querySelectorAll('.modal .help')].map(h=>h.innerText.trim()) })")
        inputs = pg.locator(".modal input[type=password]")
        # sandi lama salah → 422 pada `current`, dilukis di bawah field, dialog tetap terbuka
        inputs.nth(0).fill("bukan-sandi-saya"); inputs.nth(1).fill("password"); inputs.nth(2).fill("password")
        # Tunggu jawabannya, bukan 1,5 s tetap: di Chromium 422-nya tiba 1,9 s setelah
        # klik lewat php -S (curl langsung 0,24 s) dan pengukuran pertama membaca modal
        # yang masih menunggu — errors [] (4 Sep 2026).
        with pg.expect_response(lambda r: "me/password" in r.url, timeout=20000):
            click(pg, ".modal .modal-foot button:has-text('Simpan kata sandi')")
        pg.wait_for_timeout(400)
        out["wrong_current"] = pg.evaluate("() => ({ errors: [...document.querySelectorAll('.modal .field.invalid .err')].map(e=>e.innerText.trim()), modalOpen: !!document.querySelector('.modal'), toasts: [...document.querySelectorAll('.toast')].map(t=>t.innerText.trim()) })")
        pg.screenshot(path=f"{OUT}/s9-change-password-wrong-current.png")
        # sandi lama benar → diganti ke nilai yang sama ("password"), supaya skenario lain tetap bisa masuk
        inputs.nth(0).fill("password")
        with pg.expect_response(lambda r: "me/password" in r.url, timeout=20000):
            click(pg, ".modal .modal-foot button:has-text('Simpan kata sandi')")
        pg.wait_for_timeout(400)
        out["after_change"] = pg.evaluate("() => ({ modalOpen: !!document.querySelector('.modal'), toasts: [...document.querySelectorAll('.toast')].map(t=>t.innerText.trim()) })")
    return out

@scenario("S10_api_422_language")
def s10(pg):
    tok = token_for("procurement@nusantara.test")
    s, d = api("procurement/purchase-orders", tok, "POST", {"items": [{"description": "x", "qty": 0}]})
    s2, d2 = api("crm/customers", token_for("sales@nusantara.test"), "POST", {})
    s3, d3 = api("finance/ap-bills", token_for("finance@nusantara.test"), "POST", {})
    return {"po_422": {k: v[0] for k, v in (d.get("errors") or {}).items()},
            "customer_422": {k: v[0] for k, v in (d2.get("errors") or {}).items()},
            "apbill_422": {k: v[0] for k, v in list((d3.get("errors") or {}).items())[:6]}}

@scenario("S11_tugas")
def s11(pg):
    login(pg, "direktur@nusantara.test")
    nav_click(pg, "#/tugas")
    pg.wait_for_selector("table.data, .empty", timeout=15000); pg.wait_for_timeout(800)
    # Dasbor juga punya table.data (kartu "Menunggu persetujuan"): tunggu di atas puas di halaman yang
    # salah, dan `rows` di bawah akan mencatat baris kartu itu (verifikasi P1-B putaran 2, 6 Sep 2026).
    assert_screen(pg, "#/tugas", "Tugas Saya")
    out = pg.evaluate("() => ({ h1: document.querySelector('.page-head h1').innerText, rows: [...document.querySelectorAll('table.data tbody tr')].map(r=>r.innerText.split('\\n')[0]), types: [...document.querySelectorAll('.filters option')].map(o=>o.innerText) })")
    pg.screenshot(path=f"{OUT}/s11-tugas.png")
    click(pg, "table.data tbody tr:has-text('CTI/')")
    pg.wait_for_selector(".page-head h1:has-text('CTI/')", timeout=15000); pg.wait_for_timeout(800)
    out["leave_detail_bar"] = pg.evaluate("() => [...document.querySelectorAll('.page-head .actions button')].map(b => (b.innerText.trim() || b.title))")
    out["status_strip"] = pg.evaluate("() => { const a=document.querySelector('.page-head + .alert'); return a ? a.innerText : null }")
    pg.screenshot(path=f"{OUT}/s11-leave-detail.png")
    return out

@scenario("S12_po_override")
def s12(pg):
    """Ajukan PO (T2.4): vendor sehat = berapa klik tanpa modal; vendor yang TERBLOKIR di antara draf dan
    pengajuan (dinonaktifkan langsung di sqlite, seperti S4 mencabut token) — apa yang tampil dari 422 server,
    apakah isian kosong ditahan, dan apakah alasannya tersimpan di PO."""
    tok = token_for("procurement@nusantara.test")
    s, d = api("procurement/vendors?status=active&per_page=20", tok)
    # Pemasok biasa: subkon/mandor tunduk klausul K3L/pakta (P0-E) — bukan yang diukur di sini.
    vendor = next((v for v in d["data"] if v.get("vendor_type") in (None, "supplier")), None)
    if vendor is None:   # StopIteration menyembunyikan sebabnya (verifikasi P1-B putaran 3)
        raise AssertionError(f"tidak ada vendor bertipe supplier/kosong di antara {len(d['data'])} vendor — "
                             "fixture RFQ/PR tidak bisa dibuat pada basis data ini")
    BADGE = "() => (document.querySelector('.page-head .badge')||{}).innerText"
    def draft_po(tag):
        s, d = api("procurement/purchase-orders", tok, "POST", {"vendor_id": vendor["id"], "order_date": "2026-09-02",
                   "expected_date": "2026-09-16",  # wajib sejak T3.5
                   "pr_bypass_reason": "UJI-UX — pembelian langsung tanpa PR",  # wajib sejak T3.8 (PO tanpa PR)
                   "items": [{"description": f"UJI-UX {tag}", "qty": 1, "unit": "unit", "unit_price": 1500000}]})
        return d["data"]["id"], d["data"]["code"]
    def open_po(po_id):
        pg.goto(BASE + f"#/d/procurement/purchase-orders/{po_id}")
        pg.wait_for_selector(".page-head .actions button:has-text('Ajukan')", timeout=15000); pg.wait_for_timeout(800)
    out = {"vendor": vendor.get("code")}
    login(pg, "procurement@nusantara.test")
    # 1) vendor sehat: Ajukan — modal atau langsung?
    healthy_id, healthy_code = draft_po("vendor sehat")
    open_po(healthy_id); CLICKS[0] = 0
    click(pg, ".page-head .actions button:has-text('Ajukan')")
    pg.wait_for_timeout(1200)
    modal_opened = bool(pg.locator(".modal").count())
    if modal_opened:
        click(pg, ".modal .modal-foot button.primary")
    pg.wait_for_selector(".toast", timeout=15000); pg.wait_for_timeout(600)
    out["healthy"] = {"po": healthy_code, "modal_opened": modal_opened, "submit_clicks": CLICKS[0],
                      "toast": toasts(pg), "status": pg.evaluate(BADGE)}
    # 2) vendor terblokir SETELAH draf dibuat (gate berdiri saat mengajukan, bukan saat draf)
    blocked_id, blocked_code = draft_po("vendor terblokir")
    con = sqlite3.connect(DB); con.execute("UPDATE prc_vendors SET status='inactive' WHERE id=?", (vendor["id"],)); con.commit(); con.close()
    try:
        open_po(blocked_id); CLICKS[0] = 0
        click(pg, ".page-head .actions button:has-text('Ajukan')")
        pg.wait_for_selector(".modal", timeout=10000); pg.wait_for_timeout(500)
        out["prompt"] = pg.evaluate("""() => { const m=document.querySelector('.modal'); return {
            title: (m.querySelector('.modal-head h2')||{}).innerText || null,
            message: (m.querySelector('.modal-body p')||{}).innerText || null,
            fields: [...m.querySelectorAll('.field > label')].map(l=>l.innerText.trim()),
            help: [...m.querySelectorAll('.field .help')].map(h=>h.innerText.trim()),
            buttons: [...m.querySelectorAll('.modal-foot button')].map(b=>b.innerText.trim()) } }""")
        pg.screenshot(path=f"{OUT}/s12-prompt.png")
        # kosong -> ditahan di klien (Wajib diisi.), modal tetap terbuka, tidak ada permintaan
        click(pg, ".modal .modal-foot button.primary"); pg.wait_for_timeout(600)
        out["empty_reason"] = {"errors": pg.evaluate("() => [...document.querySelectorAll('.modal .field .err')].map(e=>e.innerText.trim())"),
                               "modal_open": bool(pg.locator(".modal").count()), "toasts": toasts(pg)}
        pg.fill(".modal textarea", "UJI-UX — pembelian darurat, vendor tunggal pemegang lisensi")
        click(pg, ".modal .modal-foot button.primary")
        pg.wait_for_selector(".toast", timeout=15000); pg.wait_for_timeout(800)
        out["blocked"] = {"po": blocked_code, "submit_clicks": CLICKS[0], "toast": toasts(pg), "status": pg.evaluate(BADGE),
                          "modal_open": bool(pg.locator(".modal").count())}
        pg.screenshot(path=f"{OUT}/s12-after-override.png")
        s, fresh = api(f"procurement/purchase-orders/{blocked_id}", tok)
        out["stored"] = {"status": fresh["data"].get("status"), "qualification_override_reason": fresh["data"].get("qualification_override_reason")}
    except Exception as e:
        # Alur lama (modal opsional yang tertutup saat dikirim kosong) berhenti di sini; catat
        # apa yang terlihat alih-alih membuang seluruh hasil skenario.
        out["blocked"] = {"ERROR": str(e).split("\n")[0][:160], "submit_clicks": CLICKS[0], "toasts": toasts(pg),
                          "modal_open": bool(pg.locator(".modal").count()), "status": pg.evaluate(BADGE)}
        pg.screenshot(path=f"{OUT}/s12-blocked-failed.png")
    finally:
        con = sqlite3.connect(DB); con.execute("UPDATE prc_vendors SET status='active' WHERE id=?", (vendor["id"],)); con.commit(); con.close()
    return out

@scenario("S13_approve_with_note")
def s13(pg):
    """T2.3, jalur DENGAN catatan: buka 'Tambah catatan', ketik, Setujui — tanpa modal — lalu baca
    core_approvals langsung dari sqlite: catatan yang diketik harus tiba di baris 'approved' terbaru.
    Klik dihitung sampai keputusan (baris + pelipat + Setujui); S2 mengukur jalur tanpa catatan."""
    NOTE = "UJI-UX — catatan persetujuan inline"
    login(pg, "direktur@nusantara.test")
    pg.wait_for_timeout(1500)
    code = pg.evaluate("() => { const c=[...document.querySelectorAll('.card')].find(c=>/Menunggu persetujuan/.test(c.innerText)); const r=c&&c.querySelector('tbody tr'); return r ? r.innerText.split('\\n')[0] : null }")
    if not code:
        return {"inbox_empty": True}
    click(pg, f"tr.clickable:has-text('{code}')")
    pg.wait_for_selector(f".page-head h1:has-text('{code}')", timeout=15000); pg.wait_for_timeout(800)
    out = {"code": code, "before": pg.evaluate(NOTE_PANEL)}
    if not out["before"]:
        return out  # build lama: tidak ada panel inline — jalur modalnya sudah terukur di S2
    click(pg, ".page-head .actions details.action-note > summary"); pg.wait_for_timeout(250)
    out["after_toggle"] = pg.evaluate(NOTE_PANEL)
    pg.screenshot(path=f"{OUT}/s13-note-open.png")
    pg.fill(".page-head .actions details.action-note textarea", NOTE)
    bodies = []
    pg.on("request", lambda r: bodies.append(r.post_data) if r.url.endswith("/approve") else None)
    click(pg, ".page-head .actions button:has-text('Setujui')")
    pg.wait_for_selector(".toast:has-text('disetujui'), .toast.err", timeout=15000); pg.wait_for_timeout(500)
    out["approve_payload"] = bodies[-1] if bodies else None  # kontrak API: { note } seperti sebelum T2.3
    out["modal_opened"] = bool(pg.locator(".modal").count())
    out["toast"] = toasts(pg)
    out["clicks_to_decide"] = CLICKS[0]
    con = sqlite3.connect(DB)
    row = con.execute("SELECT note FROM core_approvals WHERE action='approved' ORDER BY id DESC LIMIT 1").fetchone()
    con.close()
    out["stored_note"] = row[0] if row else None
    out["note_stored"] = out["stored_note"] == NOTE
    return out

@scenario("S14_search_screens")
def s14(pg):
    """Ctrl+K "opname" as admin (T2.5): the client-side "Layar" group, in the order shown, then Enter."""
    login(pg, "admin@nusantara.test")
    pg.keyboard.press("Control+k")
    pg.wait_for_selector(".modal .search-input", timeout=5000)
    # Ketik ke kotaknya, bukan ke halaman: openSearch() memfokuskan input 30 ms setelah modal tampil,
    # dan ketikan yang mendahului fokus itu jatuh ke <body> (terjadi pada run pertama 4 Sep 2026).
    pg.locator(".modal .search-input").press_sequentially("opname")
    pg.wait_for_timeout(1200)  # debounce 220 ms + the server round-trip
    out = pg.evaluate("""() => [...document.querySelectorAll('.search-group')].map(g => ({
        label: g.querySelector('.search-group-label').innerText.trim(),
        hits: [...g.querySelectorAll('.search-hit')].map(h => h.innerText.trim().replace(/\\n/g, ' · ')) }))""")
    pg.screenshot(path=f"{OUT}/s14-ctrl-k-opname.png")
    pg.keyboard.press("Enter"); pg.wait_for_timeout(800)
    layar = next((g for g in out if g["label"].lower() == "layar"), None)  # innerText ikut text-transform: "LAYAR"
    return {"groups": out, "layar": layar["hits"] if layar else None, "layar_count": len(layar["hits"]) if layar else 0,
            "enter_opened": pg.evaluate("() => ({ hash: location.hash, h1: (document.querySelector('.page-head h1')||{}).innerText || null, modal: !!document.querySelector('.modal') })")}

# JPEG 8×8 abu-abu dari GD (php -r 'imagejpeg(imagecreatetruecolor(8,8), ...)'), 691 byte, tanpa EXIF.
# padded_jpeg() melebarkannya dengan segmen COM (FF FE, maks 65 533 byte per segmen) sampai ukuran yang
# diminta: tetap JPEG sah bagi finfo dan exif_read_data, tanpa PIL di harness. Diterima server 4 Sep
# 2026 (201, mime image/jpeg, geo_source device — posisi konteks dipakai karena tak ada GPS EXIF).
JPEG_SEED = base64.b64decode(
    "/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNDAK"
    "/9sAQwAUDg8SDw0UEhASFxUUGB4yIR4cHB49LC4kMklATEtHQEZFUFpzYlBVbVZFRmSIZW13e4GCgU5gjZeMfZZzfoF8/9sAQwEVFxceGh47"
    "ISE7fFNGU3x8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8A"
    "AAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHw"
    "JDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeo"
    "qaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkK"
    "C//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpD"
    "REVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW"
    "19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AKKKKsk//2Q==")

def padded_jpeg(size):
    body, tail = JPEG_SEED[2:-2], JPEG_SEED[-2:]
    out = bytearray(b"\xff\xd8"); need = size - len(JPEG_SEED)
    while need > 4:
        n = min(65533, need - 2)
        out += b"\xff\xfe" + struct.pack(">H", n) + b"x" * (n - 2); need -= n + 2
    return bytes(out + body + tail)

@scenario("S15_lapangan_upload")
def s15(browser):
    """T2.9 — foto dari layar Lapangan di ponsel (site-manager, 390×844, posisi konteks Jakarta). Tiga ukuran:
    (1) bilah kemajuan per foto saat unggahan dicekik — CDP uploadThroughput 200 kB/s, dan context.route
    menahan jawaban 1,5 s (di Chromium route saja TIDAK menggerakkan upload.onprogress: byte baru dihitung
    setelah continue_(), diukur 4 Sep 2026 — jadi cekikan CDP yang menggerakkan bilah, route yang
    memperlihatkan keadaan "Menunggu jawaban server…"); (2) jaringan putus (route.abort) — foto tetap
    terdaftar dengan "Kirim ulang" dan bertahan lewat muat-ulang halaman (localStorage); (3) kirim ulang
    berhasil setelah jaringan kembali. Klik dihitung: Buat laporan (bila hari ini belum ada), Ambil foto ×2,
    Kirim ulang."""
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True,
                              geolocation={"latitude": -6.2, "longitude": 106.8, "accuracy": 12}, permissions=["geolocation"])
    pg = ctx.new_page()
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e).split("\n")[0][:160]))
    # Lembar onboarding site-manager yang belum diputuskan menangkap ketukan 'Buat laporan hari ini'
    # (verifikasi P1-B 5 Sep 2026) — statusnya diputuskan oleh login() sendiri sejak putaran 2.
    login(pg, "site-manager@nusantara.test")
    pg.goto(BASE + "#/lapangan")
    pg.wait_for_selector("button:has-text('Ambil foto'), button:has-text('Buat laporan hari ini')", timeout=15000)
    out = {"report_created": False}
    if pg.locator("button:has-text('Buat laporan hari ini')").count():
        # Langkah pertama site manager pagi itu; dihitung karena memang ditekan orang. Kegiatan wajib
        # diisi (422 "Kegiatan wajib diisi." tanpa isian — aturan server yang sudah ada, bukan T2.9).
        pg.fill("textarea", "UJI-UX — foto progres lantai 3")
        click(pg, "button:has-text('Buat laporan hari ini')")
        pg.wait_for_selector("button:has-text('Ambil foto')", timeout=15000); out["report_created"] = True
    pg.wait_for_timeout(600)
    ROW = """() => { const r=document.querySelector('.upload-item'); if(!r) return null; const p=r.querySelector('.progress');
        return { state: r.dataset.state, text: r.querySelector('.cell-sub').innerText, pct: p ? Number(p.getAttribute('aria-valuenow')) : null,
                 bar_width: p ? p.firstChild.style.width : null, buttons: [...r.querySelectorAll('button')].map(b=>b.innerText.trim()) } }"""
    PHOTOS = "() => document.querySelectorAll('.field-photo').length"
    ROWS = "() => document.querySelectorAll('.upload-item').length"
    STORED = "() => Object.keys(localStorage).filter(k => k.startsWith('nusantara_erp_upload:')).length"
    out["photos_before"] = pg.evaluate(PHOTOS)
    photo = padded_jpeg(1024 * 1024)

    def shoot(name):
        with pg.expect_file_chooser() as chooser:
            click(pg, "button:has-text('Ambil foto')")
        chooser.value.set_files({"name": name, "mimeType": "image/jpeg", "buffer": photo})

    # (1) unggahan dicekik: bilah harus bergerak, lalu "Menunggu jawaban server…" selama route menahan
    cdp = ctx.new_cdp_session(pg); cdp.send("Network.enable")
    cdp.send("Network.emulateNetworkConditions", {"offline": False, "latency": 40, "downloadThroughput": 5_000_000, "uploadThroughput": 200_000})
    def hold(route):
        if route.request.method != "POST": return route.continue_()
        time.sleep(1.5); route.continue_()
    ctx.route("**/api/core/attachments", hold)
    t0 = time.time(); shoot("uji-1mb.jpg")
    samples = []; shot = False
    while time.time() - t0 < 30:
        s = pg.evaluate(ROW)
        if s: samples.append((round(time.time() - t0, 2), s["state"], s["pct"], s["text"]))
        elif samples: break
        if s and not shot and s["pct"] and 25 <= s["pct"] <= 75:
            pg.screenshot(path=f"{OUT}/s15-progress.png"); shot = True
        pg.wait_for_timeout(100)
    pg.wait_for_selector(".toast", timeout=15000); pg.wait_for_timeout(400)
    pcts = sorted(set(s[2] for s in samples if s[2] is not None))
    out["throttled"] = {"ms": int((time.time() - t0) * 1000), "samples": len(samples), "distinct_pct": len(pcts),
                        "pct_first_last": [pcts[0], pcts[-1]] if pcts else None, "states": list(dict.fromkeys(s[1] for s in samples)),
                        "texts": list(dict.fromkeys(re.sub(r"\d+ %", "N %", s[3]) for s in samples)),
                        "toast": toasts(pg), "photos_after": pg.evaluate(PHOTOS), "queue_rows": pg.evaluate(ROWS), "stored_keys": pg.evaluate(STORED)}
    cdp.send("Network.emulateNetworkConditions", {"offline": False, "latency": 0, "downloadThroughput": -1, "uploadThroughput": -1})
    ctx.unroute("**/api/core/attachments")
    pg.wait_for_timeout(5500)  # toast pertama lenyap dulu, supaya toast berikutnya terbaca sendiri

    # (2) jaringan putus saat mengirim: barisnya tetap ada, dengan Kirim ulang, dan selamat dari muat ulang
    ctx.route("**/api/core/attachments", lambda route: route.abort("connectionfailed") if route.request.method == "POST" else route.continue_())
    shoot("uji-putus.jpg")
    pg.wait_for_selector(".upload-item[data-state='failed']", timeout=20000); pg.wait_for_timeout(400)
    out["failed"] = {**pg.evaluate(ROW), "stored_keys": pg.evaluate(STORED), "toasts": toasts(pg)}
    pg.screenshot(path=f"{OUT}/s15-failed.png")
    pg.reload(); pg.wait_for_selector("button:has-text('Ambil foto')", timeout=20000); pg.wait_for_timeout(800)
    out["after_reload"] = pg.evaluate(ROW)

    # (3) jaringan kembali: Kirim ulang
    ctx.unroute("**/api/core/attachments")
    click(pg, ".upload-item button:has-text('Kirim ulang')")
    pg.wait_for_selector(".toast:has-text('terkirim')", timeout=20000); pg.wait_for_timeout(900)
    out["retry"] = {"toast": toasts(pg), "queue_rows": pg.evaluate(ROWS), "photos_after": pg.evaluate(PHOTOS), "stored_keys": pg.evaluate(STORED),
                    "last_photo": pg.evaluate("() => { const p=document.querySelector('.field-photo'); return p ? p.innerText.replace(/\\n/g,' · ') : null }")}
    pg.screenshot(path=f"{OUT}/s15-after-retry.png")
    out["pageerrors"] = errors
    ctx.close()
    return out

def ap_bill_with_outstanding(tok):
    """Tagihan vendor yang disetujui dan MASIH BERSISA untuk S16 — dipakai apa adanya bila ada,
    dibuat lewat API bila tidak.

    Salinan basis data hidup 6 Sep 2026 memuat SATU tagihan (BIL/2026/III/0001, approved,
    amount_paid 232.545.000 = lunas), jadi `next(b for b in ... if outstanding > 0)` melempar
    StopIteration dan S16 mati SEBELUM mengukur apa pun — bukan gagal jujur, melainkan tidak ada
    hasil sama sekali (verifikasi P1-B putaran 3). Skenario ini jalan di atas SALINAN coretan
    (ERP_DB), tidak pernah basis data hidup, jadi ia boleh membuat fixture-nya sendiri: draf →
    submit (finance) → approve (direktur). Yang dikembalikan: (tagihan, catatan) — catatan berisi
    kode yang dibuat, atau alasan mengapa tidak ada, dan pemanggil MENCATATNYA. Bila pembuatan
    gagal, S16 tercatat SKIPPED dengan sebabnya, tidak pernah "ok" tanpa pengukuran."""
    s, d = api("finance/ap-bills?status=approved&per_page=50", tok)
    for b in (d or {}).get("data", []):
        if float(b.get("outstanding") or 0) > 0:
            return b, None

    s, v = api("procurement/vendors?per_page=1", tok)
    vendors = (v or {}).get("data", [])
    if not vendors:
        return None, "tidak ada tagihan vendor approved yang bersisa DAN tidak ada vendor untuk membuatnya"

    payload = {"vendor_id": vendors[0]["id"], "description": "Fixture S16 — jasa uji harness",
               "vendor_invoice_no": f"INV-S16-{int(time.time())}", "dpp": 12_500_000,
               "bill_date": date.today().isoformat(), "due_date": date.today().isoformat()}
    s, made = api("finance/ap-bills", tok, "POST", payload)
    if s not in (200, 201) or not made:
        return None, f"POST finance/ap-bills → {s}: {str(made)[:160]}"
    bill_id = (made.get("data") or made).get("id")

    s, _ = api(f"finance/ap-bills/{bill_id}/submit", tok, "POST", {})
    if s != 200:
        return None, f"submit tagihan {bill_id} → {s}"
    s, _ = api(f"finance/ap-bills/{bill_id}/approve", token_for("direktur@nusantara.test"), "POST", {})
    if s != 200:
        return None, f"approve tagihan {bill_id} → {s} (direktur@ memegang fin.approve?)"

    s, fresh = api(f"finance/ap-bills/{bill_id}", tok)
    bill = (fresh or {}).get("data") or fresh
    if not bill or float(bill.get("outstanding") or 0) <= 0:
        return None, f"tagihan {bill_id} dibuat tetapi outstanding {bill.get('outstanding') if bill else '?'}"
    return bill, f"dibuat oleh harness: {bill['code']} (dpp 12.500.000, jatuh tempo hari ini)"


@scenario("S16_ap_bill_payment_button")
def s16(pg):
    """T3.1 — "Buat pembayaran" pada tagihan vendor yang disetujui dan masih bersisa (BIL/2026/VII/0002
    di produksi: 69 hari lewat jatuh tempo tanpa PAY, 4 Sep 2026). Dibuka sebagai finance (fin.create),
    tombolnya diklik: yang harus muncul formulir Pembayaran (bukan POST) dengan arah keluar dan jumlah =
    sisa tagihan; tersimpan tidak diuji di sini — itu formulir pembayaran biasa."""
    tok = token_for("finance@nusantara.test")
    bill, created = ap_bill_with_outstanding(tok)
    if bill is None:
        return {"SKIPPED": created}   # sebab tercatat, bukan "ok" — lihat ap_bill_with_outstanding()
    login(pg, "finance@nusantara.test")
    pg.goto(BASE + f"#/d/finance/ap-bills/{bill['id']}")
    pg.wait_for_selector(f".page-head h1:has-text('{bill['code']}')", timeout=15000); pg.wait_for_timeout(800)
    out = {"bill": bill["code"], "outstanding": bill["outstanding"], "fixture_created": created,
           **read_action_bar(pg, "s16-ap-bill-bar")}
    click(pg, ".page-head .actions button:has-text('Buat pembayaran')")
    pg.wait_for_selector(".modal .field", timeout=10000); pg.wait_for_timeout(300)
    out["modal"] = pg.evaluate("""() => ({ title: (document.querySelector('.modal h2, .modal .modal-head')||{}).innerText,
        fields: [...document.querySelectorAll('.modal .field')].map(f => ({ label: (f.querySelector('label')||{}).innerText,
            value: (f.querySelector('input,select,textarea')||{}).value })),
        buttons: [...document.querySelectorAll('.modal .modal-foot button')].map(b => b.innerText.trim()) })""")
    pg.screenshot(path=f"{OUT}/s16-payment-form.png")
    pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
    out["modal_closed_on_escape"] = pg.locator(".modal").count() == 0
    return out

@scenario("S17_contract_from_quotation")
def s17(pg):
    """T3.6 — kontrak dari penawaran yang menang (produksi 4 Sep 2026: QTN/2026/VIII/0008 Rp 2,04 M
    diketik ulang menjadi CTR/2026/VIII/0004 Rp 1,84 M tanpa tautan, ANALISIS-PROSES A1). Fixture lewat
    API: penawaran baru diajukan sales, disetujui direktur, Tandai Menang oleh sales — server mencetak
    cangkang CTR tanpa jadwal (QuotationService::markWon). Lalu di peramban sebagai sales: detail
    penawaran menawarkan "Lengkapi kontrak"; formulir kontraknya terisi dari penawaran; Simpan dengan
    nilai lain ditolak 422 yang menyebut kedua angka pada field "Alasan perubahan nilai"; diisi
    alasannya, tersimpan dan mendarat di kontrak yang NOMORNYA SAMA dengan cangkang, dengan baris
    "Dari penawaran QTN/…". Pemakai token API: sales (crm.create/update) dan direktur (crm.approve)."""
    sales = token_for("sales@nusantara.test"); direktur = token_for("direktur@nusantara.test")
    customer_id = api("crm/customers?per_page=1", sales)[1]["data"][0]["id"]
    s, q = api("crm/quotations", sales, "POST", {"customer_id": customer_id, "title": "UJI-UX — Instalasi CCTV & Akses Kontrol Gedung Parkir",
        "scope_type": "system_integration", "items": [{"description": "Instalasi CCTV 120 titik", "qty": 1, "unit": "ls", "unit_price": 1540000000},
        {"description": "Akses kontrol 24 pintu", "qty": 1, "unit": "ls", "unit_price": 500000000}]})
    qid, qcode = q["data"]["id"], q["data"]["code"]
    api(f"crm/quotations/{qid}/submit", sales, "POST", {}); api(f"crm/quotations/{qid}/approve", direktur, "POST", {})
    s, shell = api(f"crm/quotations/{qid}/mark-won", sales, "POST", {})
    out = {"quotation": qcode, "dpp": q["data"]["dpp"], "mark_won_status": s, "shell": shell["data"]["code"]}
    login(pg, "sales@nusantara.test")
    pg.goto(BASE + f"#/d/crm/quotations/{qid}")
    pg.wait_for_selector(f".page-head h1:has-text('{qcode}')", timeout=15000); pg.wait_for_timeout(800)
    KV = """(labels) => Object.fromEntries([...document.querySelectorAll('dl.kv dt')].filter(d => labels.includes(d.innerText.trim())).map(d => [d.innerText.trim(), d.nextElementSibling.innerText.trim()]))"""
    out.update(read_action_bar(pg, "s17-quotation-bar"))
    out["quotation_info"] = pg.evaluate(KV, ["No. kontrak"])
    click(pg, ".page-head .actions button:has-text('Lengkapi kontrak')")
    pg.wait_for_selector(".modal .field", timeout=10000); pg.wait_for_timeout(500)
    out["form_prefilled"] = pg.evaluate("""() => [...document.querySelectorAll('.modal .field')].filter(f => f.offsetParent !== null)
        .map(f => ({ label: (f.querySelector('label')||{}).innerText, value: (f.querySelector('input,select,textarea')||{}).value }))""")
    pg.screenshot(path=f"{OUT}/s17-contract-form.png")
    row = pg.locator(".modal table.lines tbody tr").first
    row.locator("td:nth-child(1) input").first.fill("Pelunasan 100%"); row.locator("td:nth-child(2) input").first.fill("100")
    # nilai lain dari DPP penawaran, tanpa alasan → 422 yang menyebut kedua angka, dilukis di field alasannya
    pg.locator(".modal .field", has=pg.locator("label", has_text="Nilai kontrak")).first.locator("input").first.fill("1840000000")
    click(pg, ".modal .modal-foot button:has-text('Simpan')"); pg.wait_for_timeout(1500)
    out["refused"] = {"errors": pg.evaluate("() => [...document.querySelectorAll('.modal .field .err, .modal td .err')].map(e=>e.innerText.trim())"),
                      "toasts": toasts(pg), "modal_open": pg.locator(".modal").count() > 0}
    pg.screenshot(path=f"{OUT}/s17-value-refused.png")
    pg.locator(".modal .field", has=pg.locator("label", has_text="Alasan perubahan nilai")).first.locator("textarea").first.fill(
        "UJI-UX — negosiasi akhir, lingkup akses kontrol dikurangi 8 pintu")
    click(pg, ".modal .modal-foot button:has-text('Simpan')")
    pg.wait_for_selector(".modal", state="detached", timeout=15000); pg.wait_for_timeout(1200)
    out["saved"] = {"hash": pg.evaluate("() => location.hash"), "h1": pg.evaluate("() => document.querySelector('.page-head h1').innerText"), "toasts": toasts(pg)}
    out["contract_info"] = pg.evaluate(KV, ["Dari penawaran", "Alasan perubahan nilai", "Penawaran"])
    out["dari_penawaran_text"] = pg.evaluate("() => (document.body.innerText.match(/Dari penawaran\\s*\\n?\\s*QTN\\/[\\w\\/]+/) || [null])[0]")
    pg.screenshot(path=f"{OUT}/s17-contract-detail.png")
    s, c = api(f"crm/contracts/{out['saved']['hash'].split('/')[-1]}", sales)
    out["api"] = {"code": c["data"]["code"], "quotation_code": c["data"]["quotation_code"], "value": c["data"]["value"],
                  "value_change_reason": c["data"]["value_change_reason"], "termins": len(c["data"]["termins"]), "status": c["data"]["status"]}
    out["contracts_for_quotation"] = sum(1 for r in api("crm/contracts?per_page=100", sales)[1]["data"] if r.get("quotation_id") == qid)
    pg.goto(BASE + f"#/d/crm/quotations/{qid}"); pg.wait_for_selector(f".page-head h1:has-text('{qcode}')", timeout=15000); pg.wait_for_timeout(800)
    out["quotation_after"] = {"action_bar": pg.evaluate(BAR), **pg.evaluate(KV, ["No. kontrak"])}
    return out


# ---------------------------------------------------------- onboarding v2
# Panel berlabuh / lembar bawah (masukan pemilik 5 Sep 2026: "show the intended page/location while user
# displayed the onboarding … also make it on mobile version"). Status onboarding di-reset langsung di
# sqlite (bukan PUT lewat token_for(): login dibatasi 10/menit/IP dan S18+S19 sudah dua kali masuk), dan
# dibaca kembali dari sqlite untuk membuktikan Lewati/Esc tercatat DI SERVER — bukan localStorage.
def reset_onboarding(email):
    con = sqlite3.connect(DB); con.execute("UPDATE users SET onboarding_status=NULL, onboarding_seen_at=NULL WHERE email=?", (email,)); con.commit(); con.close()

# Memutuskan status onboarding langsung di sqlite (verifikasi P1-B 5 Sep 2026): pada salinan DB hidup yang
# statusnya masih NULL, panel berlabuh terbuka SESUDAH pemeriksaan 'Lewati' satu kali milik S21 (fetchGuide
# mengulang sampai 2 × 1,5 s) dan langkah 1 memindah halaman ke #/dashboard — remah modul yang mau diklik
# lenyap, S21/S21m jatuh dengan TypeError. Skenario yang bukan tentang onboarding memutuskannya lebih dulu;
# S18/S19 mengatur ulang sendiri (reset_onboarding) sebelum menguji panelnya.
def decide_onboarding(email, status="skipped"):
    con = sqlite3.connect(DB); con.execute("UPDATE users SET onboarding_status=?, onboarding_seen_at=datetime('now') WHERE email=? AND onboarding_status IS NULL", (status, email)); con.commit(); con.close()

def onboarding_status(email):
    con = sqlite3.connect(DB); row = con.execute("SELECT onboarding_status FROM users WHERE email=?", (email,)).fetchone(); con.close()
    return row[0] if row else None

def tap(page, sel, **kw):
    CLICKS[0] += 1
    page.tap(sel, **kw)

DOCK = """() => { const d=document.querySelector('.onboarding-dock'); if(!d) return null; const r=d.getBoundingClientRect();
    const shell=document.querySelector('.shell').getBoundingClientRect();
    return { mode: d.dataset.mode, state: d.dataset.state, left: Math.round(r.left), top: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height),
      shell_right: Math.round(shell.right), counter: (d.querySelector('.onboarding-counter')||{}).innerText || null,
      heading: (d.querySelector('.onboarding-heading')||{}).innerText || null,
      chips: [...d.querySelectorAll('.onboarding-location')].map(c => ({ text: c.innerText.replace(/\\s+/g,' ').trim(), route: c.dataset.route, here: c.classList.contains('here') })),
      steps: [...d.querySelectorAll('.onboarding-step')].map(s => s.innerText.replace(/\\s+/g,' ').trim()),
      foot: [...d.querySelectorAll('.dock-foot .btn')].filter(b => !b.hidden).map(b => ({ label: b.innerText.trim(), h: Math.round(b.getBoundingClientRect().height), w: Math.round(b.getBoundingClientRect().width) })),
      bar: d.dataset.state === 'collapsed' ? { text: d.querySelector('.dock-bar').innerText.replace(/\\s+/g,' ').trim(), h: Math.round(d.querySelector('.dock-bar').getBoundingClientRect().height) } : null,
      spotlights: [...document.querySelectorAll('.spotlight:not([hidden])')].map(s => { const b=s.getBoundingClientRect(); return { label: (s.querySelector('.spotlight-label')||{}).innerText || null, w: Math.round(b.width), h: Math.round(b.height), x: Math.round(b.left), y: Math.round(b.top) } }),
      body_classes: [...document.body.classList], hash: location.hash, h1: (document.querySelector('#view .page-head h1')||{}).innerText || null } }"""

# Elemen yang benar-benar menerima klik di titik itu — bukti "tidak tertutup" yang lebih kuat daripada z-index.
HIT = """(sel) => { const e=document.querySelector(sel); if(!e) return null; const r=e.getBoundingClientRect();
    const t=document.elementFromPoint(r.left + r.width/2, r.top + r.height/2); return { visible: e.checkVisibility(), hit_is_self: !!t && (t === e || e.contains(t)), hit: t ? (t.getAttribute('class') || t.tagName) : null } }"""

@scenario("S18_onboarding_dock_desktop")
def s18(pg):
    """Onboarding v2 di desktop (1440×900) sebagai procurement@ yang belum memutuskan: panel berlabuh tampil
    ≤3 s sesudah masuk; halaman di bawahnya hidup (tautan sidebar diklik selagi panel terbuka → hash berganti,
    panel tetap ada, konten tidak tersembunyi di balik panel); Lanjut ke langkah 2 → sorotan sidebar; Lanjut ke
    langkah 3 → hash pindah ke rute yang izinnya dipegang (lokasi pertama bagian itu) dengan cincin sorotan pada
    tautan sidebar dan tombol Tambah; lipat → tab tepi "Onboarding 3/7", buka lagi; Esc pada buka-otomatis =
    Lewati (tercatat 'skipped' di sqlite); dibuka lagi dari menu akun → Tutup, Esc tidak mengubah status."""
    email = "procurement@nusantara.test"
    reset_onboarding(email)
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e).split("\n")[0][:160]))
    login(pg, email, onboarding=None)  # satu-satunya jalur yang MEMBIARKAN status NULL: panel inilah yang diuji
    t0 = time.time()
    pg.wait_for_selector(".onboarding-dock[data-state='open']", timeout=3000)
    out = {"dock_ms_after_login": int((time.time() - t0) * 1000), "status_before": onboarding_status(email)}
    pg.wait_for_timeout(700)
    out["step1"] = pg.evaluate(DOCK)
    pg.screenshot(path=f"{OUT}/s18-dock-step1.png")
    # halaman di bawah panel hidup: sidebar diklik selagi panel terbuka
    out["underneath_before"] = pg.evaluate(HIT, "nav.nav a[href='#/tenggat']")
    nav_click(pg, "#/tenggat"); pg.wait_for_timeout(900)
    d = pg.evaluate(DOCK)
    out["underneath"] = {"hash": d["hash"], "dock_present": d is not None, "state": d["state"], "shell_right_lte_dock_left": d["shell_right"] <= d["left"], "h1": d["h1"],
                         "userchip_hit": pg.evaluate(HIT, ".header .userchip")}
    # langkah 2: sorot sidebar
    click(pg, ".onboarding-dock .dock-foot button:has-text('Lanjut')"); pg.wait_for_timeout(900)
    d2 = pg.evaluate(DOCK)
    out["step2"] = {"hash": d2["hash"], "counter": d2["counter"], "spotlights": d2["spotlights"],
                    "ring_covers_nav": any(s["w"] >= 200 and s["h"] >= 600 for s in d2["spotlights"])}
    # langkah 3: pindah ke lokasi pertama yang bisa dibuka + sorotan
    click(pg, ".onboarding-dock .dock-foot button:has-text('Lanjut')"); pg.wait_for_timeout(1500)
    d3 = pg.evaluate(DOCK)
    perms = pg.evaluate("() => (JSON.parse(localStorage.getItem('nusantara_erp_user') || '{}').permissions || [])")
    out["step3"] = {"hash": d3["hash"], "counter": d3["counter"], "h1": d3["h1"], "chips": d3["chips"], "spotlights": d3["spotlights"],
                    "hash_changed": d3["hash"] != d2["hash"], "route_permitted": "prc.view" in perms and not pg.locator("#view .alert.error").count(),
                    "chip_here": [c["text"] for c in d3["chips"] if c["here"]], "active_nav": pg.evaluate("() => (document.querySelector('nav.nav a.active')||{}).innerText || null")}
    pg.screenshot(path=f"{OUT}/s18-dock-step3.png")
    # keping "Buka ›" kedua: pindah lagi, sorotan mengikuti
    click(pg, ".onboarding-dock .onboarding-location >> nth=1"); pg.wait_for_timeout(1500)
    d3b = pg.evaluate(DOCK)
    out["chip_click"] = {"hash": d3b["hash"], "h1": d3b["h1"], "chip_here": [c["text"] for c in d3b["chips"] if c["here"]], "spotlights": d3b["spotlights"]}
    # lipat → tab tepi; buka lagi
    click(pg, ".onboarding-dock .dock-head button[title='Lipat panel']"); pg.wait_for_timeout(300)
    dc = pg.evaluate(DOCK)
    out["collapsed"] = {"state": dc["state"], "bar": dc["bar"], "width": dc["width"], "body_classes": dc["body_classes"], "shell_right": dc["shell_right"]}
    pg.screenshot(path=f"{OUT}/s18-collapsed.png")
    click(pg, ".onboarding-dock .dock-bar"); pg.wait_for_timeout(300)
    out["reopened_state"] = pg.evaluate(DOCK)["state"]
    # Esc pada buka-otomatis = Lewati, tercatat di server
    pg.keyboard.press("Escape"); pg.wait_for_timeout(1200)
    out["escape"] = {"dock_gone": pg.locator(".onboarding-dock").count() == 0, "spotlights_gone": pg.locator(".spotlight").count() == 0,
                     "toasts": toasts(pg), "status_after": onboarding_status(email), "body_classes": pg.evaluate("() => [...document.body.classList]")}
    # dari menu akun: Tutup, bukan Lewati; Esc tidak mencatat apa pun
    click(pg, ".header .userchip"); pg.wait_for_selector(".modal", timeout=5000)
    click(pg, ".modal .modal-foot button:has-text('Panduan onboarding')")
    pg.wait_for_selector(".onboarding-dock[data-state='open']", timeout=5000); pg.wait_for_timeout(600)
    dr = pg.evaluate(DOCK)
    out["reopen"] = {"foot": [b["label"] for b in dr["foot"]], "again_button": pg.locator(".onboarding-dock button:has-text('Tampilkan lagi')").count() == 1, "hash": dr["hash"]}
    pg.keyboard.press("Escape"); pg.wait_for_timeout(800)
    out["reopen"]["escape_status"] = onboarding_status(email); out["reopen"]["dock_gone"] = pg.locator(".onboarding-dock").count() == 0
    out["pageerrors"] = errors
    return out

@scenario("S19_onboarding_sheet_mobile")
def s19(browser):
    """Onboarding v2 di ponsel (390×844, is_mobile, has_touch) sebagai procurement@: lembar bawah tampil ≤3 s,
    tinggi 55–60% layar, tombol drawer nav dan chip akun di header tetap tersentuh; kepingan langkah menggulir
    mendatar; tombol kaki selebar lembar dan ≥44 px; Lanjut → langkah 2 membuka drawer 2 detik dengan sorotan
    pada tombolnya; Lanjut → langkah 3 pindah halaman, lembar melipat ke bilah yang menyebut "Anda di: …",
    ketuk bilah → terbuka lagi; Lewati tercatat 'skipped' di sqlite."""
    email = "procurement@nusantara.test"
    reset_onboarding(email)
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e).split("\n")[0][:160]))
    login(pg, email, onboarding=None)  # seperti S18: lembar bawahnya yang diuji, jadi status tetap NULL
    t0 = time.time()
    pg.wait_for_selector(".onboarding-dock[data-mode='mobile'][data-state='open']", timeout=3000)
    out = {"sheet_ms_after_login": int((time.time() - t0) * 1000)}
    pg.wait_for_timeout(700)
    d = pg.evaluate(DOCK)
    out["sheet"] = {"height": d["height"], "height_pct": round(d["height"] / 844 * 100, 1), "top": d["top"], "counter": d["counter"], "foot": d["foot"],
                    "foot_full_width": all(b["w"] >= 170 for b in d["foot"]), "foot_min_h_44": all(b["h"] >= 44 for b in d["foot"]),
                    "chips_scroll": pg.evaluate("() => { const s=document.querySelector('.onboarding-dock .onboarding-steps'); return { scrollWidth: s.scrollWidth, clientWidth: s.clientWidth, scrolls: s.scrollWidth > s.clientWidth } }"),
                    "menu_toggle": pg.evaluate(HIT, ".header .menu-toggle"), "userchip": pg.evaluate(HIT, ".header .userchip"), "body_classes": d["body_classes"]}
    pg.screenshot(path=f"{OUT}/s19-sheet-step1.png")
    # kepingan langkah digulir dengan jari
    pg.evaluate("() => { const s=document.querySelector('.onboarding-dock .onboarding-steps'); s.scrollLeft = 200; }"); pg.wait_for_timeout(150)
    out["sheet"]["chips_scrolled_left"] = pg.evaluate("() => document.querySelector('.onboarding-dock .onboarding-steps').scrollLeft")
    # langkah 2: drawer terbuka 2 s + sorotan pada tombol drawer
    tap(pg, ".onboarding-dock .dock-foot button:has-text('Lanjut')"); pg.wait_for_timeout(600)
    d2 = pg.evaluate(DOCK)
    out["step2"] = {"counter": d2["counter"], "hash": d2["hash"], "drawer_open_at_0_6s": "nav-open" in d2["body_classes"], "spotlights": d2["spotlights"]}
    pg.screenshot(path=f"{OUT}/s19-step2-drawer.png")
    pg.wait_for_timeout(2000)
    out["step2"]["drawer_open_at_2_6s"] = pg.evaluate("() => document.body.classList.contains('nav-open')")
    # langkah 3: pindah halaman → lembar melipat ke bilah yang menyebut lokasi
    tap(pg, ".onboarding-dock .dock-foot button:has-text('Lanjut')"); pg.wait_for_timeout(1500)
    d3 = pg.evaluate(DOCK)
    out["step3"] = {"hash": d3["hash"], "hash_changed": d3["hash"] != d2["hash"], "state": d3["state"], "bar": d3["bar"], "h1": d3["h1"],
                    "bar_names_location": bool(d3["bar"]) and "Anda di:" in d3["bar"]["text"], "spotlights": d3["spotlights"],
                    "page_visible_above_bar": pg.evaluate("() => { const h=document.querySelector('#view .page-head h1'); const b=document.querySelector('.onboarding-dock .dock-bar').getBoundingClientRect(); const r=h.getBoundingClientRect(); return r.bottom < b.top }"),
                    "menu_toggle": pg.evaluate(HIT, ".header .menu-toggle")}
    pg.screenshot(path=f"{OUT}/s19-bar-step3.png")
    tap(pg, ".onboarding-dock .dock-bar"); pg.wait_for_timeout(400)
    d3b = pg.evaluate(DOCK)
    out["expanded"] = {"state": d3b["state"], "height_pct": round(d3b["height"] / 844 * 100, 1), "chips": d3b["chips"], "chip_here": [c["text"] for c in d3b["chips"] if c["here"]], "heading": d3b["heading"]}
    pg.screenshot(path=f"{OUT}/s19-expanded-step3.png")
    # pegangan: ketuk = lipat
    tap(pg, ".onboarding-dock .dock-handle"); pg.wait_for_timeout(300)
    out["handle_tap_collapses"] = pg.evaluate(DOCK)["state"] == "collapsed"
    tap(pg, ".onboarding-dock .dock-bar"); pg.wait_for_timeout(300)
    # Lewati → tercatat di server
    tap(pg, ".onboarding-dock .dock-foot button:has-text('Lewati')"); pg.wait_for_timeout(1200)
    out["skip"] = {"dock_gone": pg.locator(".onboarding-dock").count() == 0, "toasts": toasts(pg), "status_after": onboarding_status(email),
                   "body_classes": pg.evaluate("() => [...document.body.classList]"), "main_padding_bottom": pg.evaluate("() => getComputedStyle(document.querySelector('.main')).paddingBottom")}
    out["pageerrors"] = errors
    ctx.close()
    return out


# ------------------------------------------------------------ S20 (P1-A)
# Token grafik js/charts.js. Modul diimpor di konteks halaman lewat import('/app/js/charts.js')
# — php -S -t public melayani /app/js/*.js apa adanya, dan hash router tidak mengubah URL
# dokumen, jadi jalur absolut ini sama di server mana pun yang melayani public/. Setiap
# jenis grafik dirender dengan fixture (termasuk kasus kosong, celah NaN, satu titik, gantt
# terbuka) ke #s20 di body, lalu diukur dengan getComputedStyle di tema terang DAN gelap
# (data-theme di <html>, mekanisme yang sama dengan tombol tema app.js): warna terkomputasi
# tiap bentuk ber-data-token == nilai token itu, teks == --chart-text, jumlah <title> ==
# jumlah .mark, placeholder memuat "Belum ada data", gantt punya garis hari ini + rect akhir
# pekan. Nilai yang dicatat adalah angka terukur (hitungan, rasio kontras, lebar render),
# bukan boolean saja. Verifikasi P1-A (5 Sep 2026) menambah: neg_dims (atribut negatif yang
# ditolak Chromium) + console.error, geometri di luar viewBox (getBBox; legenda/catatan/label
# tick yang melampaui svg), klip garis & titik di luar sumbu paksa, tinggi legenda vs baris
# tergambar, ukuran huruf placeholder/donat/gantt terender per viewport, cincin donat irisan
# mungil, legenda irisan yang dikecualikan, gantt data tidak konsisten, label gantt terpotong
# ber-<title> (satu-satunya <title> di luar .mark), label baris vs kolom jadwal, teks yang
# HANYA cocok aturan .chart-lib (tabular-nums), dan pass cetak (abu-abu, pola garis/tepi).
CHART_RENDER = """async () => {
  const m = await import('/app/js/charts.js');
  const host = document.createElement('div'); host.id = 's20';
  host.style.cssText = 'position:absolute;top:0;left:0;right:0;z-index:999;padding:16px;background:var(--surface);color:var(--text)';
  document.body.appendChild(host);
  const add = (name, svg) => { const w = document.createElement('div'); w.dataset.name = name; w.style.cssText = 'margin:0 0 12px;max-width:760px';
    if (name.startsWith('gantt')) { const sc = document.createElement('div'); sc.className = 'chart-scroll'; sc.appendChild(svg); w.appendChild(sc); } else w.appendChild(svg);
    host.appendChild(w); return svg; };
  const rp = (v) => new Intl.NumberFormat('id-ID').format(v) + ' jt';
  const errors = [];
  const t = (name, fn) => { try { add(name, fn()); } catch (e) { errors.push(name + ': ' + e.message); } };
  t('line', () => m.lineChart({ series: [
      { label: 'Rencana', points: [{x:0,y:10},{x:1,y:20},{x:2,y:null},{x:3,y:40},{x:4,y:35},{x:5,y:60}], dashed: true },
      { label: 'Aktual', points: [{x:0,y:5},{x:1,y:NaN},{x:2,y:25},{x:3,y:30},{x:4,y:-5},{x:5,y:12}], area: true },
      { label: 'Biaya', points: [{x:0,y:2},{x:1,y:8},{x:2,y:14},{x:3,y:22},{x:4,y:30},{x:5,y:44}] }],
    xLabels: ['M1','M2','M3','M4','M5','M6'], yFormat: (v) => v + ' %', ariaLabel: 'uji garis', sourceNote: 'Sumber: fixture harness S20' }));
  t('line_single', () => m.lineChart({ series: [{ label: 'Satu', points: [{x:0,y:7}] }], ariaLabel: 'satu titik' }));
  // Paritas tiga grafik tangan (P1-E): dash per seri ('5 3' rencana vs '2 4' baseline), token
  // eksplisit, dots:false / 'last', <title> gabungan per titik, jari-jari titik as-of, token per
  // titik (GRN vs PO pada satu garis), yStep 25 dengan yMax 125 (aturan EVM >100 %).
  t('line_api', () => m.lineChart({ series: [
      { label: 'Rencana', points: [{x:0,y:10},{x:1,y:40},{x:2,y:70},{x:3,y:100}], dash: '5 3', token: '--chart-8', dots: false },
      { label: 'Aktual', points: [{x:0,y:8,title:'Minggu 1 — rencana 10 %, aktual 8 %'},{x:1,y:35,title:'Minggu 2 — rencana 40 %, aktual 35 %'},{x:2,y:60,title:'Minggu 3 — rencana 70 %, aktual 60 %',r:4,token:'--chart-2'}], area: true },
      { label: 'Baseline', points: [{x:0,y:12},{x:1,y:45},{x:2,y:72},{x:3,y:118}], dash: '2 4', dots: 'last' }],
    yMin: 0, yMax: 125, yStep: 25, yFormat: (v) => v + ' %', ariaLabel: 'API paritas grafik tangan' }));
  // points[].r ≤ 0 / bukan angka dan token yang tidak terdefinisi: dulu r="-3" (console.error Chromium,
  // titik tidak digambar), r="0" (.mark tak terlihat ber-<title>), '--chart-nope' → stroke:none diam-diam.
  t('line_r_edge', () => m.lineChart({ series: [
      { label: 'r tepi', points: [{x:0,y:1},{x:1,y:2,r:-3},{x:2,y:3,r:0},{x:3,y:4,r:'x'},{x:4,y:5,r:2.5,token:'--chart-nope'}] },
      { label: 'token asing', points: [{x:0,y:2},{x:1,y:3}], token: '--chart-nope' }], ariaLabel: 'jari-jari & token tepi' }));
  // Skala x campuran (tanggal + angka + 'abc'): yang bukan tanggal dibuang, bukan diformat "01 Jan 70".
  // Sumbu tanggal tanpa xFormat: label bawaan harus berbentuk fmt.date ("05 Sep 2026"), bukan "05 Sep 26".
  t('line_dates', () => m.lineChart({ series: [{ label: 'Harga PO', points: [{x:'2026-01-05',y:12500},{x:'2026-03-02',y:13000},{x:'2026-06-10',y:13750}] }], yMin: 12000, yMax: 14000, yFormat: (v) => 'Rp ' + new Intl.NumberFormat('id-ID').format(v), ariaLabel: 'sumbu tanggal' }));
  t('line_mixed_x', () => m.lineChart({ series: [{ label: 'campur', points: [{x:'2026-01-01',y:1},{x:5,y:3},{x:'abc',y:2},{x:'2026-02-01',y:4}] }], ariaLabel: 'x campuran' }));
  // Seri yang semua x-nya dibuang (indeks pada sumbu tanggal) atau semua y-nya null: legenda harus mengatakannya '(tanpa data)'.
  t('line_series_nodata', () => m.lineChart({ series: [{ label: 'tanggal', points: [{x:'2026-01-01',y:1},{x:'2026-02-01',y:3}] }, { label: 'indeks', points: [{x:0,y:2},{x:1,y:4}] }, { label: 'kosong', points: [{x:'2026-01-01',y:null},{x:'2026-02-01',y:NaN}] }], ariaLabel: 'seri tanpa data' }));
  // Sumbu tanggal padat (P1-E: tren harga harian, kurva-S 52 minggu, EVM bulanan): label terakhir
  // ditambatkan ke ujung kanan dan bergeser ±30 px — verifikasi P1-A putaran 2 mengukur dua label
  // terakhir bertumpuk 17–54 px pada setiap sumbu tanggal ≥ 10 titik (line_dates hanya 3 titik).
  // x_label_overlaps (getBBox semua label sumbu-x) harus 0 di desktop DAN pada lebar 358.
  const dated = (n, stepDays, k = 0) => Array.from({length: n}, (_, i) => ({ x: new Date(Date.UTC(2026, 8, 1 + i * stepDays)).toISOString().slice(0, 10), y: 10 + ((i + k) * 37) % 50 }));
  t('line_dates_daily_31', () => m.lineChart({ series: [{ label: 'Harga PO', points: dated(31, 1).map(p => ({ x: p.x, y: 12000 + p.y * 40 })) }], yFormat: (v) => 'Rp ' + new Intl.NumberFormat('id-ID').format(v), ariaLabel: 'tanggal harian 31' }));
  t('line_dates_weekly_52', () => m.lineChart({ series: [
      { label: 'Rencana', points: dated(52, 7).map((p, i) => ({ x: p.x, y: Math.round(100 * (1 - Math.cos(Math.PI * i / 51)) / 2) })), dash: '5 3', dots: false },
      { label: 'Aktual', points: dated(40, 7).map((p, i) => ({ x: p.x, y: Math.round(90 * (1 - Math.cos(Math.PI * i / 51)) / 2) })), area: true, dots: 'last' },
      { label: 'Baseline', points: dated(52, 7).map((p, i) => ({ x: p.x, y: Math.round(100 * (1 - Math.cos(Math.PI * i / 45)) / 2) })), dash: '2 4', dots: false }],
    yMin: 0, yMax: 125, yStep: 25, yFormat: (v) => v + ' %', ariaLabel: 'kurva-S 52 minggu' }));
  t('line_dates_monthly_24', () => m.lineChart({ series: [{ label: 'Biaya', points: dated(24, 30).map(p => ({ x: p.x, y: p.y * 1e8 })) }], yFormat: (v) => 'Rp ' + new Intl.NumberFormat('id-ID').format(v), ariaLabel: 'tanggal bulanan 24' }));
  t('line_dates_w358', () => m.lineChart({ series: [{ label: 'Harga PO', points: dated(30, 1).map(p => ({ x: p.x, y: 12000 + p.y * 40 })) }], width: 358, yFormat: (v) => 'Rp ' + new Intl.NumberFormat('id-ID').format(v), ariaLabel: 'tanggal harian lebar 358' }));
  // Sumbu dipaksa 0..100 dengan nilai 140 dan −20: dulu titik+garis terlukis 73 px di atas svg
  // (menimpa kepala kartu) — kini garis diklip dan titiknya ditempel di tepi plot + "(di luar sumbu)".
  t('line_clip', () => m.lineChart({ series: [{ label: 'Progres', points: [{x:0,y:10},{x:1,y:60},{x:2,y:140},{x:3,y:-20},{x:4,y:50}], area: true }], yMin: 0, yMax: 100, yFormat: (v) => v + ' %', ariaLabel: 'di luar sumbu' }));
  t('line_empty', () => m.lineChart({ series: [{ label: 'Kosong', points: [{x:0,y:null},{x:1,y:NaN}] }], ariaLabel: 'kosong' }));
  // 8 seri berlabel 28 huruf + sumbu Rp (PAD.left 120) + catatan sumber: legenda membungkus 4 baris —
  // tinggi viewBox harus dihitung dari tata letak yang sama (dulu 3 baris, catatan 16 px di luar svg).
  const longLabel = (i) => ('Seri ' + i + ' ' + 'x'.repeat(40)).slice(0, 28);
  const rpAxis = (v) => 'Rp ' + new Intl.NumberFormat('id-ID').format(v);
  t('line_legend_wrap', () => m.lineChart({ series: [1,2,3,4,5,6,7,8].map(i => ({ label: longLabel(i), points: [{x:0,y:1e9*i},{x:1,y:2e9*i}] })), yFormat: rpAxis, sourceNote: 'Sumber: fixture legenda 8 seri', ariaLabel: 'legenda membungkus' }));
  t('bar_legend_wrap', () => m.barChart({ categories: ['A','B','C'], series: [1,2,3,4,5,6,7,8].map(i => ({ label: longLabel(i), values: [1e9*i, 2e9*i, 1.5e9*i] })), yFormat: rpAxis, sourceNote: 'Sumber: fixture', ariaLabel: 'legenda batang membungkus' }));
  t('bar', () => m.barChart({ categories: ['Jan','Feb','Mar','Apr'], series: [{ label: 'RAP', values: [3,-2,5,4] },{ label: 'Realisasi', values: [1,4,null,6] }], yFormat: rp, ariaLabel: 'uji batang' }));
  t('bar_stacked', () => m.barChart({ categories: ['Proyek A','Proyek B','Proyek C'], series: [{ label: 'Material', values: [3,2,1] },{ label: 'Upah', values: [1,4,2] },{ label: 'Alat', values: [-1,1,0] }], stacked: true, ariaLabel: 'tumpuk' }));
  t('bar_horizontal', () => m.barChart({ categories: ['Gudang Utama Jakarta Selatan','Gudang 2','Gudang 3'], series: [{ label: 'Stok', values: [30,12,0] }], horizontal: true, ariaLabel: 'mendatar' }));
  // Label nilai mendatar 'Rp 1.000.000.000,00' (103 px) × 5 tick: yang terakhir ditambatkan ke ujung kanan — dijarangkan dengan kotak yang sama.
  t('bar_horizontal_rp', () => m.barChart({ categories: ['Proyek A','Proyek B','Proyek C'], series: [{ label: 'Nilai', values: [1e9, 2e9, 5e8] }], horizontal: true, yFormat: (v) => 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2 }).format(v), ariaLabel: 'mendatar rupiah' }));
  t('bar_empty', () => m.barChart({ categories: [], series: [], ariaLabel: 'kosong' }));
  // 12 nama bulan pada lebar 720 (pita 55,7 px): 'Februari' 41 px / 'September' 54 px muat — dulu
  // CHAR_W 6,3 memotongnya jadi 'Februa…', 'Septem…', 'Novemb…', 'Desemb…'.
  t('bar_months', () => m.barChart({ categories: ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'], series: [{ label: 'RAP', values: [1,2,3,4,5,6,7,8,9,10,11,12] }], ariaLabel: 'dua belas bulan' }));
  // 100 kategori × 6 seri = 600 batang pada lebar 720: barW < 1 px — sebelum verifikasi P1-A
  // width="-0.2" (Chromium menolak, 0 batang tergambar, 600 <title> tetap ada).
  t('bar_dense', () => m.barChart({ categories: Array.from({length: 100}, (_, i) => 'K' + (i + 1)), series: Array.from({length: 6}, (_, i) => ({ label: 'S' + (i + 1), values: Array.from({length: 100}, (_, j) => (j * 7919 + i) % 97) })), ariaLabel: 'batang rapat 600' }));
  t('donut', () => m.donutChart({ slices: [{label:'Disetujui',value:60},{label:'Menunggu',value:30},{label:'Ditolak',value:10},{label:'Draf',value:0}], centerLabel: '100', centerSub: 'dokumen', ariaLabel: 'donat' }));
  t('donut_one', () => m.donutChart({ slices: [{label:'Semua',value:5}], ariaLabel: 'satu irisan' }));
  // Nilai negatif / tak terukur tidak digambar, tetapi harus tetap disebut legenda (dulu hilang tanpa jejak).
  t('donut_excluded', () => m.donutChart({ slices: [{label:'Retur',value:-5},{label:'Disetujui',value:5},{label:'Tak terukur',value:NaN}], ariaLabel: 'irisan dikecualikan' }));
  t('donut_empty', () => m.donutChart({ slices: [], ariaLabel: 'kosong' }));
  // Semua baris dikecualikan (negatif/NaN/teks): dulu placeholder polos 'Belum ada data' menelan ketiganya tanpa jejak.
  t('donut_all_excluded', () => m.donutChart({ slices: [{label:'Retur',value:-5},{label:'Tak terukur',value:NaN},{label:'Teks',value:'x'},{label:'Nol',value:0}], ariaLabel: 'semua dikecualikan' }));
  // Label 55 huruf: dulu viewBox melebar ke 560 mengikuti label lalu menyusut ×0,64 di ponsel (teks 7 px);
  // kini lebar tetap 360 dan legenda dibungkus per kata (data-lines > 1) — ukuran huruf sama dengan donat lain.
  t('donut_long', () => m.donutChart({ slices: [{label:'Kategori dengan nama yang sangat panjang sekali sekali',value:1},{label:'B',value:2},{label:'Rp 10 M',value:1e10}], valueFormat: (v) => new Intl.NumberFormat('id-ID').format(v), ariaLabel: 'label panjang' }));
  // Verifikasi P1-A putaran 3: label HURUF BESAR / angka polos (nama proyek yang lazim) — 6,1–8,6 px/glyph, bukan 5,6:
  // dulu dibungkus 21 huruf × CHAR_W lalu 'PEMBANGUNAN GEDUNG' (137 px) berakhir 9,5 px di luar viewBox 360; kini dibungkus
  // menurut taksiran per kelas glyph dan grup legenda diklip ke viewBox (legend_texts_past_right harus 0, legend_clipped 1).
  t('donut_uppercase', () => m.donutChart({ slices: [{label:'PEMBANGUNAN GEDUNG KANTOR PUSAT JAKARTA SELATAN',value:5e9},{label:'PENGADAAN ALAT BERAT 2026',value:3e9},{label:'1234567890 1234567890',value:1e9}], valueFormat: (v) => new Intl.NumberFormat('id-ID').format(v), ariaLabel: 'label huruf besar' }));
  // Sembilan seri/irisan: token berulang setelah 8 (seri 9 = --chart-1) dan blok cetak memberi seri 2..8 pola masing-masing.
  t('donut_9', () => m.donutChart({ slices: Array.from({length: 9}, (_, i) => ({ label: 'Bagian ' + (i + 1), value: i + 1 })), ariaLabel: 'sembilan irisan' }));
  t('line_9_series', () => m.lineChart({ series: Array.from({length: 9}, (_, i) => ({ label: 'S' + (i + 1), points: [{x:0,y:i},{x:1,y:i+1}] })), ariaLabel: 'sembilan seri' }));
  // Irisan 99,9999 %: busur ≥ 359,99° yang ujungnya berimpit setelah pembulatan hilang dari
  // path (cakram tanpa lubang / tidak tergambar) — diukur getTotalLength cincin ≈ 2π(84+56) = 880.
  t('donut_tiny', () => m.donutChart({ slices: [{label:'Rp 10 M',value:1e10},{label:'Rp 100 rb',value:1e5}], ariaLabel: 'irisan mungil' }));
  t('spark', () => m.sparkline({ points: [1,3,2,null,5,4,6], ariaLabel: 'spark' }));
  t('spark_single', () => m.sparkline({ points: [2], ariaLabel: 'spark satu' }));
  t('spark_empty', () => m.sparkline({ points: [null, NaN], ariaLabel: 'spark kosong' }));
  const rows = [
    { label: 'Persiapan', start: '2026-08-24', end: '2026-09-04', progress: 1, baselineStart: '2026-08-24', baselineEnd: '2026-09-02', level: 0 },
    { label: 'Mobilisasi alat', start: '2026-08-26', end: '2026-09-01', progress: 1, level: 1 },
    { label: 'Pekerjaan tanah dan galian pondasi', start: '2026-09-01', end: '2026-09-18', progress: 0.4, baselineStart: '2026-08-31', baselineEnd: '2026-09-14', level: 0 },
    { label: 'Galian', start: '2026-09-01', end: '2026-09-09', progress: 0.8, level: 1 },
    { label: 'Urugan', start: '2026-09-08', end: '2026-09-18', progress: 0.1, level: 1 },
    { label: 'Struktur bawah', start: '2026-09-14', end: null, progress: 0, baselineStart: '2026-09-12', baselineEnd: '2026-10-02', level: 0 },
    { label: 'Pengadaan besi', start: null, end: '2026-09-20', level: 1 },
    { label: 'Belum dijadwalkan: pengadaan material finishing tahap kedua', level: 1 },
    { label: 'Struktur atas', start: '2026-10-01', end: '2026-10-30', progress: 0, level: 0 },
    { label: 'Finishing', start: '2026-10-20', end: '2026-11-30', level: 0 },
  ];
  t('gantt_week', () => m.ganttChart({ rows, from: '2026-08-24', to: '2026-10-11', zoom: 'week', today: '2026-09-05', ariaLabel: 'gantt minggu', sourceNote: 'Sumber: fixture' }));
  t('gantt_month', () => m.ganttChart({ rows, from: '2026-06-01', to: '2026-12-31', zoom: 'month', today: '2026-09-05', ariaLabel: 'gantt bulan' }));
  t('gantt_week_long', () => m.ganttChart({ rows, from: '2026-06-01', to: '2026-12-31', zoom: 'week', today: '2026-09-05', ariaLabel: 'gantt minggu rentang sama' }));
  t('gantt_empty', () => m.ganttChart({ rows: [], ariaLabel: 'gantt kosong' }));
  // Data tidak konsisten: dulu end<start jadi bar 1 px bertitle terbalik, '2026-13-45' jadi
  // "14 Feb 2027", progress 7 jadi "100 %", baris di luar rentang tanpa keterangan apa pun.
  t('gantt_invalid', () => m.ganttChart({ rows: [
      { label: 'Terbalik', start: '2026-09-10', end: '2026-09-02' },
      { label: 'Tanggal rusak', start: 'abc', end: '2026-13-45' },
      { label: 'Progres 700 %', start: '2026-09-01', end: '2026-09-20', progress: 7 },
      { label: 'Progres negatif', start: '2026-09-01', end: '2026-09-20', progress: -1 },
      { label: 'Sebelum rentang', start: '2026-01-01', end: '2026-01-10' },
      // Baris terbuka di luar rentang: dulu batas rentang (01 Sep / 30 Sep 2026) dicetak sebagai tanggal tugas.
      { label: 'Buka-awal lampau', start: null, end: '2026-01-10' },
      { label: 'Buka-akhir mendatang', start: '2027-01-01', end: null },
      // Baris berteks + baseline: dulu rect baseline 240 px digambar DI BAWAH teks catatan (tak terbaca), dan
      // baris 'tanpa tanggal' dengan baseline terbalik diam saja (catatannya hanya ada di <title> bar yang tidak ada).
      { label: 'Rusak + baseline', start: 'abc', end: '2026-13-45', baselineStart: '2026-09-03', baselineEnd: '2026-09-12' },
      { label: 'Tanpa tanggal + baseline', baselineStart: '2026-09-03', baselineEnd: '2026-09-12' },
      { label: 'Tanpa tanggal, baseline terbalik', baselineStart: '2026-09-20', baselineEnd: '2026-09-01' },
      { label: 'Baseline di luar rentang', start: '2026-09-02', end: '2026-09-10', baselineStart: '2026-01-03', baselineEnd: '2026-01-12' },
      { label: 'Baseline terbalik', start: '2026-09-03', end: '2026-09-12', baselineStart: '2026-09-20', baselineEnd: '2026-09-01' },
      { label: 'Benar', start: '2026-09-02', end: '2026-09-10', progress: 0.5 }],
    from: '2026-09-01', to: '2026-09-30', today: '2026-09-05', ariaLabel: 'gantt data tidak konsisten' }));
  // Satu-satunya baseline di luar rentang: tidak ada rect baseline, jadi legenda tidak boleh menyebut 'Baseline'.
  t('gantt_baseline_outside', () => m.ganttChart({ rows: [{ label: 'Benar, baseline Januari', start: '2026-09-02', end: '2026-09-10', baselineStart: '2026-01-03', baselineEnd: '2026-01-12' }], from: '2026-09-01', to: '2026-09-30', today: '2026-09-05', ariaLabel: 'baseline di luar rentang' }));
  return { errors, charts: host.querySelectorAll('svg').length, exports: Object.keys(m).sort() };
}"""

CHART_MEASURE = """(theme) => {
  document.documentElement.dataset.theme = theme;
  const root = getComputedStyle(document.documentElement);
  const v = (n) => root.getPropertyValue(n).trim();
  const rgb = (hex) => { const h = hex.replace('#',''); const n = parseInt(h.length === 3 ? h.split('').map(c => c + c).join('') : h, 16); return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`; };
  const lum = (hex) => { const c = hex.match(/\\w\\w/g).map(x => parseInt(x, 16) / 255).map(x => x <= .03928 ? x / 12.92 : ((x + .055) / 1.055) ** 2.4); return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]; };
  const cr = (a, b) => { const l1 = lum(a), l2 = lum(b); return +(((Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05)).toFixed(2)); };
  const names = [1,2,3,4,5,6,7,8].map(i => `--chart-${i}`).concat(['grid','axis','text','today','weekend','baseline'].map(k => `--chart-${k}`));
  const tokens = Object.fromEntries(names.map(n => [n, v(n)]));
  const surface = v('--surface');
  const contrast = Object.fromEntries([1,2,3,4,5,6,7,8].map(i => [`--chart-${i}`, cr(tokens[`--chart-${i}`], surface)]));
  contrast['--chart-text'] = cr(tokens['--chart-text'], surface);
  const charts = {};
  // Label sumbu-x = baris teks di bawah sumbu (y > y2 sumbu + 4); tumpang tindih diukur getBBox
  // (koordinat svg, bebas skala) atas SEMUA pasangan tetangga — termasuk label terakhir yang
  // ditambatkan ke ujung, yang dulu luput karena pemeriksaan lama hanya melihat label kategori
  // bertumpu tengah.
  const bottomLabels = (svg, bottom) => [...svg.querySelectorAll('text.chart-tick')].filter(t => +t.getAttribute('y') > bottom + 4);
  const overlaps = (els) => { const b = els.map(t => t.getBBox()).sort((p, q) => p.x - q.x); const ov = b.map((x, i) => i ? b[i - 1].x + b[i - 1].width - x.x : 0); return { count: ov.filter(o => o > 0.5).length, max_px: +Math.max(0, ...ov).toFixed(1) }; };
  document.querySelectorAll('#s20 [data-name]').forEach(w => {
    const svg = w.querySelector('svg'); const name = w.dataset.name;
    const painted = [...svg.querySelectorAll('[data-token]')]; const mismatch = [];
    painted.forEach(el => { const got = getComputedStyle(el)[el.dataset.paint]; const want = rgb(tokens[el.dataset.token] || v(el.dataset.token)); if (got !== want) mismatch.push(`${el.tagName}.${el.getAttribute('class')} ${el.dataset.token}/${el.dataset.paint}: ${got} != ${want}`); });
    const texts = [...svg.querySelectorAll('text:not([data-token])')];
    // Pembeda: --chart-text == --muted di kedua tema, jadi fill saja tidak membedakan aturan
    // .chart-lib text dari .chart text (yang juga cocok). tabular-nums hanya diberi .chart-lib.
    const textOk = texts.filter(t => { const cs = getComputedStyle(t); return cs.fill === rgb(tokens['--chart-text']) && cs.fontVariantNumeric === 'tabular-nums'; }).length;
    const fonts = [...new Set(texts.map(t => getComputedStyle(t).fontFamily.split(',')[0].trim()))];
    const numeric = [...new Set(texts.map(t => getComputedStyle(t).fontVariantNumeric))];
    const box = svg.getBoundingClientRect(); const vb = svg.viewBox.baseVal;
    const scale = vb.width ? box.width / vb.width : 1;
    const negDims = [...svg.querySelectorAll('rect, circle')].filter(e => ['width', 'height', 'r'].some(a => e.hasAttribute(a) && parseFloat(e.getAttribute(a)) < 0)).length;
    // <title> yang sah: tepat satu per .mark, plus nama lengkap pada label gantt yang dipotong (data-truncated).
    const strayTitles = [...svg.querySelectorAll('title')].filter(t => !t.parentElement.classList.contains('mark') && !(t.parentElement.classList.contains('gantt-label') && t.parentElement.dataset.truncated)).length;
    const c = { painted: painted.length, painted_ok: painted.length - mismatch.length, mismatch, marks: svg.querySelectorAll('.mark').length, titles: svg.querySelectorAll('title').length, mark_titles: svg.querySelectorAll('.mark > title').length, stray_titles: strayTitles, neg_dims: negDims,
      texts: texts.length, text_ok: textOk, fonts, font_variant_numeric: numeric, empty: svg.dataset.empty === 'true', empty_text: (svg.querySelector('.chart-empty') || {}).textContent || null,
      empty_font_px: svg.dataset.empty === 'true' ? +(parseFloat(getComputedStyle(svg.querySelector('.chart-empty')).fontSize) * scale).toFixed(1) : null,
      empty_sub: (svg.querySelector('.chart-empty-sub') || {}).textContent || null, excluded_rows: svg.dataset.excluded ? +svg.dataset.excluded : null,
      width: Math.round(box.width), height: Math.round(box.height), viewbox_w: vb.width, rendered_font_px: +(11 * scale).toFixed(1), series_tokens: [...new Set(painted.filter(e => e.dataset.token.match(/--chart-\\d/)).map(e => e.dataset.token))] };
    // Geometri di luar viewBox (getBBox dalam koordinat svg): legenda/catatan yang melampaui tinggi,
    // label tick yang keluar tepi kanan, titik di luar plot — .chart { overflow: visible } melukisnya
    // di atas elemen berikutnya. Path yang diklip dikecualikan (getBBox = geometri sebelum klip).
    const outside = [...svg.querySelectorAll('circle, rect, line, text:not([clip-path]), path:not([clip-path])')].filter(e => !e.closest('defs')).filter(e => { try { const b = e.getBBox(); return b.y + b.height > vb.height + 0.5 || b.y < -0.5 || b.x + b.width > vb.width + 0.5 || b.x < -0.5; } catch (x) { return false; } });
    c.outside_viewbox = outside.length; c.outside_viewbox_sample = outside.slice(0, 3).map(e => e.tagName + '.' + (e.getAttribute('class') || '') + ' ' + (e.textContent || '').slice(0, 20));
    // px/glyph terukur label tick (getBBox lebar ÷ jumlah huruf) — pembanding untuk taksiran CHAR_W 5,6 charts.js (docblock menyebut 'September' 6,02 dan angka polos 6,12 melampauinya).
    const glyphs = [...svg.querySelectorAll('text.chart-tick')].filter(t => t.textContent.length >= 3).map(t => t.getBBox().width / t.textContent.length); c.tick_glyph_px_max = glyphs.length ? +Math.max(...glyphs).toFixed(2) : null;
    const legendEls = [...svg.querySelectorAll('text.chart-legend')]; const legendYs = legendEls.map(t => +t.getAttribute('y')); const note = svg.querySelector('text.chart-note');
    // Teks entri legenda: donat membungkus per baris ke <tspan> (dy 14) — digabung dengan spasi supaya sama dengan teks satu barisnya.
    const legendText = (t) => t.querySelector('tspan') ? [...t.querySelectorAll('tspan')].map(sp => sp.textContent).join(' ') : t.textContent;
    // Tepi bawah legenda dari getBBox (entri donat bisa 2–3 baris; y atribut hanya baris pertamanya), catatan sumber dari y-nya.
    if (legendEls.length) { c.legend_rows = new Set(legendYs).size; c.legend_overflow_px = +(Math.max(...legendEls.map(t => { const b = t.getBBox(); return b.y + b.height; }), note ? +note.getAttribute('y') + 4 : 0) - vb.height).toFixed(1); }
    if (name.startsWith('line')) { c.series_line_paths = svg.querySelectorAll('path.series-line').length; c.series1_segments = svg.querySelectorAll('path.series-line[data-series="1"]').length; c.zero_line = svg.querySelectorAll('.chart-zero').length;
      const clipRect = svg.querySelector('clipPath rect'); const top = clipRect ? +clipRect.getAttribute('y') + 2 : 14; const bottom = clipRect ? top + +clipRect.getAttribute('height') - 4 : 232;
      c.clipped_paths = svg.querySelectorAll('path.series-line[clip-path], path.series-area[clip-path]').length; c.unclipped_paths = svg.querySelectorAll('path.series-line:not([clip-path]), path.series-area:not([clip-path])').length;
      const dots = [...svg.querySelectorAll('circle.series-point')]; c.dots_outside_plot = dots.filter(d => +d.getAttribute('cy') < top - 0.5 || +d.getAttribute('cy') > bottom + 0.5).length;
      c.dots_marked_outside = dots.filter(d => d.dataset.outside).length; c.outside_titles = [...svg.querySelectorAll('circle[data-outside] title')].map(t => t.textContent);
      const xl = bottomLabels(svg, bottom); c.x_labels = xl.map(t => t.textContent); c.dropped_x = +(svg.dataset.droppedX || 0);
      const xo = overlaps(xl); c.x_label_overlaps = xo.count; c.x_label_max_overlap_px = xo.max_px; c.x_label_anchors = [...new Set(xl.map(t => t.getAttribute('text-anchor')))];
      c.two_digit_year_labels = c.x_labels.filter(l => /^\d{2} \w{3} \d{2}$/.test(l)).length;
      c.y_ticks = [...svg.querySelectorAll('text.chart-tick[text-anchor="end"]')].filter(t => +t.getAttribute('y') <= bottom + 4).map(t => t.textContent);
      c.series_dash = [...svg.querySelectorAll('path.series-line')].map(p => p.dataset.series + ':' + (p.getAttribute('stroke-dasharray') || 'solid'));
      c.dots_by_series = dots.reduce((a, d) => { a[d.dataset.series] = (a[d.dataset.series] || 0) + 1; return a; }, {});
      c.dot_tokens = [...new Set(dots.map(d => d.dataset.token))]; c.dot_radii = [...new Set(dots.map(d => +d.getAttribute('r')))];
      // Seri di legenda tanpa satu pun path/titik yang tidak mengatakan '(tanpa data)'.
      const drawnSeries = new Set([...svg.querySelectorAll('path.series-line, circle.series-point')].map(e => e.dataset.series));
      c.legend_series_silent_nodata = [...svg.querySelectorAll('text.chart-legend')].filter(t => { const sw = t.previousElementSibling; return sw && sw.dataset.series && !drawnSeries.has(sw.dataset.series) && !t.dataset.nodata; }).length;
      c.legend_nodata = svg.querySelectorAll('text.chart-legend[data-nodata]').length;
      c.dots_r_not_positive = dots.filter(d => !(+d.getAttribute('r') > 0)).length; c.undefined_tokens = [...svg.querySelectorAll('[data-token]')].filter(e => !v(e.dataset.token)).length;
      c.line_stroke_none = [...svg.querySelectorAll('path.series-line')].filter(p => getComputedStyle(p).stroke === 'none').length;
      c.line_tokens = [...svg.querySelectorAll('path.series-line')].map(p => p.dataset.series + ':' + p.dataset.token); c.custom_titles = [...svg.querySelectorAll('circle.series-point title')].map(t => t.textContent).filter(t => /^Minggu/.test(t)).length; c.fabricated_1970 = [...svg.querySelectorAll('text, title')].filter(t => /\b70\b|1970/.test(t.textContent)).length; }
    if (name.startsWith('bar')) { c.zero_line = svg.querySelectorAll('.chart-zero').length; const bars = [...svg.querySelectorAll('rect.series-bar')]; c.bars = bars.length; c.bar_min_thickness = bars.length ? Math.min(...bars.map(r => +r.getAttribute(name.includes('horizontal') ? 'height' : 'width'))) : null;
      const axisEl = svg.querySelector('.chart-axis'); const xl = axisEl ? bottomLabels(svg, +axisEl.getAttribute('y2')) : [];
      const catLabels = name.includes('horizontal') ? [...svg.querySelectorAll('text.chart-tick[text-anchor="end"]')].filter(t => !xl.includes(t)) : xl;
      c.category_labels = catLabels.map(t => t.textContent); c.truncated_labels = catLabels.filter(t => /…$/.test(t.textContent)).length;
      // Label vs lebar sebenarnya: semua label baris bawah (kategori tegak / nilai mendatar) yang tumpang tindih dengan tetangganya (getBBox, koordinat svg).
      c.x_labels = xl.map(t => t.textContent); const xo = overlaps(xl); c.label_overlaps = xo.count; c.x_label_overlaps = xo.count; c.x_label_max_overlap_px = xo.max_px; }
    if (name.startsWith('donut')) { c.full_ring = svg.querySelectorAll('circle.mark').length; c.legend_items = legendEls.map(legendText); c.legend_excluded = svg.querySelectorAll('text.chart-legend[data-excluded]').length;
      c.legend_lines_max = Math.max(0, ...legendEls.map(t => +(t.dataset.lines || 1))); c.legend_right_px = +Math.max(0, ...legendEls.map(t => { const b = t.getBBox(); return b.x + b.width; })).toFixed(1);
      // Teks legenda yang tepi kanannya melewati viewBox (getBBox — geometri, bukan lukisan: klip tidak menyembunyikannya), px/glyph terukur per baris tspan
      // (pembanding tabel GLYPH_CLASSES charts.js), dan apakah grup legenda diklip ke viewBox (jaring terakhir untuk font klien yang lebih lebar).
      c.legend_texts_past_right = legendEls.filter(t => { const b = t.getBBox(); return b.x + b.width > vb.width + 0.5; }).length;
      const spans = legendEls.flatMap(t => [...t.querySelectorAll('tspan')]).filter(sp => sp.textContent.length >= 3); c.legend_glyph_px_max = spans.length ? +Math.max(...spans.map(sp => sp.getBBox().width / sp.textContent.length)).toFixed(2) : null;
      const group = svg.querySelector('g.chart-legend-group'); c.legend_clipped = group && group.getAttribute('clip-path') && svg.querySelector('clipPath rect') ? 1 : 0; c.slice_lengths = [...svg.querySelectorAll('path.series-slice')].map(p => +p.getTotalLength().toFixed(1)); c.biggest_slice_is_ring = c.slice_lengths.length ? Math.max(...c.slice_lengths) >= 2 * Math.PI * (84 + 56) - 2 : null; }
    if (name.startsWith('gantt')) {
      c.today_lines = svg.querySelectorAll('.gantt-today').length; c.today_label = (svg.querySelector('.gantt-today-label') || {}).textContent || null;
      c.weekend_rects = svg.querySelectorAll('.gantt-weekend').length; c.ticks = svg.querySelectorAll('.gantt-tick').length; c.tick_labels = svg.querySelectorAll('.gantt-tick-label').length;
      c.rows = svg.querySelectorAll('.gantt-label').length; c.bars = svg.querySelectorAll('.gantt-bar').length; c.baselines = svg.querySelectorAll('.gantt-baseline').length;
      c.truncated_labels = svg.querySelectorAll('.gantt-label[data-truncated]').length; c.truncated_with_full_title = [...svg.querySelectorAll('.gantt-label[data-truncated]')].filter(t => t.querySelector('title') && t.querySelector('title').textContent === t.dataset.full).length;
      c.open_titles = [...svg.querySelectorAll('.gantt-bar[data-open] title')].map(t => t.textContent);
      const noteEls = [...svg.querySelectorAll('.gantt-nodate, .gantt-invalid, .gantt-outside')]; c.row_notes = noteEls.map(t => t.getAttribute('class').split(' ')[0] + ': ' + t.textContent);
      // Catatan baris vs bar baseline: kotak teks yang beririsan dengan rect baseline (getBBox) — teks di atas rect tak terbaca.
      const baseEls = [...svg.querySelectorAll('.gantt-baseline')]; const inter = (a, b) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;
      c.note_over_baseline = noteEls.filter(n => baseEls.some(b => inter(n.getBBox(), b.getBBox()))).length; c.baseline_notes = noteEls.filter(n => /baseline/.test(n.textContent)).length;
      c.bar_titles = [...svg.querySelectorAll('.gantt-bar title')].map(t => t.textContent); c.fabricated_dates = c.bar_titles.filter(t => /2027/.test(t)).length;
      // Catatan 'di luar rentang' yang mengutip batas rentang fixture (01 Sep / 30 Sep 2026) sebagai tanggal tugas — baris terbuka tidak punya tanggal itu.
      c.outside_notes = [...svg.querySelectorAll('.gantt-outside')].map(t => t.textContent); c.outside_notes_quoting_window = c.outside_notes.filter(t => /01 Sep 2026|30 Sep 2026/.test(t)).length;
      c.legend_items = [...svg.querySelectorAll('text.chart-legend')].map(t => t.textContent);
      // Legenda 'Baseline' hanya bila ada rect baseline yang tergambar (baseline di luar rentang tidak punya rect).
      c.baseline_legend_dishonest = c.legend_items.includes('Baseline') !== (c.baselines > 0) ? 1 : 0;
      c.baseline_before_actual = [...svg.querySelectorAll('.gantt-baseline')].every(b => { const bar = b.nextElementSibling; return bar && bar.classList.contains('gantt-bar') && !!(b.compareDocumentPosition(bar) & Node.DOCUMENT_POSITION_FOLLOWING); });
      // Label baris vs jadwal: label yang menembus kolom jadwal (bbox kanan > x sumbu = labelWidth) —
      // pemeriksaan lama membandingkan baris yang berjarak rowHeight secara konstruksi (selalu 0).
      const axis = svg.querySelector('.chart-axis'); const axisX = axis ? +axis.getAttribute('x1') : null; // placeholder gantt tidak punya sumbu
      c.label_into_timeline = axisX === null ? 0 : [...svg.querySelectorAll('.gantt-label')].filter(t => { const b = t.getBBox(); return b.x + b.width > axisX - 2; }).length;
      const sc = w.querySelector('.chart-scroll'); c.scroll_width = sc.scrollWidth; c.client_width = sc.clientWidth; c.scrolls = sc.scrollWidth > sc.clientWidth + 1; c.min_width = svg.style.minWidth;
    }
    charts[name] = c;
  });
  const total = Object.values(charts);
  return { theme, tokens, surface, contrast, charts,
    summary: { charts: total.length, painted: total.reduce((a, c) => a + c.painted, 0), mismatches: total.reduce((a, c) => a + c.mismatch.length, 0),
      marks: total.reduce((a, c) => a + c.marks, 0), titles: total.reduce((a, c) => a + c.titles, 0), mark_titles: total.reduce((a, c) => a + c.mark_titles, 0), titles_equal_marks: total.every(c => c.marks === c.mark_titles), stray_titles: total.reduce((a, c) => a + c.stray_titles, 0),
      neg_dims: total.reduce((a, c) => a + c.neg_dims, 0), outside_viewbox: total.reduce((a, c) => a + c.outside_viewbox, 0),
      dots_outside_plot: total.reduce((a, c) => a + (c.dots_outside_plot || 0), 0), unclipped_paths: total.reduce((a, c) => a + (c.unclipped_paths || 0), 0),
      legend_overflow_max_px: Math.max(...total.map(c => c.legend_overflow_px ?? -999)),
      // Verifikasi P1-A putaran 2: label sumbu-x yang bertumpuk (getBBox, semua fixture garis+batang) — dulu 17–54 px pada sumbu tanggal ≥ 10 titik.
      x_label_overlaps: total.reduce((a, c) => a + (c.x_label_overlaps || 0), 0), x_label_max_overlap_px: Math.max(0, ...total.map(c => c.x_label_max_overlap_px || 0)),
      x_label_charts: total.filter(c => c.x_label_overlaps !== undefined).length,
      tick_glyph_px_max: Math.max(...total.map(c => c.tick_glyph_px_max ?? 0)),
      texts: total.reduce((a, c) => a + c.texts, 0), text_ok: total.reduce((a, c) => a + c.text_ok, 0), empty_charts: total.filter(c => c.empty).length,
      empty_with_text: total.filter(c => c.empty && /^Belum ada data/.test(c.empty_text || '')).length,
      // Donat yang semua barisnya dikecualikan: placeholder harus menyebut jumlah baris dan alasannya (data-excluded), bukan 'Belum ada data' polos.
      excluded_placeholders_silent: total.filter(c => c.empty && c.excluded_rows && !c.empty_sub).length + Object.entries(charts).filter(([n, c]) => n === 'donut_all_excluded' && c.empty && !c.excluded_rows).length,
      // Placeholder harus tetap terbaca di ponsel: viewBox 720/900 menyusutkan teksnya ke 5,5/4,4 px (diukur 5 Sep 2026).
      empty_min_font_px: Math.min(...total.filter(c => c.empty).map(c => c.empty_font_px)),
      // Donat: ukuran huruf tidak boleh bergantung pada panjang label (donut 4 irisan vs donut_one vs donut_tiny).
      donut_font_px: Object.fromEntries(Object.entries(charts).filter(([n, c]) => n.startsWith('donut') && !c.empty).map(([n, c]) => [n, c.rendered_font_px])),
      // Verifikasi P1-A putaran 2: lebar tetap 360 → ukuran huruf donat sama untuk semua fixture (donut_long 55 huruf dulu 7 px di ponsel), legenda dibungkus (baris maks) dan tetap di dalam viewBox.
      donut_font_min_px: Math.min(...Object.entries(charts).filter(([n, c]) => n.startsWith('donut') && !c.empty).map(([, c]) => c.rendered_font_px)),
      donut_viewbox_widths: [...new Set(Object.entries(charts).filter(([n, c]) => n.startsWith('donut') && !c.empty).map(([, c]) => c.viewbox_w))],
      donut_legend_lines_max: Math.max(0, ...Object.entries(charts).filter(([n]) => n.startsWith('donut')).map(([, c]) => c.legend_lines_max || 0)),
      donut_legend_right_max_px: Math.max(0, ...Object.entries(charts).filter(([n]) => n.startsWith('donut')).map(([, c]) => c.legend_right_px || 0)),
      // Verifikasi P1-A putaran 3: teks legenda donat yang melewati tepi kanan viewBox (dulu 2 pada donut_uppercase, 369,5 px), px/glyph terukur maks, dan jumlah donat berlegenda yang grupnya diklip.
      donut_legend_texts_past_right: total.reduce((a, c) => a + (c.legend_texts_past_right || 0), 0),
      donut_legend_glyph_px_max: Math.max(0, ...total.map(c => c.legend_glyph_px_max || 0)),
      donut_legend_clipped: total.filter(c => c.legend_clipped).length + '/' + Object.entries(charts).filter(([n, c]) => n.startsWith('donut') && !c.empty).length,
      // Lantai ukuran huruf terender per viewport: gantt punya min-width 80 % (≥ 8,8 px) — garis/batang 720 px
      // di ponsel 390 (5,5 px) adalah pertanyaan terbuka P1-A (lebar per viewport), dicatat, belum dipaku.
      gantt_min_font_px: Math.min(...Object.entries(charts).filter(([n, c]) => n.startsWith('gantt') && !c.empty).map(([, c]) => c.rendered_font_px)),
      line_bar_min_font_px: Math.min(...Object.entries(charts).filter(([n, c]) => (n.startsWith('line') || n.startsWith('bar')) && !c.empty).map(([, c]) => c.rendered_font_px)),
      label_into_timeline: total.reduce((a, c) => a + (c.label_into_timeline || 0), 0),
      outside_notes_quoting_window: total.reduce((a, c) => a + (c.outside_notes_quoting_window || 0), 0),
      note_over_baseline: total.reduce((a, c) => a + (c.note_over_baseline || 0), 0), baseline_legend_dishonest: total.reduce((a, c) => a + (c.baseline_legend_dishonest || 0), 0),
      legend_series_silent_nodata: total.reduce((a, c) => a + (c.legend_series_silent_nodata || 0), 0), dots_r_not_positive: total.reduce((a, c) => a + (c.dots_r_not_positive || 0), 0), undefined_tokens: total.reduce((a, c) => a + (c.undefined_tokens || 0), 0), line_stroke_none: total.reduce((a, c) => a + (c.line_stroke_none || 0), 0),
      min_series_contrast: Math.min(...Object.values(contrast)) } };
}"""

# Blok cetak: token seri jadi abu-abu — kontras tiap seri vs kertas putih, jarak antar-tetangga,
# --chart-7 vs --chart-grid, dan pembeda BENTUK (pola putus garis per seri, pola garis tepi
# batang/irisan/swatch per seri). Diukur dengan emulate_media('print') di atas fixture S20.
CHART_PRINT = """() => {
  const root = getComputedStyle(document.documentElement); const v = (n) => root.getPropertyValue(n).trim();
  const lum = (hex) => { const c = hex.match(/\\w\\w/g).map(x => parseInt(x, 16) / 255).map(x => x <= .03928 ? x / 12.92 : ((x + .055) / 1.055) ** 2.4); return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]; };
  const cr = (a, b) => { const l1 = lum(a), l2 = lum(b); return +(((Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05)).toFixed(2)); };
  const paper = v('--surface'); const series = {}; const neighbours = {};
  for (let i = 1; i <= 8; i++) series['--chart-' + i] = { hex: v('--chart-' + i), vs_paper: cr(v('--chart-' + i), paper) };
  for (let i = 1; i < 8; i++) neighbours[i + '-' + (i + 1)] = cr(v('--chart-' + i), v('--chart-' + (i + 1)));
  const dash = (sel) => [...document.querySelectorAll(sel)].reduce((a, e) => { a[e.dataset.series] = getComputedStyle(e).strokeDasharray; return a; }, {});
  const lineDash = dash('#s20 [data-name="line_9_series"] path.series-line'); const barDash = dash('#s20 [data-name="bar_legend_wrap"] rect.series-bar'); const sliceDash = dash('#s20 [data-name="donut_9"] path.series-slice');
  const distinct = (o) => new Set(Object.entries(o).filter(([k]) => +k <= 8).map(([, d]) => d)).size;
  return { print: matchMedia('print').matches, paper, series, neighbours, min_vs_paper: Math.min(...Object.values(series).map(s => s.vs_paper)), min_neighbour: Math.min(...Object.values(neighbours)),
    chart7_vs_grid: cr(v('--chart-7'), v('--chart-grid')), line_dash: lineDash, bar_outline_dash: barDash, slice_outline_dash: sliceDash,
    distinct_line_dashes: distinct(lineDash), distinct_bar_outlines: distinct(barDash), distinct_slice_outlines: distinct(sliceDash),
    bar_outline_width: getComputedStyle(document.querySelector('#s20 [data-name="bar_legend_wrap"] rect.series-bar')).strokeWidth }; }"""

def chart_tokens(pg, tag):
    errors = []
    console_errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
    # Atribut SVG yang ditolak Chromium (width negatif dsb.) tidak melempar — hanya console.error.
    pg.on("console", lambda m: console_errors.append(m.text[:160]) if m.type == "error" else None)
    login(pg, "admin@nusantara.test")
    out = {"render": pg.evaluate(CHART_RENDER)}
    for theme in ("light", "dark"):
        out[theme] = pg.evaluate(CHART_MEASURE, theme)
        pg.wait_for_timeout(150)
        pg.locator("#s20").screenshot(path=f"{OUT}/s20-chart-tokens-{theme}{tag}.png")
    pg.evaluate("() => { delete document.documentElement.dataset.theme; }")
    pg.emulate_media(media="print")
    out["print"] = pg.evaluate(CHART_PRINT)
    pg.locator("#s20 [data-name='bar_legend_wrap']").screenshot(path=f"{OUT}/s20-chart-print-bars{tag}.png")
    pg.emulate_media(media="screen")
    out["pageerrors"] = errors
    out["console_errors"] = {"count": len(console_errors), "first": console_errors[:3]}
    out["viewport"] = pg.viewport_size
    return out

@scenario("S20_chart_tokens")
def s20(pg):
    return chart_tokens(pg, "")

@scenario("S20_chart_tokens_mobile")
def s20m(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        return chart_tokens(pg, "-mobile")
    finally:
        ctx.close()

# ------------------------------------------------------------ S20e (P1-E)
#
# Tiga grafik tangan yang pindah ke charts.js: kurva-S proyek, kurva EVM, tren
# harga satuan. S20 mengukur token pada grafik SINTETIS di sandbox; di sini yang
# dibuka adalah LAYAR SUNGGUHAN dengan data demo, karena yang bisa hilang dalam
# migrasi bukan tokennya melainkan FITURNYA — sumbu EVM yang boleh melewati
# 100 %, sumbu harga yang tidak dipaksa mulai dari nol, celah kurva baseline
# sebelum sampel pertama, titik as-of yang lebih besar, dan kalimat <title> yang
# menyebut ketiga angka sekaligus.
#
# ROADMAP menuliskan kriteria "diff piksel ≤ 2 %". Angka itu DIUKUR di sini
# (bukan diasumsikan) terhadap tangkapan layar sebelum migrasi yang disimpan di
# repositori — dan hasilnya dilaporkan apa adanya, termasuk ketika ia melampaui
# 2 %: legenda yang pindah ke dalam svg dan warna yang pindah ke token
# kategorikal mengubah piksel dengan sengaja. Yang tidak boleh berubah adalah
# daftar fitur di bawahnya.

CHART_INVENTORY = """(sel) => {
  const svg = document.querySelector(sel);
  if (!svg) return null;
  const cs = (n, p) => getComputedStyle(n)[p];
  const resolve = (t) => getComputedStyle(document.documentElement).getPropertyValue(t).trim();
  const lines = [...svg.querySelectorAll('path.series-line')];
  const pts = [...svg.querySelectorAll('circle.series-point, .series-point')];
  return {
    cls: svg.getAttribute('class'),
    aria: svg.getAttribute('aria-label'),
    lib: (svg.getAttribute('class') || '').includes('chart-lib'),
    series: lines.map((l) => ({
      token: l.dataset.token || null,
      stroke: cs(l, 'stroke'),
      expected: l.dataset.token ? resolve(l.dataset.token) : null,
      dash: cs(l, 'strokeDasharray'),
      // Tebal garis: seri yang DIUKUR lebih tebal daripada seri acuannya,
      // seperti `.chart .act` grafik tangan. Pemindahan P1-E menuliskan 2 untuk
      // semuanya dan hierarki itu hilang tanpa disebut (verifikasi P1-E).
      width: parseFloat(cs(l, 'strokeWidth')),
    })),
    areas: svg.querySelectorAll('path.series-area').length,
    points: pts.length,
    point_radii: [...new Set(pts.map((p) => p.getAttribute('r')))].sort(),
    point_tokens: [...new Set(pts.map((p) => p.dataset.token || null))].sort(),
    marks_with_title: [...svg.querySelectorAll('.mark')].filter((m) => m.querySelector('title')).length,
    marks: svg.querySelectorAll('.mark').length,
    titles: [...svg.querySelectorAll('title')].map((t) => t.textContent),
    ticks: [...svg.querySelectorAll('text.chart-tick')].map((t) => t.textContent),
    legend: [...svg.querySelectorAll('text.chart-legend')].map((t) => t.textContent),
    outside: svg.querySelectorAll('[data-outside]').length,
    box: (() => { const r = svg.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) }; })(),
    // Label tepi tidak boleh keluar dari kotak svg — yang persis dilanggar
    // grafik tangan tren harga sebelum migrasi.
    text_outside_viewbox: (() => {
      const vb = (svg.getAttribute('viewBox') || '0 0 0 0').split(' ').map(Number);
      return [...svg.querySelectorAll('text')].filter((t) => {
        const b = t.getBBox();
        return b.x < vb[0] - 0.5 || b.x + b.width > vb[0] + vb[2] + 0.5;
      }).map((t) => t.textContent);
    })(),
  };
}"""


def _pixel_diff(before, after):
    """Bagian piksel yang berubah pada irisan kedua gambar, plus luas di luarnya.

    Tanpa dependensi: dekodernya `_read_png` di bawah. Sampai verifikasi P1-E
    fungsi ini memanggil Pillow dan menyerah dengan sopan bila tidak ada — dan
    host tempat harness ini benar-benar dijalankan TIDAK memasang Pillow, jadi
    satu-satunya angka yang roadmap tuntut untuk pemindahan grafik dilaporkan
    "tidak tersedia" setiap kali. Sebuah angka yang tidak pernah terukur bukan
    kriteria.
    """
    if not (os.path.exists(before) and os.path.exists(after)):
        return {"available": False, "reason": "tangkapan layar pembanding tidak ada"}

    aw, ah, a = _read_png(before)
    bw, bh, b = _read_png(after)
    if a is None or b is None:
        return {"available": False, "reason": "PNG tidak bisa dibaca dekoder bawaan"}

    w, h = min(aw, bw), min(ah, bh)
    changed = 0
    for y in range(h):
        ra, rb = y * aw * 3, y * bw * 3
        for x in range(w):
            ia, ib = ra + x * 3, rb + x * 3
            # Toleransi 16/255 per kanal: anti-alias sub-piksel bukan perubahan.
            if (abs(a[ia] - b[ib]) > 16 or abs(a[ia + 1] - b[ib + 1]) > 16
                    or abs(a[ia + 2] - b[ib + 2]) > 16):
                changed += 1

    frame = max(aw, bw) * max(ah, bh)
    outside = frame - (w * h)
    return {
        "available": True,
        "before": [aw, ah], "after": [bw, bh],
        "changed_pct": round(changed / (w * h) * 100, 2),
        "outside_intersection_pct": round(outside / frame * 100, 2),
    }


def _read_png(path):
    """PNG 8-bit non-interlaced -> (lebar, tinggi, bytes RGB). (0, 0, None) bila tak terbaca.

    Dekoder sendiri, bukan Pillow. Alasannya diukur: host produksi ini TIDAK
    memasang Pillow, jadi satu-satunya angka yang dijanjikan roadmap untuk
    pemindahan grafik ("diff piksel") dilaporkan "tidak tersedia" persis di
    tempat ia paling dibutuhkan — dan sebuah metrik yang selalu menyerah dengan
    sopan tidak pernah menjadi gerbang (verifikasi P1-E). Format yang dibaca
    adalah format yang benar-benar ditulis Playwright: 8 bit per kanal, tanpa
    interlace; bentuk lain menjawab None dan pemanggilnya melaporkannya.
    """
    try:
        data = open(path, "rb").read()
        if data[:8] != b"\x89PNG\r\n\x1a\n":
            return 0, 0, None

        pos, idat, w, h, ctype = 8, b"", 0, 0, 0
        while pos + 8 <= len(data):
            ln = struct.unpack(">I", data[pos:pos + 4])[0]
            typ = data[pos + 4:pos + 8]
            body = data[pos + 8:pos + 8 + ln]
            if typ == b"IHDR":
                w, h, bitd, ctype, _, _, interlace = struct.unpack(">IIBBBBB", body)
                if bitd != 8 or interlace != 0 or ctype not in (2, 6):
                    return 0, 0, None
            elif typ == b"IDAT":
                idat += body
            elif typ == b"IEND":
                break
            pos += 12 + ln

        ch = 3 if ctype == 2 else 4
        raw = zlib.decompress(idat)
        stride = w * ch
        rgb = bytearray(w * h * 3)
        prev = bytearray(stride)
        at = 0
        for y in range(h):
            f = raw[at]
            at += 1
            line = bytearray(raw[at:at + stride])
            at += stride
            if f == 1:
                for i in range(ch, stride):
                    line[i] = (line[i] + line[i - ch]) & 0xFF
            elif f == 2:
                for i in range(stride):
                    line[i] = (line[i] + prev[i]) & 0xFF
            elif f == 3:
                for i in range(stride):
                    left = line[i - ch] if i >= ch else 0
                    line[i] = (line[i] + ((left + prev[i]) >> 1)) & 0xFF
            elif f == 4:
                for i in range(stride):
                    left = line[i - ch] if i >= ch else 0
                    up = prev[i]
                    ul = prev[i - ch] if i >= ch else 0
                    p = left + up - ul
                    pa, pb, pc = abs(p - left), abs(p - up), abs(p - ul)
                    line[i] = (line[i] + (left if (pa <= pb and pa <= pc) else (up if pb <= pc else ul))) & 0xFF
            base = y * w * 3
            for x in range(w):
                rgb[base + x * 3:base + x * 3 + 3] = line[x * ch:x * ch + 3]
            prev = line
        return w, h, bytes(rgb)
    except Exception:
        return 0, 0, None


@scenario("S20e_migrated_charts")
def s20e(pg):
    login(pg, "admin@nusantara.test")
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))

    out = {"charts": {}, "pageerrors": errors}

    def grab(key, sel, shot):
        pg.wait_for_selector(sel, timeout=20000)
        info = pg.evaluate(CHART_INVENTORY, sel)
        pg.locator(sel).first.screenshot(path=f"{OUT}/{shot}-sesudah-p1e.png")
        info["pixel_diff"] = _pixel_diff(f"{SPA_EVIDENCE}/{shot}-sebelum-p1e.png", f"{OUT}/{shot}-sesudah-p1e.png")
        out["charts"][key] = info
        return info

    # Kurva-S dan kurva EVM hidup di halaman proyek yang sama; kartu EVM dimuat
    # setelah kurva-S, jadi masing-masing ditunggu dengan selektornya sendiri.
    pg.evaluate("() => { location.hash = '#/d/projects/1'; }")
    pg.wait_for_timeout(1200)
    scurve = grab("kurva_s", "svg[aria-label*='Kurva-S']", "s20e-kurva-s")
    evm = grab("evm", "svg[aria-label*='EVM']", "s20e-evm")

    pg.evaluate("() => { location.hash = '#/harga-satuan'; }")
    pg.wait_for_timeout(2500)
    if not pg.locator("svg[aria-label*='Tren harga']").count():
        pg.locator("select").first.select_option(index=1)
        pg.wait_for_timeout(2500)
    trend = grab("tren_harga", "svg[aria-label*='Tren harga']", "s20e-tren-harga")

    light_series = {
        "kurva_s": [(x["token"], x["stroke"]) for x in scurve["series"]],
        "evm": [(x["token"], x["stroke"]) for x in evm["series"]],
        "tren_harga": [(x["token"], x["stroke"]) for x in trend["series"]],
    }

    # Tema gelap: yang diperiksa BUKAN gambarnya melainkan bahwa setiap garis
    # tetap mengambil warnanya dari tokennya — token --chart-* punya nilai
    # sendiri di blok gelap, jadi sebuah warna yang ter-hardcode akan lolos di
    # terang dan ketahuan di sini.
    pg.evaluate("() => { document.documentElement.dataset.theme = 'dark'; }")
    pg.wait_for_timeout(250)
    dark = {
        "tren_harga": pg.evaluate(CHART_INVENTORY, "svg[aria-label*='Tren harga']"),
    }
    pg.evaluate("() => { location.hash = '#/d/projects/1'; }")
    pg.wait_for_selector("svg[aria-label*='EVM']", timeout=20000)
    dark["kurva_s"] = pg.evaluate(CHART_INVENTORY, "svg[aria-label*='Kurva-S']")
    dark["evm"] = pg.evaluate(CHART_INVENTORY, "svg[aria-label*='EVM']")
    pg.locator("svg[aria-label*='Kurva-S']").first.screenshot(path=f"{OUT}/s20e-kurva-s-gelap-p1e.png")
    out["dark"] = {k: [(x["token"], x["stroke"], x["expected"]) for x in v["series"]] for k, v in dark.items() if v}
    pg.evaluate("() => { delete document.documentElement.dataset.theme; }")

    # ---- daftar fitur yang TIDAK boleh hilang, dinyatakan sebagai syarat ----
    # Sumbu tren harga atas deret harga yang BERGERAK — data demo punya dua
    # harga yang sama, dan itulah satu-satunya bentuk yang lolos aturan lama.
    # Digambar dengan lineChart yang dikapalkan, memakai rumus yLo/yHi/yStep
    # milik hargasatuan.js sendiri, di halaman yang sedang diuji.
    trend_axis = pg.evaluate("""async () => {
      const m = await import("/app/js/charts.js");
      const host = document.createElement("div");
      document.body.appendChild(host);
      const axisOf = (prices) => {
        const lo = Math.min(...prices), hi = Math.max(...prices);
        const room = (hi - lo) * 0.25 || hi * 0.05 || 1;
        const yLo = Math.max(0, lo - room), yHi = hi + room;
        host.replaceChildren(m.lineChart({
          series: [{ label: "Harga", points: prices.map((v, i) => ({ x: i, y: v })) }],
          width: 1112, height: 260, legend: false,
          yMin: yLo, yMax: yHi, yStep: (yHi - yLo) / 4, yFormat: (v) => String(Math.round(v)),
        }));
        const ticks = [...host.querySelectorAll("text.chart-tick")]
          .filter((t) => t.getAttribute("text-anchor") === "end")
          .map((t) => parseFloat(t.textContent));
        return { ticks, yLo, yHi };
      };
      const rising = axisOf([12500, 18750, 31000]);
      const near = (a, b) => Math.abs(a - b) <= Math.max(1, Math.abs(b) * 1e-6);
      host.remove();
      return {
        rising_ticks: rising.ticks,
        rising_gridlines: rising.ticks.length,
        edges_labelled: rising.ticks.length > 1
          && near(rising.ticks[0], Math.round(rising.yLo))
          && near(rising.ticks[rising.ticks.length - 1], Math.round(rising.yHi)),
      };
    }""")
    out["trend_axis_rising"] = trend_axis

    # TITIK YANG DIKECUALIKAN `dots:false`, dan legendanya.
    #
    # charts.js tetap menggambar run SATU TITIK walau pemanggilnya menulis
    # `dots:false` — tanpa garis maupun titik nilainya tidak terlihat sama
    # sekali. Yang salah sampai verifikasi P1-E adalah UKURANNYA: r 4, sama
    # dengan titik as-of EVM yang justru satu-satunya titik yang dicari orang,
    # 2,7 px di sebelahnya. Dan sebuah seri yang seluruh datanya terpencil
    # (biaya aktual berlubang) kehilangan garisnya sama sekali sementara
    # legendanya tetap menggambar swatch GARIS untuk garis yang tidak ada.
    # Dua bentuk itu digambar di sini dengan lineChart yang dikapalkan.
    dots_probe = pg.evaluate("""async () => {
      const m = await import("/app/js/charts.js");
      const host = document.createElement("div");
      document.body.appendChild(host);
      const draw = (series) => {
        host.replaceChildren(m.lineChart({ series, yMin: 0, yMax: 100, yStep: 25,
          yFormat: (v) => `${v}%`, ariaLabel: "uji" }));
        return {
          radii: [...host.querySelectorAll("circle.series-point:not(.legend-swatch)")].map((c) => +c.getAttribute("r")),
          lines: host.querySelectorAll("path.series-line").length,
          swatches: [...host.querySelectorAll(".legend-swatch")].map((n) => n.tagName),
        };
      };
      const fresh = draw([
        { label: "baseline", token: "--chart-8", dash: "2 4", dots: false,
          points: [{ x: "2026-01-05", y: 0 }, { x: "2026-02-05", y: 20 }, { x: "2026-03-05", y: 60 }] },
        { label: "EV", token: "--chart-1",
          points: [{ x: "2026-01-05", y: 0.75, r: 4 }, { x: "2026-02-05", y: null }, { x: "2026-03-05", y: null }] },
        { label: "AC", token: "--chart-2", dots: false,
          points: [{ x: "2026-01-05", y: 0.75 }, { x: "2026-02-05", y: null }, { x: "2026-03-05", y: null }] },
      ]);
      const gap = draw([
        { label: "baseline", token: "--chart-8", dash: "2 4", dots: false,
          points: [{ x: "2026-01-05", y: 10 }, { x: "2026-02-05", y: 40 }, { x: "2026-03-05", y: 80 }] },
        { label: "AC", token: "--chart-2", dots: false,
          points: [{ x: "2026-01-05", y: 5 }, { x: "2026-02-05", y: null }, { x: "2026-03-05", y: 30 }] },
      ]);
      host.remove();
      return { fresh, gap };
    }""")
    out["dots_false_probe"] = dots_probe

    # KERTAS. Blok cetak app.css mengabukan token --chart-* dan memberi setiap
    # seri pola putusnya; sampai verifikasi P1-E deklarasi itu juga MENIMPA
    # pola yang ditulis pemanggil (deklarasi CSS mengalahkan atribut
    # presentasi), jadi baseline yang ditulis "2 4" dan garis EV yang UTUH di
    # layar tercetak sebagai dua pola titik yang hanya berbeda 1 px celah, dan
    # "Aktual" kurva-S yang utuh di layar menjadi garis paling putus di
    # kertas. Dan legenda DOM tren harga tidak tercetak sama sekali pada
    # setelan bawaan Chrome (grafik latar mati) — dua label tanpa satu swatch.
    pg.emulate_media(media="print")
    pg.wait_for_timeout(300)
    print_state = pg.evaluate("""() => {
      const svgs = [...document.querySelectorAll("svg.chart-lib")];
      const chart = (svg) => [...svg.querySelectorAll("path.series-line")].map((l) => ({
        series: l.dataset.series,
        authored: l.getAttribute("stroke-dasharray"),
        printed: getComputedStyle(l).strokeDasharray,
      }));
      return { charts: svgs.map(chart) };
    }""")
    pg.emulate_media(media="screen")
    pg.wait_for_timeout(200)

    pg.evaluate("""() => { location.hash = "#/harga-satuan"; }""")
    pg.wait_for_selector("svg[aria-label*='Tren harga']", timeout=20000)
    pg.wait_for_timeout(1200)
    pg.emulate_media(media="print")
    pg.wait_for_timeout(300)
    print_state["trend_legend"] = pg.evaluate("""() => [...document.querySelectorAll(".legend i")].map((i) => {
      const cs = getComputedStyle(i);
      const r = i.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height), adjust: cs.printColorAdjust || cs.webkitPrintColorAdjust };
    })""")
    print_state["trend_point_radii"] = pg.evaluate(
        "() => [...new Set([...document.querySelectorAll('circle.series-point:not(.legend-swatch)')]"
        ".map((c) => parseFloat(c.getAttribute('r'))))].sort((a, b) => a - b)")
    pg.emulate_media(media="screen")
    out["print"] = print_state

    # Pola yang DITULIS pemanggil selamat di kertas; yang tidak menulis apa pun
    # tetap mendapat pola cetaknya (itulah alasan blok cetak ada).
    authored = [l for c in print_state["charts"] for l in c if l["authored"]]
    print_state["authored_dash_survives_print"] = bool(authored) and all(
        l["printed"].replace("px", "").replace(",", "") == l["authored"] for l in authored)
    # Swatch legenda tren harga: tercetak (print-color-adjust) DAN berbeda
    # UKURAN, bukan hanya berbeda abu-abu.
    legend = print_state["trend_legend"]
    print_state["trend_legend_prints_and_differs_by_shape"] = (
        len(legend) == 2
        and all(i["adjust"] == "exact" for i in legend)
        and legend[0]["w"] != legend[1]["w"]
    )
    print_state["trend_points_differ_by_more_than_half_a_pixel"] = (
        len(print_state["trend_point_radii"]) == 2
        and print_state["trend_point_radii"][1] - print_state["trend_point_radii"][0] >= 1
    )

    # KEPADATAN LABEL SUMBU MINGGU. Grafik tangan kurva-S menjarangkan per
    # INDEKS (paling banyak 12 label); charts.js menjarangkan di ruang piksel
    # dengan irama 64 px yang ditera untuk "05 Sep 2026" (61,6 px) — dan
    # dipakai apa adanya oleh label selebar "M12" (±21 px), sehingga proyek 12
    # minggu kehilangan lima labelnya (M1, M3, M5, M7, M9, M11, M12) di sumbu
    # yang ruangnya jelas cukup (verifikasi P1-E). Proyek demo hanya 8 minggu,
    # jadi bentuk ini tidak pernah terlihat di layar mana pun di sini.
    week_axis = pg.evaluate("""async () => {
      const m = await import("/app/js/charts.js");
      const host = document.createElement("div");
      document.body.appendChild(host);
      const draw = (n) => {
        host.replaceChildren(m.lineChart({
          series: [{ label: "Aktual", points: Array.from({ length: n }, (_, i) => ({ y: (i + 1) * (100 / n) })) }],
          xLabels: Array.from({ length: n }, (_, i) => `M${i + 1}`),
          width: 720, height: 260, yMin: 0, yMax: 100, yStep: 25, yFormat: (v) => `${v}%`,
        }));
        const labels = [...host.querySelectorAll("text.chart-tick")]
          .filter((t) => t.getAttribute("text-anchor") !== "end");
        // Tidak boleh ada dua label yang kotaknya bersentuhan.
        const boxes = labels.map((t) => t.getBBox()).sort((a, b) => a.x - b.x);
        let overlap = false;
        for (let i = 1; i < boxes.length; i++) {
          if (boxes[i - 1].x + boxes[i - 1].width > boxes[i].x) overlap = true;
        }
        return { labels: labels.map((t) => t.textContent), overlap };
      };
      const out = { w8: draw(8), w12: draw(12), w52: draw(52) };
      host.remove();
      return out;
    }""")
    out["week_axis"] = week_axis

    checks = {
        # Ketiganya benar-benar digambar charts.js, bukan sisa SVG tangan.
        "all_are_chart_lib": all(c["lib"] for c in (scurve, evm, trend)),
        # Setiap tanda membawa tepat satu <title> (aturan charts.js, dan satu-
        # satunya cara pembaca layar mendapat angkanya).
        "every_mark_titled": all(c["marks_with_title"] == c["marks"] for c in (scurve, evm, trend)),
        # Kurva-S: tiga seri, area di bawah aktual, sumbu 0–100 langkah 25.
        "scurve_three_series": len(scurve["series"]) == 3,
        "scurve_has_area": scurve["areas"] == 1,
        "scurve_axis_0_100": scurve["ticks"][:5] == ["0%", "25%", "50%", "75%", "100%"],
        "scurve_week_labels": any(t.startswith("M") for t in scurve["ticks"]),
        # Kalimat <title> menyebut rencana DAN aktual pada minggu yang sama.
        "scurve_title_names_both": all(
            ("rencana" in t and "aktual" in t) for t in scurve["titles"]) and bool(scurve["titles"]),
        # EVM: tiga seri dengan TIGA warna berbeda (sebelum P1-E garis EV dan
        # biaya sama-sama biru, hanya dibedakan opacity .55).
        "evm_three_distinct_colours": len({s["stroke"] for s in evm["series"]}) == 3,
        # Titik as-of lebih besar daripada titik biasa.
        "evm_as_of_point_larger": len(evm["point_radii"]) >= 2,
        # …dan titik yang DIKECUALIKAN dots:false tidak boleh menyamainya:
        # pada proyek yang baru dibaseline, r 4 hanya milik penanda as-of.
        "exempt_dot_never_outranks_the_as_of_marker":
            dots_probe["fresh"]["radii"].count(4) == 1 and max(dots_probe["gap"]["radii"]) < 4,
        # Seri yang seluruh datanya terpencil dilambangkan TITIK di legenda,
        # bukan garis penuh untuk garis yang tidak ada di grafiknya.
        "dot_only_series_gets_a_dot_swatch":
            dots_probe["gap"]["lines"] == 1 and dots_probe["gap"]["swatches"] == ["line", "circle"],
        "evm_title_names_three_numbers": all(
            ("rencana" in t and "fisik" in t and "biaya" in t) for t in evm["titles"]) and bool(evm["titles"]),
        # Sumbu EVM boleh melewati 100 % — di sini datanya berhenti di 100, jadi
        # yang dibuktikan adalah bahwa ia TIDAK jatuh di bawahnya.
        "evm_axis_reaches_100": "100%" in evm["ticks"],
        # Seri yang DIUKUR lebih tebal daripada seri acuannya — pada ketiga
        # grafik. Kurva-S: Aktual di atas dua garis rencana; EVM: biaya aktual
        # di atas baseline dan EV; tren harga: satu-satunya garisnya.
        "measured_series_is_the_heaviest_line": (
            max(x["width"] for x in scurve["series"]) == 2.5
            and sorted(x["width"] for x in scurve["series"]) == [2, 2, 2.5]
            and sorted(x["width"] for x in evm["series"]) == [2, 2, 2.5]
            and [x["width"] for x in trend["series"]] == [2.5]
        ),
        # KERTAS: pola yang ditulis pemanggil menang, legenda DOM benar-benar
        # tercetak, dan pembeda titiknya bukan 0,5 px.
        "authored_dash_survives_print": print_state["authored_dash_survives_print"],
        "trend_legend_prints_and_differs_by_shape": print_state["trend_legend_prints_and_differs_by_shape"],
        "trend_points_differ_by_more_than_half_a_pixel": print_state["trend_points_differ_by_more_than_half_a_pixel"],
        # Sumbu minggu 12 titik menggambar KEDUA BELAS labelnya, dan tidak ada
        # sumbu minggu yang labelnya bertumpuk.
        "twelve_week_axis_keeps_all_labels": len(week_axis["w12"]["labels"]) == 12,
        "week_axis_never_overlaps": not any(week_axis[k]["overlap"] for k in ("w8", "w12", "w52")),
        # Tren harga: sumbu TIDAK mulai dari nol.
        "trend_axis_not_zero_based": trend["ticks"] and not trend["ticks"][0].strip().endswith(" 0"),
        "trend_five_gridlines": len([t for t in trend["ticks"] if t.startswith("Rp")]) == 5,
        # …dan garis kisi itu benar-benar MEMBENTANG plotnya: label pertama =
        # lantai sumbu, label terakhir = langit-langitnya.
        #
        # Menghitung lima saja tidak cukup, dan buktinya ada di data demo
        # sendiri: kedua harga item demo SAMA (Rp 62.000), dan pada kasus
        # berimpit itu lo/step kebetulan bulat untuk harga BERAPA pun — jadi
        # syarat lama hijau di sini sementara sumbu harga yang sungguhan
        # kehilangan garis kelima DAN kedua label tepinya (terukur atas 205
        # deret harga: 5 garis pada 21,5 % kasus, 161 sumbu tanpa label tepi;
        # sesudah perbaikan 100 % dan 0). Yang di bawah ini diukur pada deret
        # harga NAIK yang digambar di halaman ini juga.
        "trend_axis_edges_are_labelled": trend_axis["edges_labelled"],
        "trend_rising_prices_keep_five_gridlines": trend_axis["rising_gridlines"] == 5,
        # PO vs GRN dibedakan per TITIK, bukan per seri.
        "trend_one_series": len(trend["series"]) == 1,
        "trend_point_tokens_differ": len(trend["point_tokens"]) >= 2,
        # Warna setiap garis benar-benar nilai tokennya.
        "series_colours_match_tokens": all(
            s["expected"] and s["stroke"].replace(" ", "") == _rgb_css(s["expected"])
            for c in (scurve, evm, trend) for s in c["series"]),
        # Label tepi tidak keluar dari kotak — cacat yang DIPERBAIKI migrasi ini.
        "no_text_outside_viewbox": all(not c["text_outside_viewbox"] for c in (scurve, evm, trend)),
        # …dan warnanya masih datang dari token di tema GELAP, di mana setiap
        # token punya nilai berbeda: warna ter-hardcode lolos di terang dan
        # ketahuan di sini.
        "dark_colours_match_tokens": all(
            s["expected"] and s["stroke"].replace(" ", "") == _rgb_css(s["expected"])
            for v in dark.values() if v for s in v["series"]),
        "dark_differs_from_light": any(
            d[i][1] != light_series[key][i][1]
            for key, d in out["dark"].items()
            for i in range(len(d))
            if key in light_series and i < len(light_series[key])),
        "no_page_errors": not errors,
    }
    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


def _rgb_css(hexs):
    """'#1a56db' → 'rgb(26,86,219)' untuk dibandingkan dengan getComputedStyle."""
    h = hexs.strip().lstrip("#")
    if len(h) != 6:
        return hexs.strip()
    return "rgb({},{},{})".format(*(int(h[i:i + 2], 16) for i in (0, 2, 4)))


# ------------------------------------------------------------ S24 (P1-F)
#
# Laporan Bebas: katalog yang menyaring dirinya per izin, pivot yang benar-benar
# berjalan, kolom yang DITOLAK katalog yang tetap terlihat dengan alasannya, sel
# kosong yang tidak pernah menjadi 0, dan laporan tersimpan yang dibagikan per
# peran.
#
# Yang tidak bisa dibuktikan uji PHP dan karena itu ada di sini: bahwa angka di
# LAYAR sama dengan angka yang dikirim server (label enum dan nama relasi
# ditulis peramban), bahwa kolom yang ditolak benar-benar terbaca oleh orang
# yang mencarinya, dan bahwa plafon yang diumumkan server benar-benar tercetak
# di layar alih-alih dihafal SPA.

S24_CLEANUP = """async () => {
  const token = localStorage.getItem('nusantara_erp_token');
  const head = { 'X-Api-Token': token, Accept: 'application/json' };
  const list = await (await fetch('/api/core/reports/saved', { headers: head })).json();
  const mine = (list.data || []).filter((r) => r.name.includes('(S24)'));
  for (const one of mine) {
    await fetch('/api/core/reports/saved/' + one.id, { method: 'DELETE', headers: head });
  }
  return mine.length;
}"""


S24_RUN = """() => {
  // Kartu HASIL ditandai aplikasinya (.report-result). Memakai "kartu terakhir"
  // salah begitu daftar laporan tersimpan tumbuh di bawahnya — dan itu terjadi
  // pada jalan KEDUA, yaitu jalan yang membuktikan skenario ini bisa diulang.
  const last = document.querySelector('.card.report-result');
  if (!last) return null;
  return {
    head: (last.querySelector('.card-head') || {}).innerText || '',
    foot: (last.querySelector('.card-foot') || {}).innerText || '',
    rows: [...last.querySelectorAll('table.data tr')].map((tr) => [...tr.children].map((td) => td.innerText.trim())),
    // Judul sel membedakan "tidak ada baris" dari "ada baris, nilainya tidak ada".
    titles: [...last.querySelectorAll('table.data td.num')].map((td) => td.getAttribute('title')),
  };
}"""




# ------------------------------------------------------- S20em (P1-E, ponsel)
#
# S20e berjalan pada 1440x900, dan itulah satu-satunya lebar yang pernah
# diukurnya. Yang PALING berubah pada pemindahan P1-E justru hanya terlihat di
# lebar lain: legenda kurva-S dan EVM pindah KE DALAM svg, dan svg ber-viewBox
# tetap yang diregangkan ke kartu ponsel mengecilkan teksnya bersama gambarnya —
# terukur 5,0 px untuk legenda DAN label sumbu pada 390x844, sementara legenda
# DOM yang tersisa (tren harga) tetap 12 px di halaman yang sama. Skenario ini
# mengukur lantai itu, di kedua tema.

MOBILE_CHART_TEXT = """() => [...document.querySelectorAll("svg.chart-lib")].map((svg) => {
  const r = svg.getBoundingClientRect();
  const vb = svg.viewBox.baseVal;
  const scale = vb.width ? r.width / vb.width : 1;
  const px = (node) => (node ? +(parseFloat(getComputedStyle(node).fontSize) * scale).toFixed(1) : null);
  return {
    aria: (svg.getAttribute("aria-label") || "").slice(0, 24),
    viewbox_w: vb.width,
    css_w: Math.round(r.width),
    scale: +scale.toFixed(3),
    legend_px: px(svg.querySelector("text.chart-legend")),
    tick_px: px(svg.querySelector("text.chart-tick")),
    stroke_px: (() => { const l = svg.querySelector("path.series-line");
      return l ? +(parseFloat(getComputedStyle(l).strokeWidth) * scale).toFixed(2) : null; })(),
    // Halaman tidak boleh menggulung mendatar karena grafiknya.
    overflows: r.width > document.documentElement.clientWidth + 1,
  };
})"""


@scenario("S20e_migrated_charts_mobile")
def s20em(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
    try:
        login(pg, "admin@nusantara.test")
        pg.evaluate("""() => { location.hash = "#/d/projects/1"; }""")
        pg.wait_for_selector("svg[aria-label*='Kurva-S']", timeout=20000)
        pg.wait_for_timeout(2500)
        project = pg.evaluate(MOBILE_CHART_TEXT)
        pg.screenshot(path=f"{OUT}/s20em-proyek-ponsel-p1e.png", full_page=True)

        pg.evaluate("""() => { location.hash = "#/harga-satuan"; }""")
        pg.wait_for_selector("svg[aria-label*='Tren harga']", timeout=20000)
        pg.wait_for_timeout(1800)
        trend = pg.evaluate(MOBILE_CHART_TEXT)
        # Legenda DOM tren harga: pembanding yang ada DI HALAMAN yang sama.
        dom_legend_px = pg.evaluate(
            "() => { const i = document.querySelector('.legend'); "
            "return i ? parseFloat(getComputedStyle(i).fontSize) : null; }")
        pg.screenshot(path=f"{OUT}/s20em-tren-harga-ponsel-p1e.png", full_page=True)

        charts = project + trend
        texts = [c["legend_px"] for c in charts if c["legend_px"]] + [c["tick_px"] for c in charts if c["tick_px"]]

        out = {
            "project_charts": project,
            "trend_chart": trend,
            "dom_legend_px": dom_legend_px,
            "min_rendered_text_px": min(texts) if texts else None,
            "pageerrors": errors,
        }
        checks = {
            "charts_drawn": len(project) >= 2 and len(trend) >= 1,
            # Lantai 9 px: bukan angka mutlak melainkan angka yang bisa dibaca,
            # dan jarak yang jelas dari 5,0 px yang terukur sebelum perbaikan.
            "rendered_text_never_below_9px": bool(texts) and min(texts) >= 9,
            # Grafik tidak melebihi lebar layarnya.
            "no_horizontal_overflow": not any(c["overflows"] for c in charts),
            "no_page_errors": not errors,
        }
        out["checks"] = checks
        out["failed_checks"] = [k for k, v in checks.items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()


@scenario("S24_laporan_bebas")
def s24(pg):
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
    login(pg, "admin@nusantara.test")
    pg.evaluate("() => { location.hash = '#/laporan-bebas'; }")
    pg.wait_for_selector(".card select", timeout=20000)
    pg.wait_for_timeout(1200)

    out = {"pageerrors": errors}

    # --- katalog + plafon yang DIUMUMKAN (bukan dihafal SPA) ---------------
    out["catalogue"] = pg.evaluate("""() => {
      const first = document.querySelector('.card select');
      return {
        sources: [...first.options].map((o) => o.value),
        limits_text: (document.querySelector('.card-head .cell-sub') || {}).innerText || '',
      };
    }""")
    out["limits_not_hardcoded"] = pg.evaluate(
        "async () => { const r = await fetch('/api/core/reports/resources', { headers: { 'X-Api-Token': localStorage.getItem('nusantara_erp_token'), Accept: 'application/json' } });"
        " const j = await r.json(); return j.meta && j.meta.limits; }")

    # --- pivot sungguhan --------------------------------------------------
    pg.select_option(".card select >> nth=0", "finance/project-costs")
    pg.wait_for_timeout(500)
    pg.select_option(".card select >> nth=1", "pivot")
    pg.wait_for_timeout(500)
    click(pg, ".card-foot button:has-text('Jalankan')")
    pg.wait_for_timeout(2500)
    out["pivot"] = pg.evaluate(S24_RUN)
    pg.screenshot(path=f"{OUT}/s24-laporan-bebas-p1f.png", full_page=True)

    # --- kolom yang DITOLAK katalog, terlihat dengan alasannya -------------
    pg.select_option(".card select >> nth=0", "finance/ar-invoices")
    pg.wait_for_timeout(600)
    pg.select_option(".card select >> nth=1", "detail")
    pg.wait_for_timeout(500)
    click(pg, ".card-body button:has-text('Pilih kolom')")
    pg.wait_for_selector(".modal", timeout=10000)
    out["column_picker"] = pg.evaluate("""() => {
      const rows = [...document.querySelectorAll('.modal .field')];
      return rows.map((r) => ({
        label: (r.querySelector('.cell-main') || {}).innerText || '',
        disabled: !!(r.querySelector('input[type=checkbox]') || {}).disabled,
        reason: (r.querySelector('.help') || {}).innerText || null,
      })).filter((r) => r.label);
    }""")
    pg.screenshot(path=f"{OUT}/s24-kolom-ditolak-p1f.png")
    click(pg, ".modal-foot button:has-text('Selesai')")
    pg.wait_for_timeout(400)

    # --- sel kosong bukan 0, pada aset (nilai buku alat sewa NULL) ---------
    pg.select_option(".card select >> nth=0", "assets/assets")
    pg.wait_for_timeout(600)
    pg.select_option(".card select >> nth=1", "pivot")
    pg.wait_for_timeout(500)
    # baris = kepemilikan, kolom = status, ukuran = SUM nilai buku
    pg.select_option(".card select >> nth=2", "ownership")
    pg.wait_for_timeout(300)
    pg.select_option(".card select >> nth=3", "status")
    pg.wait_for_timeout(300)
    labels = pg.evaluate("() => [...document.querySelectorAll('.card select')].map((s) => (s.closest('.field').querySelector('label') || {}).innerText)")
    if "Kolom ukuran" in labels:
        pg.select_option(f".card select >> nth={labels.index('Kolom ukuran')}", "book_value")
        pg.wait_for_timeout(300)
    click(pg, ".card-foot button:has-text('Jalankan')")
    pg.wait_for_timeout(2500)
    out["assets"] = pg.evaluate(S24_RUN)

    # --- simpan, bagikan, salin, hapus ------------------------------------
    click(pg, ".card-foot button:has-text('Simpan laporan')")
    pg.wait_for_selector(".modal input[type=text]", timeout=10000)
    pg.fill(".modal input[type=text]", "Nilai buku per kepemilikan (S24)")
    shares = pg.locator(".modal input[type=checkbox]")
    if shares.count():
        shares.first.check()
    click(pg, ".modal-foot button:has-text('Simpan')")
    pg.wait_for_timeout(1800)

    out["saved"] = pg.evaluate("""() => {
      const table = [...document.querySelectorAll('table.data')].pop();
      const card = [...document.querySelectorAll('.card')].find((c) => (c.querySelector('.card-head') || {}).innerText.includes('Laporan tersimpan'));
      if (!card) return null;
      return {
        head: card.querySelector('.card-head').innerText,
        rows: [...card.querySelectorAll('tbody tr')].map((tr) => [...tr.children].map((td) => td.innerText.trim())),
        actions: [...card.querySelectorAll('tbody tr td:last-child button')].map((b) => b.innerText.trim()),
      };
    }""")

    # --- saringan yang layar tidak punya kendalinya TETAP TERBACA ----------
    #
    # Sebuah laporan tersimpan boleh membawa saringan ber-FK (pemilihnya
    # sengaja ditunda ke v2) atau nilai enum yang sudah dicabut. 'Buka'
    # mengirimkannya kembali apa adanya, jadi angka yang tergambar adalah
    # HIMPUNAN BAGIAN — dan sampai verifikasi kedua P1-F setiap kendali di
    # layar terbaca '— tidak ada —' sementara tak satu kata pun menyebut
    # saringannya. Yang diukur di sini adalah bahwa saringan itu punya suara:
    # keping di panel dan kalimat 'Disaring:' di kartu hasilnya.
    out["hidden_filter"] = pg.evaluate("""async () => {
      const token = localStorage.getItem('nusantara_erp_token');
      const head = { 'X-Api-Token': token, Accept: 'application/json', 'Content-Type': 'application/json' };
      const body = {
        name: 'Biaya proyek satu saja (S24)',
        definition: {
          resource: 'finance/project-costs', mode: 'group',
          row: { column: 'cost_category' }, measure: { agg: 'sum', column: 'amount' },
          filters: { eq: { project_id: 1 } },
        },
      };
      const r = await fetch('/api/core/reports/saved', { method: 'POST', headers: head, body: JSON.stringify(body) });
      return (await r.json()).data;
    }""")

    # Daftar tersimpan digambar ulang, lalu barisnya dibuka.
    pg.evaluate("() => { location.hash = '#/home'; }")
    pg.wait_for_timeout(600)
    pg.evaluate("() => { location.hash = '#/laporan-bebas'; }")
    pg.wait_for_selector(".card select", timeout=20000)
    pg.wait_for_timeout(1500)
    click(pg, "tr:has-text('Biaya proyek satu saja (S24)') button:has-text('Buka')")
    pg.wait_for_timeout(2500)

    out["hidden_filter_screen"] = pg.evaluate("""() => {
      const chips = document.querySelector('.report-filter-chips');
      const result = document.querySelector('.card.report-result');
      return {
        chips: chips ? chips.innerText.trim() : null,
        head: result ? result.querySelector('.card-head').innerText : null,
        // Kendali enum di panel TIDAK boleh mengaku '— tidak ada —' untuk
        // saringan yang sedang berlaku.
        controls: [...document.querySelectorAll('.card select')].map((s) => s.selectedOptions[0].text),
      };
    }""")
    pg.screenshot(path=f"{OUT}/s24-saringan-tersembunyi-p1f.png", full_page=True)

    # --- katalog peran lain: menyaring dirinya sendiri ---------------------
    ctx = pg.context.browser.new_context(viewport={"width": 1440, "height": 900})
    other = ctx.new_page()
    try:
        login(other, "warehouse@nusantara.test")
        other.evaluate("() => { location.hash = '#/laporan-bebas'; }")
        other.wait_for_timeout(3000)
        out["warehouse"] = other.evaluate("""() => {
          const first = document.querySelector('.card select');
          return {
            sources: first ? [...first.options].map((o) => o.value) : [],
            alert: (document.querySelector('.alert') || {}).innerText || null,
            // Laporan orang lain yang dibagikan ke peran ini tetap tak terlihat
            // bila sumbernya bukan miliknya.
            saved_rows: [...document.querySelectorAll('.card')].filter((c) => (c.querySelector('.card-head') || {}).innerText.includes('Laporan tersimpan')).length,
          };
        }""")
    finally:
        ctx.close()

    # ---------------------------- syarat ----------------------------------
    pivot = out["pivot"] or {}
    assets = out["assets"] or {}
    picker = out["column_picker"] or []
    refused = [r for r in picker if r["disabled"]]

    checks = {
        "catalogue_has_eight_sources": len(out["catalogue"]["sources"]) == 8,
        # Plafon DIUMUMKAN server dan tercetak di layar — tidak dihafal SPA.
        "limits_announced_by_server": out["limits_not_hardcoded"] == {"rows": 5000, "groups": 200},
        "limits_printed_on_screen": "200" in out["catalogue"]["limits_text"] and "5.000" in out["catalogue"]["limits_text"],
        "pivot_ran": bool(pivot.get("rows")) and len(pivot["rows"]) > 1,
        # SATU kueri, diumumkan di kaki kartu.
        "one_query_announced": "1 kueri" in pivot.get("foot", ""),
        # Dimensi ber-FK dilabeli seperti di layar daftarnya, bukan id telanjang.
        "fk_dimension_is_labelled": any("PRJ-" in cell for row in pivot.get("rows", []) for cell in row),
        # Kolom yang ditolak katalog TERLIHAT, nonaktif, dengan alasannya.
        "refused_columns_shown": len(refused) > 0,
        "refused_columns_explain_themselves": all(bool(r["reason"]) for r in refused),
        "outstanding_is_refused": any("Sisa" in r["label"] for r in refused),
        # Sel kosong '—', tidak pernah 0.
        "empty_cell_is_dash_never_zero": bool(assets.get("rows")) and any(
            "—" in cell for row in assets.get("rows", [])[1:] for cell in row),
        "empty_cell_says_why": any(t and ("tidak ada" in t or "nilainya tidak ada" in t) for t in (assets.get("titles") or [])),
        "saved_report_listed": bool(out["saved"]) and len(out["saved"]["rows"]) >= 1,
        "saved_report_offers_xlsx": "XLSX" in (out["saved"] or {}).get("actions", []),
        # Saringan yang layar tidak punya kendalinya tetap PUNYA SUARA: keping
        # berlabel di panel, dan kalimat 'Disaring:' di kartu hasilnya.
        "hidden_filter_has_a_chip": "Proyek" in ((out["hidden_filter_screen"] or {}).get("chips") or ""),
        "hidden_filter_chip_is_labelled_like_the_list_screen": "PRJ-" in ((out["hidden_filter_screen"] or {}).get("chips") or ""),
        "result_card_names_the_filter_in_force": "Disaring" in ((out["hidden_filter_screen"] or {}).get("head") or ""),
        # Katalog menyaring dirinya: gudang tidak memegang satu pun sumber.
        "catalogue_filters_by_permission": len(out["warehouse"]["sources"]) < 8,
        "no_page_errors": not errors,
    }

    # Skenario ini membersihkan jejaknya sendiri: laporan tersimpan yang
    # ditinggalkan membuat jalan berikutnya berangkat dari keadaan yang
    # berbeda, dan skenario yang hanya hijau pada basis data bersih tidak
    # membuktikan apa yang diklaimnya.
    out["cleanup"] = pg.evaluate(S24_CLEANUP)

    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


# ------------------------------------------------------------ S25 (P1-G)
#
# Papan kanban. Yang diukur di sini adalah satu hal yang tidak bisa dibuktikan
# uji PHP mana pun: bahwa kartu yang DITOLAK benar-benar KEMBALI. SortableJS
# tidak punya API batal — onEnd menyala setelah DOM dipindahkan — jadi
# "kartunya kembali" adalah kode tangan, dan sebuah papan yang salah di situ
# menampilkan dokumen di kolom yang bukan statusnya: papan yang berbohong
# tentang keadaan dokumen, yang justru satu-satunya hal yang dijualnya.
#
# Dua akun, dua hasil, satu gerakan yang sama: procurement@ memegang prc.update
# tetapi BUKAN prc.approve, direktur@ memegang keduanya.

def restore_pr(code):
    """Kembalikan satu PR ke `submitted` dan buang jejak persetujuannya.

    Tidak ada endpoint yang membatalkan persetujuan — dan memang tidak boleh
    ada. Skenario ini karena itu memulihkan keadaannya langsung di sqlite,
    pola yang sama dengan decide_onboarding(), supaya jalan KEDUA berangkat
    dari keadaan yang sama dengan jalan pertama.
    """
    con = sqlite3.connect(DB)
    con.execute("UPDATE prc_purchase_requisitions SET status='submitted' WHERE code=?", (code,))
    con.execute(
        "DELETE FROM core_approvals WHERE approvable_type LIKE '%PurchaseRequisition%' "
        "AND approvable_id IN (SELECT id FROM prc_purchase_requisitions WHERE code=?)", (code,))
    con.commit()
    row = con.execute("SELECT status FROM prc_purchase_requisitions WHERE code=?", (code,)).fetchone()
    con.close()
    return {"code": code, "status": row[0] if row else None}


S25_LANES = """() => [...document.querySelectorAll('.board-lane')].map((lane) => ({
  head: lane.querySelector('.board-lane-head').innerText.split(String.fromCharCode(10)).join(' '),
  status: lane.querySelector('.board-cards').dataset.status,
  cards: [...lane.querySelectorAll('.board-card')].map((c) => c.querySelector('.cell-main').innerText.trim()),
}))"""


@scenario("S25_papan_pr")
def s25(pg):
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
    out = {"pageerrors": errors}

    # PRASYARAT DIPASANG SENDIRI, bukan diwarisi: skenario ini menyetujui
    # sebuah PR, jadi ia mengembalikannya di akhir DAN memasangnya di awal.
    # Sebuah skenario yang bergantung pada keadaan yang ditinggalkan jalan
    # sebelumnya hijau sekali lalu merah selamanya.
    out["precondition"] = restore_pr("PR/2026/III/0002")

    # ---- 1. drop yang DITOLAK: pengadaan tidak memegang prc.approve --------
    login(pg, "procurement@nusantara.test")
    pg.evaluate("() => { location.hash = '#/b/procurement/purchase-requisitions'; }")
    pg.wait_for_selector(".board-card", timeout=20000)
    pg.wait_for_timeout(800)

    out["refused_before"] = pg.evaluate(S25_LANES)
    submitted = pg.locator(".board-lane:has-text('Diajukan') .board-card").first
    approved_lane = pg.locator(".board-lane:has-text('Disetujui') .board-cards").first
    CLICKS[0] += 1
    submitted.drag_to(approved_lane)
    pg.wait_for_timeout(2000)

    out["refused_after"] = pg.evaluate(S25_LANES)
    out["refused_toasts"] = toasts(pg)
    pg.screenshot(path=f"{OUT}/s25-papan-drop-ditolak-p1g.png", full_page=True)

    # ---- 2. drop yang DITERIMA: direktur memegang prc.approve --------------
    ctx = pg.context.browser.new_context(viewport={"width": 1440, "height": 1000})
    boss = ctx.new_page()
    try:
        login(boss, "direktur@nusantara.test")
        boss.evaluate("() => { location.hash = '#/b/procurement/purchase-requisitions'; }")
        boss.wait_for_selector(".board-card", timeout=20000)
        boss.wait_for_timeout(800)

        out["allowed_before"] = boss.evaluate(S25_LANES)
        boss.locator(".board-lane:has-text('Diajukan') .board-card").first.drag_to(
            boss.locator(".board-lane:has-text('Disetujui') .board-cards").first)
        boss.wait_for_timeout(1200)

        # Aksi Setujui membawa `inlineNote`: panel catatan yang SAMA dengan
        # bilah aksi ditawarkan sebelum aksinya jalan.
        out["note_dialog"] = boss.evaluate("""() => {
          const m = document.querySelector('.modal');
          return m ? {
            title: (m.querySelector('.modal-head') || {}).innerText,
            has_note_panel: !!m.querySelector('details.action-note'),
            buttons: [...m.querySelectorAll('.modal-foot button')].map((b) => b.innerText.trim()),
          } : null;
        }""")
        boss.screenshot(path=f"{OUT}/s25-papan-catatan-p1g.png")

        if out["note_dialog"]:
            boss.click(".modal-foot button:has-text('Setujui')")
            boss.wait_for_timeout(3000)

        out["allowed_after"] = boss.evaluate(S25_LANES)
        out["allowed_toasts"] = toasts(boss)

    finally:
        ctx.close()

    # Kembalikan keadaannya LEWAT SQLITE, karena tidak ada endpoint yang
    # membatalkan persetujuan — dan tanpa ini skenario hanya hijau pada jalan
    # pertama. Pola yang sama dengan decide_onboarding().
    out["restored"] = restore_pr("PR/2026/III/0002")

    # ---- 3. papan kedua: kontrak `board:` di luar documentStatus -----------
    # Akun BARU: pengadaan tidak memegang qc.view, dan papan NCR baginya adalah
    # panel akses-ditolak — bukan bukti bahwa papan kedua tidak tergambar.
    ctx2 = pg.context.browser.new_context(viewport={"width": 1440, "height": 1000})
    qc = ctx2.new_page()
    try:
        login(qc, "admin@nusantara.test")
        qc.evaluate("() => { location.hash = '#/b/quality/ncr'; }")
        qc.wait_for_timeout(3000)
        out["ncr"] = qc.evaluate(S25_LANES)
        qc.screenshot(path=f"{OUT}/s25-papan-ncr-p1g.png", full_page=True)
    finally:
        ctx2.close()

    # ---------------------------- syarat ----------------------------------
    refused_before = {lane["status"]: len(lane["cards"]) for lane in out["refused_before"]}
    refused_after = {lane["status"]: len(lane["cards"]) for lane in out["refused_after"]}
    allowed_before = {lane["status"]: len(lane["cards"]) for lane in out["allowed_before"]}
    allowed_after = {lane["status"]: len(lane["cards"]) for lane in out["allowed_after"]}
    refusal = " ".join(out["refused_toasts"])

    checks = {
        # Kolomnya adalah nilai enum, dilabeli seperti layar daftarnya.
        "lanes_are_labelled_statuses": [lane["status"] for lane in out["refused_before"]]
            == ["draft", "submitted", "approved", "rejected"],
        "lane_heads_use_enum_labels": "Diajukan" in " ".join(l["head"] for l in out["refused_before"]),
        # DROP DITOLAK: kartu kembali, dan tidak satu kolom pun berubah.
        "refused_drop_puts_the_card_back": refused_before == refused_after,
        # Kalimatnya menyebut dokumen, kolom tujuan DAN aksinya.
        "refusal_names_the_document": "PR/" in refusal,
        "refusal_names_the_target_lane": "Disetujui" in refusal,
        "refusal_names_the_missing_action": "Setujui tidak tersedia untuk Anda" in refusal,
        # DROP DITERIMA: panel catatan yang sama dengan bilah aksi, lalu pindah.
        "note_panel_is_the_action_bar_panel": bool(out["note_dialog"]) and out["note_dialog"]["has_note_panel"],
        "allowed_drop_moves_the_card": allowed_after.get("approved", 0) == allowed_before.get("approved", 0) + 1
            and allowed_after.get("submitted", 0) == allowed_before.get("submitted", 0) - 1,
        # Toast datang dari runAction — bukti jalur tombolnya yang dipakai.
        "toast_is_the_shared_one": any("disetujui" in t for t in out["allowed_toasts"]),
        # …dan `offerNext` ikut, yang hanya mungkin lewat runAction.
        "offer_next_came_along": any("Berikutnya menunggu Anda" in t for t in out["allowed_toasts"]),
        # Papan kedua berdiri di enum yang berbeda.
        "second_board_renders": [lane["status"] for lane in out["ncr"]]
            == ["open", "under_correction", "verified", "closed"],
        "no_page_errors": not errors,
    }

    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


# ------------------------------------------------------------ S26 (P1-H)
#
# Tab "Jadwal" — gantt baca-saja atas WBS proyek. Yang diukur di sini adalah
# yang TIDAK bisa dibuktikan uji PHP: uji PHP memaku muatan dua endpoint yang
# dibaca layar ini (JadwalGanttTest), gambarnya sendiri hanya ada di peramban.
#
# Setiap syarat di bawah adalah ANGKA yang dihitung ulang dari muatan API-nya,
# bukan boolean: jumlah baris yang tergambar vs jumlah tugas yang dipulangkan
# server, posisi x garis "Hari ini" vs posisi yang seharusnya untuk jendela
# rentangnya, jumlah bayangan akhir pekan vs jumlah Sabtu di jendela itu,
# kerapatan tick mingguan vs bulanan, dan ukuran teks SESUDAH skala viewBox —
# yaitu px yang benar-benar dilihat mata, bukan 11 px yang tertulis di CSS.
#
# Geometri gantt (charts.js): labelWidth 180 + timelineWidth 720 = 900 lebar
# alami, rowHeight 28, headerH 36. Baris ke-i: mid = 50 + 28i, bar aktual di
# y = mid − 9, bar baseline di y = mid + 1 — DI BAWAH bar aktualnya, dan itulah
# yang membuat selisih rencana vs beku terlihat. Angka-angka itu dipakai di
# sini untuk membaca kembali baris mana milik siapa dari koordinat rect-nya.

DAY_MS = 86400000

S26_MEASURE = """() => {
  const svg = document.querySelector('.gantt-sheet svg.chart-gantt');
  if (!svg) return null;
  const vb = (svg.getAttribute('viewBox') || '0 0 0 0').split(' ').map(Number);
  const box = svg.getBoundingClientRect();
  const scale = box.width / vb[2];
  const num = (n, a) => +n.getAttribute(a);
  const titleOf = (n) => { const t = n.querySelector('title'); return t ? t.textContent : null; };
  const fontPx = (sel) => { const n = svg.querySelector(sel); return n ? +(parseFloat(getComputedStyle(n).fontSize) * scale).toFixed(2) : null; };
  const marks = [...svg.querySelectorAll('.mark')];
  return {
    viewbox: vb,
    box: { w: +box.width.toFixed(1), h: +box.height.toFixed(1) },
    scale: +scale.toFixed(4),
    svg_min_width: getComputedStyle(svg).minWidth,
    labels: [...svg.querySelectorAll('text.gantt-label')].map((t) => ({
      shown: [...t.querySelectorAll('tspan')].map((s) => s.textContent).join(' '),
      full: t.dataset.full || null, truncated: t.dataset.truncated === 'true',
      lines: +(t.dataset.lines || 1), title: (t.querySelector('title') || {}).textContent || null,
    })),
    bars: [...svg.querySelectorAll('rect.gantt-bar')].map((r) => ({
      x: num(r, 'x'), y: num(r, 'y'), w: num(r, 'width'),
      open: r.dataset.open || null, level: r.dataset.level, title: titleOf(r),
    })),
    progress: [...svg.querySelectorAll('rect.gantt-progress')].map((r) => ({ x: num(r, 'x'), y: num(r, 'y'), w: num(r, 'width') })),
    baselines: [...svg.querySelectorAll('rect.gantt-baseline')].map((r) => ({
      x: num(r, 'x'), y: num(r, 'y'), w: num(r, 'width'), title: titleOf(r),
    })),
    open_edges: svg.querySelectorAll('line.gantt-open-edge').length,
    row_notes: {
      nodate: [...svg.querySelectorAll('text.gantt-nodate')].map((t) => t.textContent),
      invalid: [...svg.querySelectorAll('text.gantt-invalid')].map((t) => t.textContent),
      outside: [...svg.querySelectorAll('text.gantt-outside')].map((t) => t.textContent),
    },
    weekends: [...svg.querySelectorAll('rect.gantt-weekend')].map((r) => ({ x: num(r, 'x'), w: num(r, 'width') })),
    ticks: [...svg.querySelectorAll('line.gantt-tick')].map((l) => num(l, 'x1')),
    tick_labels: [...svg.querySelectorAll('text.gantt-tick-label')].map((t) => t.textContent),
    groups: [...svg.querySelectorAll('text.gantt-group')].map((t) => t.textContent),
    today_x: (() => { const l = svg.querySelector('line.gantt-today'); return l ? num(l, 'x1') : null; })(),
    today_label: (() => { const t = svg.querySelector('text.gantt-today-label'); return t ? t.textContent : null; })(),
    marks: marks.length,
    marks_with_title: marks.filter((m) => m.querySelector('title')).length,
    other_titles: [...svg.querySelectorAll('title')].length - marks.filter((m) => m.querySelector('title')).length,
    legend: [...svg.querySelectorAll('text.chart-legend')].map((t) => t.textContent),
    note: (() => { const n = svg.querySelector('text.chart-note'); return n ? n.textContent : null; })(),
    font_css_px: { label: fontPx('text.gantt-label'), tick: fontPx('text.chart-tick') },
    scroll_x: (() => { const s = document.querySelector('.gantt-sheet .chart-scroll'); return s ? s.scrollWidth > s.clientWidth + 1 : null; })(),
    /* Kotak di LAYAR (bukan atribut svg): sebuah garis "Hari ini" yang berdiri
       di x yang benar tetapi 60 px di luar jendela penggulir tidak pernah
       dilihat pembacanya. */
    boxes: (() => {
      const s = document.querySelector('.gantt-sheet .chart-scroll');
      const box = (n) => { if (!n) return null; const r = n.getBoundingClientRect();
        return { left: +r.left.toFixed(1), right: +r.right.toFixed(1), width: +r.width.toFixed(1) }; };
      return {
        scroller: s ? { ...box(s), scrollLeft: Math.round(s.scrollLeft), scrollWidth: s.scrollWidth, clientWidth: s.clientWidth } : null,
        today: box(document.querySelector('.gantt-sheet line.gantt-today')),
        today_label: box(document.querySelector('.gantt-sheet text.gantt-today-label')),
        svg_note: box(document.querySelector('.gantt-sheet text.chart-note')),
        dom_note: box(document.querySelector('.gantt-sheet .gantt-note-dom')),
      };
    })(),
    dom_note: (document.querySelector('.gantt-sheet .gantt-note-dom') || {}).innerText || null,
    head: (() => { const h = document.querySelector('.gantt-sheet .card-head h2'); return h ? h.innerText : null; })(),
    foot: [...document.querySelectorAll('.gantt-sheet .card-body p')].map((p) => p.innerText.trim()),
    zoom_buttons: [...document.querySelectorAll('.gantt-sheet .filters .btn')].map((b) => ({
      label: b.innerText.trim(), primary: b.classList.contains('primary'),
      pressed: b.getAttribute('aria-pressed'),
    })),
    filters_role: (() => { const f = document.querySelector('.gantt-sheet .filters');
      return f ? { role: f.getAttribute('role'), label: f.getAttribute('aria-label') } : null; })(),
  };
}"""

# Cetak: yang bisa dibaca dari halaman (blok @media print) DAN dari PDF yang
# benar-benar dihasilkan Chromium — ukuran halamannya diambil dari /MediaBox,
# bukan dari keyakinan bahwa `@page gantt { size: A4 landscape }` bekerja.
S26_PRINT = """() => {
  const sheet = document.querySelector('.gantt-sheet');
  const svg = sheet.querySelector('svg.chart-gantt');
  const scroll = sheet.querySelector('.chart-scroll');
  const flat = [];
  for (const s of document.styleSheets) {
    let rules = []; try { rules = [...s.cssRules]; } catch (e) { continue; }
    for (const r of rules) { flat.push(r); if (r.cssRules) for (const n of r.cssRules) flat.push(n); }
  }
  return {
    page_rules: flat.filter((r) => /^@page/.test(r.cssText || '')).map((r) => r.cssText),
    sheet_page: getComputedStyle(sheet).page || null,
    scroll_overflow_x: getComputedStyle(scroll).overflowX,
    svg_min_width: getComputedStyle(svg).minWidth,
    filters_visible: !!(sheet.querySelector('.filters') || {}).checkVisibility?.(),
    head_visible: !!(sheet.querySelector('.card-head h2') || {}).checkVisibility?.(),
    note_in_svg: !!svg.querySelector('text.chart-note'),
    svg_w: Math.round(svg.getBoundingClientRect().width),
    container_w: Math.round(scroll.getBoundingClientRect().width),
  };
}"""


def plant_open_ended_task(project_id=1, code="B.5"):
    """Satu tugas WBS tanpa tanggal selesai — keadaan yang sah (POST wbs-tasks
    menerima null) dan tidak ada di data demo, sehingga bar terbuka gantt tidak
    pernah tergambar sekali pun tanpa fixture ini."""
    con = sqlite3.connect(DB)
    try:
        parent = con.execute("SELECT id FROM prj_wbs_tasks WHERE project_id = ? AND wbs_code = 'B'", (project_id,)).fetchone()
        if parent is None:
            return {"state": "induk B tidak ada"}
        con.execute("DELETE FROM prj_wbs_tasks WHERE project_id = ? AND wbs_code = ?", (project_id, code))
        cur = con.execute(
            "INSERT INTO prj_wbs_tasks (project_id, parent_id, wbs_code, name, weight_pct, planned_start,"
            " planned_end, progress_pct, sort_order, created_at, updated_at)"
            " VALUES (?, ?, ?, ?, 0, '2026-09-01 00:00:00', NULL, 0, 9, datetime('now'), datetime('now'))",
            (project_id, parent[0], code, "Pekerjaan tambah — selesai belum ditetapkan"))
        con.commit()
        return {"state": "ditanam", "id": cur.lastrowid, "code": code, "planned_end": None}
    except Exception as e:
        return {"state": f"GAGAL: {str(e)[:140]}"}
    finally:
        con.close()


def drop_planted_task(project_id=1, code="B.5"):
    con = sqlite3.connect(DB)
    try:
        n = con.execute("DELETE FROM prj_wbs_tasks WHERE project_id = ? AND wbs_code = ?", (project_id, code)).rowcount
        con.commit()
        return {"state": "dibersihkan", "rows": n}
    finally:
        con.close()


# Tanggal beku B.2 pada berkas demo, dan tanggal yang skenario ini geser
# menjadi. Keduanya MUTLAK, bukan "before + hari": pergeseran relatif menggeser
# LAGI dari nilai yang sudah tergeser bila jalan sebelumnya mati sebelum sempat
# mengembalikan, lalu menyimpan nilai itu sebagai titik pulang — dan
# "pengembalian"-nya memasang tanggal yang salah pada baseline yang DISETUJUI
# dan menurut rancangannya tidak bisa diubah, secara permanen, sementara setiap
# S26 berikutnya tetap hijau karena semua harapan dihitung ulang dari API.
# (Diperagakan pada salinan buangan: 2026-10-31 → 2026-10-01 → mati → 2026-09-01
#  → "pengembalian" menulis 2026-10-01. Verifikasi P1-H, 7 Sep 2026.)
BASELINE_FIXTURE = {"code": "B.2", "original": "2026-10-31", "shifted": "2026-10-01"}


def shift_baseline_task(code=None, original=None, shifted=None):
    """Menggeser SATU tanggal beku, karena tanpa itu skenario ini tidak menguji
    apa pun yang menarik: pada data demo baseline BSL/2026/VIII/0001 dibekukan
    dari WBS yang sama persis, jadi 11 dari 11 bar pembandingnya berimpit
    sempurna dengan bar rencana hidupnya dan sebuah gantt yang MELUPAKAN bar
    baseline akan terlihat sama benarnya.

    Prasyaratnya DIPASANG di sini dan diperiksa, pola S25 ("skenario yang
    bergantung pada keadaan yang ditinggalkan jalan sebelumnya hijau sekali lalu
    merah selamanya"): tanggal yang sudah tergeser dikenali sebagai sisa jalan
    yang mati dan dipulihkan lebih dulu; tanggal yang BUKAN keduanya membuat
    skenarionya JATUH — menggeser dari titik yang tidak dikenal berarti
    mengarang titik pulang."""
    code = code or BASELINE_FIXTURE["code"]
    original = original or BASELINE_FIXTURE["original"]
    shifted = shifted or BASELINE_FIXTURE["shifted"]
    con = sqlite3.connect(DB)
    try:
        row = con.execute("SELECT id, planned_end FROM prj_baseline_tasks WHERE wbs_code = ? ORDER BY id LIMIT 1", (code,)).fetchone()
        if row is None:
            return {"state": f"baris beku {code} tidak ada"}
        before = str(row[1])[:10]
        if before not in (original, shifted):
            raise AssertionError(
                f"tanggal beku {code} = {before}, bukan {original} (asli) maupun {shifted} "
                f"(sisa jalan yang mati) — fixture ini menolak menggeser dari titik yang tidak dikenal")
        con.execute("UPDATE prj_baseline_tasks SET planned_end = ? WHERE id = ?", (shifted + " 00:00:00", row[0]))
        con.commit()
        days = (date.fromisoformat(shifted) - date.fromisoformat(original)).days
        return {"state": "digeser", "code": code, "from": original, "to": shifted, "days": days,
                "row_id": row[0], "restore": original,
                "healed": before == shifted}
    finally:
        con.close()


def restore_baseline_task(planted):
    """Mengembalikan tanggal beku ke nilai MUTLAKNYA, lalu membacanya lagi:
    sebuah pengembalian yang tidak diperiksa adalah keyakinan, bukan bukti."""
    if planted.get("state") != "digeser":
        return {"state": "tidak ada yang dikembalikan", "sebab": planted.get("state")}
    con = sqlite3.connect(DB)
    try:
        con.execute("UPDATE prj_baseline_tasks SET planned_end = ? WHERE id = ?",
                    (planted["restore"] + " 00:00:00", planted["row_id"]))
        con.commit()
        after = str(con.execute("SELECT planned_end FROM prj_baseline_tasks WHERE id = ?",
                                (planted["row_id"],)).fetchone()[0])[:10]
        return {"state": "dikembalikan" if after == planted["restore"] else f"GAGAL: terbaca {after}",
                "code": planted["code"], "to": planted["restore"], "read_back": after}
    finally:
        con.close()


def _days(value):
    """'YYYY-MM-DD' → hari UTC dalam ms, cara yang sama dengan parseDay charts.js."""
    y, m, d = (int(part) for part in value[:10].split("-"))
    return int(date(y, m, d).toordinal() - date(1970, 1, 1).toordinal()) * DAY_MS


def _dow(ms):
    """Hari dalam minggu ala JS getUTCDay(): Minggu 0 … Sabtu 6."""
    return (date.fromordinal(ms // DAY_MS + date(1970, 1, 1).toordinal()).weekday() + 1) % 7


def gantt_expectations(tasks, frozen, today_ms, label_w=180):
    """Seluruh geometri gantt dihitung ULANG dari muatan API — inilah pembanding
    yang membuat angka terukur berarti sesuatu."""
    flat = []

    def walk(nodes, level):
        for node in nodes:
            flat.append({**node, "level": level})
            walk(node.get("children") or [], level + 1)

    walk(tasks, 0)

    by_code = {row["wbs_code"]: row for row in (frozen or [])}
    dates = []
    for row in flat:
        for key in ("planned_start", "planned_end"):
            if row.get(key):
                dates.append(_days(row[key]))
        f = by_code.get(row["wbs_code"])
        if f:
            for key in ("planned_start", "planned_end"):
                if f.get(key):
                    dates.append(_days(f[key]))

    if not dates:
        # Tanpa penjaga ini kegagalannya berbunyi "min() iterable argument is empty",
        # yang tidak menyebut sebabnya. Sebab yang sesungguhnya selalu sama: muatan
        # API kosong — paling sering karena batas 120 permintaan/menit/pengguna
        # menjawab 429 ketika beberapa skenario dijalankan berurutan (terukur
        # 7 Sep 2026: S26m jatuh sesudah lima skenario, hijau bila dijalankan
        # sendiri, basis data bukti utuh 11 tugas).
        raise AssertionError(
            f"tidak ada satu pun tanggal pada {len(tasks)} tugas hidup dan {len(frozen)} baris beku — "
            "muatan API kosong (429 karena batas laju? proyek tanpa jadwal?), bukan gantt yang salah")

    lo, hi = min(dates), max(dates)
    days = round((hi - lo) / DAY_MS) + 1
    # Lebar kolom label mengikuti ATURAN yang ditulis jadwal.js (300 satuan di
    # layar >= 900 px, 180 di bawahnya); total viewBox tetap 900 satuan.
    timeline_w = 900 - label_w
    day_w = timeline_w / days
    x = lambda ms: label_w + ((ms - lo) / DAY_MS) * day_w

    weekends = 0
    cursor = lo
    while cursor <= hi:
        dow = _dow(cursor)
        if dow == 6:
            weekends += 1
            if cursor + DAY_MS <= hi:
                cursor += DAY_MS
        elif dow == 0 and cursor == lo:
            weekends += 1
        cursor += DAY_MS

    week_ticks = 0
    cursor = lo + ((8 - _dow(lo)) % 7) * DAY_MS
    while cursor <= hi:
        week_ticks += 1
        cursor += 7 * DAY_MS

    month_ticks = 0
    d = date.fromordinal(lo // DAY_MS + date(1970, 1, 1).toordinal())
    cursor_y, cursor_m = (d.year, d.month) if d.day == 1 else (d.year + d.month // 12, d.month % 12 + 1)
    while _days(f"{cursor_y:04d}-{cursor_m:02d}-01") <= hi:
        month_ticks += 1
        cursor_y, cursor_m = (cursor_y + cursor_m // 12, cursor_m % 12 + 1)

    matched = [row["wbs_code"] for row in flat if row["wbs_code"] in by_code]

    # Geometri SETIAP baris, dihitung ulang dari tanggal yang dipulangkan API:
    # x = 180 + (hari sejak `from`) x dayW, lebar = sampai HARI SESUDAH tanggal
    # selesai (`to` inklusif). Inilah yang membuat bar baseline berarti sesuatu:
    # kalau ia digambar dari tanggal hidup alih-alih tanggal beku, x atau
    # lebarnya meleset dan angkanya ketahuan.
    clamp = lambda v: max(float(label_w), min(900.0, v))
    geom = []
    for row in flat:
        start, end = row.get("planned_start"), row.get("planned_end")
        entry = {"code": row["wbs_code"], "bar": None, "baseline": None, "progress": None, "open": None}
        if start or end:
            s_ms = _days(start) if start else lo
            e_ms = _days(end) if end else hi
            entry["open"] = "end" if (start and not end) else ("start" if (end and not start) else None)
            bx = clamp(x(s_ms))
            raw_w = clamp(x(e_ms + DAY_MS)) - bx
            entry["bar"] = {"x": round(bx, 2), "w": round(max(1.0, raw_w), 2)}
            # Isian progres: SATUANNYA yang diuji di sini. `progress_pct` datang
            # 0..100 (dan sebagai string) dan charts.js menerima 0..1 — tanpa
            # pembagian 100 di jadwal.js SETIAP batang terisi penuh, dan sampai
            # verifikasi P1-H tidak ada satu syarat pun yang bisa melihatnya
            # (S26 MENGUMPULKAN rect .gantt-progress lalu tidak membacanya:
            # menghapus `/ 100` meninggalkan 29 syarat hijau). Lebar isian
            # dihitung ulang dari persen yang dipulangkan API, seperti x dan
            # lebar batangnya sendiri.
            pct = row.get("progress_pct")
            if pct not in (None, ""):
                fraction = min(1.0, max(0.0, float(pct) / 100))
                if fraction > 0:
                    entry["progress"] = {"x": round(bx, 2), "w": round(max(1.0, raw_w * fraction), 2)}
        f = by_code.get(row["wbs_code"])
        if f and f.get("planned_start") and f.get("planned_end"):
            fb, fe = _days(f["planned_start"]), _days(f["planned_end"])
            if fe >= fb:
                bx = clamp(x(fb))
                entry["baseline"] = {"x": round(bx, 2), "w": round(max(1.0, clamp(x(fe + DAY_MS)) - bx), 2)}
        geom.append(entry)

    return {
        "row_geometry": geom,
        "rows": len(flat),
        "codes": [row["wbs_code"] for row in flat],
        "from": date.fromordinal(lo // DAY_MS + date(1970, 1, 1).toordinal()).isoformat(),
        "to": date.fromordinal(hi // DAY_MS + date(1970, 1, 1).toordinal()).isoformat(),
        "days": days,
        "day_w": round(day_w, 4),
        "weekend_rects": weekends,
        "week_ticks": week_ticks,
        "month_ticks": month_ticks,
        "baseline_rows": len(matched),
        "baseline_codes": matched,
        "rows_without_baseline": [row["wbs_code"] for row in flat if row["wbs_code"] not in by_code],
        "open_ended": [row["wbs_code"] for row in flat if row.get("planned_start") and not row.get("planned_end")],
        "today_x": round(x(today_ms) + day_w / 2, 2) if lo <= today_ms <= hi else None,
        "today_inside": lo <= today_ms <= hi,
    }


def _pdf_pages(path):
    """Ukuran setiap halaman PDF dari /MediaBox — bukti lanskap yang tidak bisa
    dibantah oleh keyakinan bahwa `@page gantt` bekerja."""
    raw = open(path, "rb").read()
    boxes = re.findall(rb"/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]", raw)
    out = []
    for box in boxes:
        w = round(float(box[2]) - float(box[0]), 2)
        h = round(float(box[3]) - float(box[1]), 2)
        out.append({"w_pt": w, "h_pt": h, "landscape": w > h})
    return out


def gantt_scenario(pg, tag, mobile=False):
    errors = []
    console_errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
    pg.on("console", lambda m: console_errors.append(m.text[:160]) if m.type == "error" else None)

    # Fixture ditanam DI DALAM try, bukan di kepala `out`: penjaga
    # shift_baseline_task() sendiri melempar bila tanggal beku bukan yang
    # diharapkannya, dan pada saat itu plant_open_ended_task() SUDAH menulis
    # barisnya — pemanggilan di kepala berada di luar jangkauan finally, jadi
    # baris tanam itu tertinggal (diukur: B.2 diset 2026-07-15, S26 jatuh, dan
    # prj_wbs_tasks proyek 1 tinggal 12 baris alih-alih 11; verifikasi P1-H
    # putaran 2, 7 Sep 2026).
    out = {"viewport": pg.viewport_size}

    # Fixture dikembalikan di `finally`, bukan di baris pernyataan biasa:
    # satu galat di tengah (wait_for_selector habis waktu, 429, Chromium
    # tersendat) ditangkap dekorator @scenario dan dilanjutkan ke skenario
    # berikutnya — dengan tugas tanam DAN tanggal beku yang tergeser masih
    # tertinggal di basis data bukti. Tidak satu syarat pun akan
    # menyadarinya: setiap harapan dihitung ulang dari muatan API, jadi
    # angka yang cocok tetap cocok (verifikasi P1-H, 7 Sep 2026).
    try:
        out["planted_task"] = plant_open_ended_task()
        out["planted_deviation"] = shift_baseline_task()

        login(pg, "admin@nusantara.test")
        pg.evaluate("() => { location.hash = '#/d/projects/1'; }")
        pg.wait_for_selector(".tabs button", timeout=20000)
        pg.wait_for_timeout(1500)
        out["tabs"] = pg.evaluate("() => [...document.querySelectorAll('.tabs button')].map((b) => b.innerText.trim())")

        # Muatan yang DIBACA layar, diambil dengan token sesi peramban sendiri —
        # inilah yang setiap angka di bawah dibandingkan dengannya.
        tasks = api_in_page(pg, "projects/1/wbs-tasks", {})
        heads = api_in_page(pg, "projects/baselines", {"project_id": 1, "current": 1, "per_page": 1})
        frozen = None
        if heads["status"] == 200 and heads["data"]:
            frozen = api_in_page(pg, f"projects/baselines/{heads['data'][0]['id']}", {})
        out["api"] = {
            "wbs_status": tasks["status"],
            "baseline_status": heads["status"],
            "baseline_code": (frozen or {}).get("data", {}).get("code") if frozen else None,
            "frozen_rows": len(((frozen or {}).get("data") or {}).get("tasks") or []),
            "as_of": (tasks["meta"] or {}).get("as_of"),
            "as_of_source": (tasks["meta"] or {}).get("as_of_source"),
        }

        # "Hari ini" DIUMUMKAN SERVER (meta.as_of), bukan dibaca dari jam mesin
        # ini: aplikasinya berjalan di Asia/Jakarta sementara host harness ini
        # UTC, jadi `date.today()` Python berselisih satu hari dengan server
        # selama tujuh jam setiap hari — pembanding yang akan menyalahkan layar
        # yang benar. Bahwa garisnya benar-benar tidak ikut jam PERAMBAN diukur
        # terpisah oleh S26_gantt_jam_server (dua timezone berjarak 25 jam).
        today = date.fromisoformat(out["api"]["as_of"]) if out["api"]["as_of"] else date.today()
        label_w = 300 if (pg.viewport_size or {}).get("width", 0) >= 900 else 180
        expect = gantt_expectations(tasks["data"] or [], ((frozen or {}).get("data") or {}).get("tasks") or [],
                                    _days(today.isoformat()), label_w=label_w)
        out["label_width"] = label_w
        out["expected"] = expect

        click(pg, ".tabs button:nth-child(2)")
        pg.wait_for_selector(".gantt-sheet svg.chart-gantt", timeout=20000)
        pg.wait_for_timeout(800)

        week = pg.evaluate(S26_MEASURE)
        out["week"] = week
        pg.screenshot(path=f"{OUT}/s26-jadwal-mingguan{tag}.png", full_page=True)

        set_theme(pg, "dark")
        pg.screenshot(path=f"{OUT}/s26-jadwal-gelap{tag}.png", full_page=True)
        set_theme(pg, None)

        # Zoom bulanan: tombol kedua di bilah kartu.
        click(pg, ".gantt-sheet .filters .btn:nth-child(2)")
        pg.wait_for_timeout(700)
        month = pg.evaluate(S26_MEASURE)
        out["month"] = month
        pg.screenshot(path=f"{OUT}/s26-jadwal-bulanan{tag}.png", full_page=True)

        # Kembali ke mingguan LEWAT PAPAN KETIK: mengganti zoom menggambar ulang
        # seluruh kartu, jadi tombol yang barusan ditekan ikut dihancurkan dan
        # fokus jatuh ke <body> — Tab berikutnya memulai lagi dari puncak
        # halaman (diukur 7 Sep 2026: activeElement BODY di kedua ukuran).
        pg.focus(".gantt-sheet .filters .btn:nth-child(1)")
        pg.keyboard.press("Enter")
        pg.wait_for_timeout(700)
        out["keyboard"] = pg.evaluate("""() => {
          const filters = document.querySelector('.gantt-sheet .filters');
          const active = document.activeElement;
          return {
            active_tag: active ? active.tagName : null,
            active_zoom: active && active.dataset ? (active.dataset.zoom || null) : null,
            group_role: filters ? filters.getAttribute('role') : null,
            group_label: filters ? filters.getAttribute('aria-label') : null,
            pressed: [...document.querySelectorAll('.gantt-sheet .filters .btn')].map((b) => b.getAttribute('aria-pressed')),
            note: (document.querySelector('.gantt-sheet text.chart-note') || {}).textContent,
          };
        }""")

        # ------------------------------------------------------------- cetak
        pg.emulate_media(media="print")
        pg.wait_for_timeout(300)
        out["print"] = pg.evaluate(S26_PRINT)
        pg.screenshot(path=f"{OUT}/s26-jadwal-cetak{tag}.png", full_page=True)
        pdf_path = f"{OUT}/s26-jadwal{tag}.pdf"
        try:
            pg.pdf(path=pdf_path, print_background=True)
            out["print"]["pdf_pages"] = _pdf_pages(pdf_path)
        except Exception as e:
            out["print"]["pdf_pages"] = f"GAGAL: {str(e)[:140]}"
        pg.emulate_media(media="screen")

    finally:
        out["cleanup"] = [drop_planted_task(), restore_baseline_task(out["planted_deviation"])]
    out["pageerrors"] = errors
    out["console_errors"] = {"count": len(console_errors), "first": console_errors[:3]}

    # ------------------------------------------------------------ syarat
    baseline_rows = week["baselines"]
    bars = week["bars"]
    # Baris ke-i: bar aktual di y = 41 + 28i, bar baseline di y = 51 + 28i.
    bar_rows = sorted(round((b["y"] - 41) / 28) for b in bars)
    baseline_row_index = sorted(round((b["y"] - 51) / 28) for b in baseline_rows)
    open_bars = [b for b in bars if b["open"] == "end"]
    # Setiap rect dikembalikan ke NOMOR BARISNYA dari koordinat y, lalu
    # dibandingkan dengan geometri yang dihitung ulang dari tanggal API — bukan
    # dengan dirinya sendiri.
    bar_by_row = {round((b["y"] - 41) / 28): b for b in bars}
    base_by_row = {round((b["y"] - 51) / 28): b for b in baseline_rows}
    # Isian progres berbagi y dengan batangnya (mid − 9), jadi nomor barisnya
    # dihitung dengan rumus yang sama.
    prog_by_row = {round((r["y"] - 41) / 28): r for r in week["progress"]}
    mismatch = []
    for i, want in enumerate(expect["row_geometry"]):
        for kind, got_map in (("bar", bar_by_row), ("baseline", base_by_row), ("progress", prog_by_row)):
            got, exp = got_map.get(i), want[kind]
            if (got is None) != (exp is None):
                mismatch.append({"row": i, "code": want["code"], "kind": kind,
                                 "expected": exp, "measured": got and {"x": got["x"], "w": got["w"]}})
            elif got is not None and (abs(got["x"] - exp["x"]) > 0.05 or abs(got["w"] - exp["w"]) > 0.05):
                mismatch.append({"row": i, "code": want["code"], "kind": kind,
                                 "expected": exp, "measured": {"x": got["x"], "w": got["w"]}})

    dev = out["planted_deviation"]
    dev_row = next((i for i, r in enumerate(expect["row_geometry"]) if r["code"] == dev.get("code")), None)
    deviation = None
    if dev_row is not None and dev_row in bar_by_row and dev_row in base_by_row:
        deviation = {
            "code": dev["code"],
            "bar_right": round(bar_by_row[dev_row]["x"] + bar_by_row[dev_row]["w"], 2),
            "baseline_right": round(base_by_row[dev_row]["x"] + base_by_row[dev_row]["w"], 2),
            "expected_gap_px": round(abs(dev["days"]) * expect["day_w"], 2),
        }
        deviation["measured_gap_px"] = round(deviation["bar_right"] - deviation["baseline_right"], 2)

    out["geometry"] = {
        "bar_rows": bar_rows,
        "baseline_rows": baseline_row_index,
        "baseline_offset_px": sorted({round(b["y"] - a["y"], 2)
                                      for b in baseline_rows for a in bars
                                      if round((b["y"] - 51) / 28) == round((a["y"] - 41) / 28)}),
        "today_x_measured": week["today_x"],
        "today_x_expected": expect["today_x"],
        "row_geometry_mismatch": mismatch,
        "planted_deviation": deviation,
    }

    checks = {
        # 1. Setiap tugas yang dipulangkan server punya barisnya di layar.
        "every_task_the_api_returned_has_a_row": len(week["labels"]) == expect["rows"],
        "row_labels_are_code_plus_name": all(
            lab["full"].startswith(code + " ") for lab, code in zip(week["labels"], expect["codes"])),
        # Nama panjang dipatahkan ke DUA baris alih-alih dipotong; yang tetap
        # tidak muat wajib membawa nama lengkapnya di <title> — dan angkanya
        # dicatat, bukan dibiarkan tak terlihat seperti sebelumnya (S26 dulu
        # merekam labels[].truncated lalu tidak menegaskan apa pun tentangnya:
        # 10 dari 12 terpotong di desktop, angka yang sama dengan di ponsel).
        "long_names_wrap_instead_of_being_cut": any(lab["lines"] > 1 for lab in week["labels"]),
        "every_truncated_label_keeps_its_full_name": all(
            lab["title"] == lab["full"] for lab in week["labels"] if lab["truncated"]),
        # 2. Garis hari ini ADA dan berdiri di x yang benar untuk jendelanya.
        "today_line_drawn": (week["today_x"] is not None) == expect["today_inside"],
        "today_line_at_the_right_x": expect["today_x"] is None or abs(week["today_x"] - expect["today_x"]) <= 0.05,
        "today_line_is_labelled": week["today_label"] == "Hari ini",
        # …dan tanggalnya datang dari server, kanal yang sama dengan EVM.
        "the_today_line_reads_the_server_date": out["api"]["as_of_source"] == "server"
            and bool(out["api"]["as_of"]),
        # 3. Bayangan akhir pekan = jumlah Sabtu (+ Minggu pembuka) di jendela.
        "weekend_shading_matches_the_window": len(week["weekends"]) == expect["weekend_rects"],
        # 4. Bar baseline HANYA untuk baris yang punya baseline, dan DI BAWAH
        #    bar aktualnya (mid + 1 vs mid − 9 = selisih 10 px).
        "baseline_bars_only_where_a_baseline_exists": len(baseline_rows) == expect["baseline_rows"],
        "baseline_bar_sits_under_the_actual_bar": out["geometry"]["baseline_offset_px"] == [10.0],
        # Setiap bar (aktual DAN baseline) berdiri di x dan lebar yang dihitung
        # ulang dari tanggal API-nya sendiri — 24 rect, tanpa satu pun meleset
        # lebih dari 0,05 px.
        "every_bar_stands_where_its_dates_say": not [m for m in mismatch if m["kind"] != "progress"],
        # …dan setiap ISIAN progres selebar persen yang dikirim API dikali lebar
        # batangnya. Ini sumbu yang dulu tidak diperiksa apa pun: menghapus
        # `/ 100` di jadwal.js membuat kesebelas batang terisi penuh sementara
        # 29 syarat tetap hijau.
        "every_progress_fill_matches_the_percentage_the_api_sent":
            not [m for m in mismatch if m["kind"] == "progress"],
        # …dan bar baseline benar-benar digambar dari tanggal BEKU: satu tanggal
        # beku digeser 30 hari, dan selisihnya muncul di layar sebesar itu.
        "a_shifted_baseline_shows_the_deviation": deviation is not None
            and abs(deviation["measured_gap_px"] - deviation["expected_gap_px"]) <= 0.05,
        # 5. Zoom mingguan vs bulanan benar-benar mengubah kerapatan tick.
        "week_ticks_match_the_mondays": len(week["ticks"]) == expect["week_ticks"],
        "month_ticks_match_the_first_of_months": len(month["ticks"]) == expect["month_ticks"],
        "zoom_really_changes_tick_density": len(week["ticks"]) > len(month["ticks"]),
        "zoom_button_marks_itself_active": [b["primary"] for b in month["zoom_buttons"]][:2] == [False, True],
        # …dan mengatakannya ke pembaca layar, bukan hanya dengan warna.
        "zoom_buttons_announce_the_active_scale": [b["pressed"] for b in month["zoom_buttons"]][:2] == ["false", "true"]
            and out["keyboard"]["pressed"][:2] == ["true", "false"]
            and out["keyboard"]["group_role"] == "group" and bool(out["keyboard"]["group_label"]),
        # Zoom lewat papan ketik: fokus tetap pada tombolnya, tidak jatuh ke <body>.
        "keyboard_zoom_keeps_the_focus_on_the_button": out["keyboard"]["active_zoom"] == "week"
            and out["keyboard"]["active_tag"] == "BUTTON",
        "keyboard_zoom_really_switched_the_scale": "Skala mingguan" in (out["keyboard"]["note"] or ""),
        # 6. Tugas tanpa tanggal selesai: bar terbuka + tepi putus + namanya.
        "open_ended_task_draws_an_open_bar": len(open_bars) == len(expect["open_ended"]),
        "open_bar_has_a_dashed_edge": week["open_edges"] == len(expect["open_ended"]),
        "open_bar_title_names_the_task_and_says_why": bool(open_bars) and all(
            "tanggal selesai belum ditetapkan (bar terbuka)" in b["title"] for b in open_bars),
        # 7. Setiap mark membawa tepat satu <title>.
        "every_mark_carries_a_title": week["marks"] == week["marks_with_title"] and week["marks"] > 0,
        # 8. Legenda hanya menyebut yang memang tergambar.
        "legend_names_only_what_is_drawn": ("Baseline" in week["legend"]) == (len(baseline_rows) > 0)
            and ("Hari ini" in week["legend"]) == (week["today_x"] is not None),
        # Bar berujung putus-putus ikut dijelaskan legenda: <title>-nya tidak
        # terjangkau di ponsel, di kertas, maupun oleh pembaca layar.
        "legend_names_the_open_ended_bar":
            ("Tanggal belum ditetapkan" in week["legend"]) == (len(expect["open_ended"]) > 0),
        # 9. Kaki kartu mengumumkan sumbernya dan menyebut yang TIDAK cocok.
        "source_note_states_the_matching_rule": "dicocokkan menurut kode WBS" in (week["note"] or ""),
        # …dan kalimat itu bisa DIBACA tanpa menebak bahwa gambarnya bisa
        # digulir: salinan DOM-nya di kaki kartu, seluruhnya di dalam lebar
        # kartu (di ponsel kalimat di dalam svg terpotong 85,7 px).
        "the_source_note_is_readable_as_dom_text": (week["dom_note"] or "").strip() == (week["note"] or "").strip(),
        "the_dom_source_note_fits_the_card_width": week["boxes"]["dom_note"] is not None
            and week["boxes"]["dom_note"]["right"] <= week["boxes"]["scroller"]["right"] + 1,
        # Garis "Hari ini" ADA DI DALAM jendela penggulir pada gambar pertama.
        "the_today_line_is_inside_the_visible_window": week["boxes"]["today"] is None or (
            week["boxes"]["today"]["left"] >= week["boxes"]["scroller"]["left"] - 1
            and week["boxes"]["today"]["right"] <= week["boxes"]["scroller"]["right"] + 1),
        "source_note_counts_the_matches": f"{expect['baseline_rows']} dari {expect['rows']} tugas cocok" in (week["note"] or ""),
        "legend_says_dependencies_are_not_drawn": any("Ketergantungan antar tugas tidak digambar" in f for f in week["foot"]),
        # 10. Cetak: lanskap sungguhan, dan gantt tidak terpotong.
        "print_page_is_landscape": isinstance(out["print"]["pdf_pages"], list)
            and any(p["landscape"] for p in out["print"]["pdf_pages"]),
        "print_releases_the_horizontal_scroll": out["print"]["scroll_overflow_x"] == "visible",
        "print_drops_the_min_width_so_the_chart_fits": out["print"]["svg_min_width"] in ("0px", "auto"),
        "print_hides_the_zoom_bar_but_keeps_the_title": not out["print"]["filters_visible"] and out["print"]["head_visible"],
        "print_keeps_the_source_note_on_paper": out["print"]["note_in_svg"],
        "no_page_errors": not errors,
    }

    # Ukuran teks: 11 px CSS DIKALI skala viewBox. Di desktop svg melebar di atas
    # 900 px alaminya, jadi teksnya justru lebih besar; di ponsel `min-width`
    # 80 % (720 px) adalah lantai yang DISENGAJA charts.js, dan angkanya dicatat
    # apa adanya alih-alih dipoles.
    out["text_px"] = {"label": week["font_css_px"]["label"], "tick": week["font_css_px"]["tick"],
                      "svg_rendered_px": week["box"]["w"], "scrolls_horizontally": week["scroll_x"],
                      "label_width_units": out.get("label_width"),
                      "labels_truncated": sum(1 for lab in week["labels"] if lab["truncated"]),
                      "labels_wrapped": sum(1 for lab in week["labels"] if lab["lines"] > 1),
                      "labels_total": len(week["labels"])}
    checks["text_is_at_least_11px" if not mobile else "text_is_at_least_the_designed_mobile_floor"] = (
        week["font_css_px"]["label"] >= (11 if not mobile else 8.8)
        and week["font_css_px"]["tick"] >= (11 if not mobile else 8.8))

    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S26_gantt")
def s26(pg):
    return gantt_scenario(pg, "-p1h")


@scenario("S26_gantt_mobile")
def s26m(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        return gantt_scenario(pg, "-mobile-p1h", mobile=True)
    finally:
        ctx.close()


def plant_twin_live_task(project_id=1, code="B.3", name="Pembesian TOWER B (kode kembar)"):
    """Tugas HIDUP kedua dengan kode WBS yang sudah dipakai — sah menurut basis
    data (indeks (project_id, wbs_code) bukan unique) dan menurut validasinya
    (`required|string|max:20`), dan tidak ada satu pun di data demo."""
    con = sqlite3.connect(DB)
    try:
        parent = con.execute("SELECT id FROM prj_wbs_tasks WHERE project_id = ? AND wbs_code = 'B'", (project_id,)).fetchone()
        if parent is None:
            return {"state": "induk B tidak ada"}
        con.execute("DELETE FROM prj_wbs_tasks WHERE project_id = ? AND name = ?", (project_id, name))
        cur = con.execute(
            "INSERT INTO prj_wbs_tasks (project_id, parent_id, wbs_code, name, weight_pct, planned_start,"
            " planned_end, progress_pct, sort_order, created_at, updated_at)"
            " VALUES (?, ?, ?, ?, 0, '2026-04-01 00:00:00', '2026-08-30 00:00:00', 10, 9, datetime('now'), datetime('now'))",
            (project_id, parent[0], code, name))
        con.commit()
        return {"state": "ditanam", "id": cur.lastrowid, "code": code, "name": name}
    finally:
        con.close()


def plant_twin_frozen_task(code="B.3", source="B.4", name="Baris beku kembar (fixture S26)"):
    """Baris BEKU kedua dengan kode yang sudah ada, disalin dari baris beku lain.

    Disisipkan, bukan diubah: baseline yang disetujui tidak boleh ditulisi
    fixture, dan sebuah baris tambahan bisa dihapus lagi tanpa menyentuh satu
    byte pun milik baris aslinya."""
    con = sqlite3.connect(DB)
    try:
        con.execute("DELETE FROM prj_baseline_tasks WHERE name = ?", (name,))
        cur = con.execute(
            "INSERT INTO prj_baseline_tasks (baseline_id, wbs_task_id, wbs_code, parent_wbs_code, name,"
            " is_leaf, weight_pct, planned_start, planned_end, sort_order, created_at, updated_at)"
            " SELECT baseline_id, wbs_task_id, ?, parent_wbs_code, ?, is_leaf, weight_pct, planned_start,"
            " planned_end, sort_order + 1, datetime('now'), datetime('now')"
            " FROM prj_baseline_tasks WHERE wbs_code = ? ORDER BY id LIMIT 1", (code, name, source))
        con.commit()
        if cur.rowcount != 1:
            return {"state": f"baris beku {source} tidak ada"}
        return {"state": "ditanam", "id": cur.lastrowid, "code": code, "name": name}
    finally:
        con.close()


def drop_planted_rows(planted):
    out = []
    con = sqlite3.connect(DB)
    try:
        for table, row in planted:
            if row.get("state") != "ditanam":
                out.append({"state": "tidak ada yang dihapus", "sebab": row.get("state")})
                continue
            n = con.execute(f"DELETE FROM {table} WHERE id = ?", (row["id"],)).rowcount
            out.append({"state": "dibersihkan" if n == 1 else f"GAGAL: {n} baris", "table": table, "id": row["id"]})
        con.commit()
        return out
    finally:
        con.close()


def plant_long_schedule(project_id=2, parents=4, children=9, mark="S26 cetak panjang"):
    """Jadwal setinggi 40 baris pada proyek yang belum punya WBS — ukuran biasa
    untuk pekerjaan gedung, dan lebih tinggi daripada satu kertas."""
    con = sqlite3.connect(DB)
    try:
        cur = con.cursor()
        cur.execute("DELETE FROM prj_wbs_tasks WHERE project_id = ? AND name LIKE ?", (project_id, f"{mark}%"))
        rows = 0
        for section in range(parents):
            code = chr(ord("A") + section)
            cur.execute(
                "INSERT INTO prj_wbs_tasks (project_id, parent_id, wbs_code, name, weight_pct, planned_start,"
                " planned_end, progress_pct, sort_order, created_at, updated_at)"
                " VALUES (?, NULL, ?, ?, 0, ?, ?, ?, ?, datetime('now'), datetime('now'))",
                (project_id, code, f"{mark} — bagian {code}", f"2026-0{(section % 9) + 1}-01 00:00:00",
                 f"2026-1{section % 2}-28 00:00:00", section * 10, section * 100))
            parent = cur.lastrowid
            rows += 1
            for item in range(1, children + 1):
                cur.execute(
                    "INSERT INTO prj_wbs_tasks (project_id, parent_id, wbs_code, name, weight_pct, planned_start,"
                    " planned_end, progress_pct, sort_order, created_at, updated_at)"
                    " VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, datetime('now'), datetime('now'))",
                    (project_id, parent, f"{code}.{item}", f"{mark} — paket {code}.{item} lantai {item}",
                     f"2026-0{(item % 9) + 1}-05 00:00:00", f"2026-{(item % 12) + 1:02d}-20 00:00:00",
                     (item * 7) % 100, section * 100 + item))
                rows += 1
        con.commit()
        return {"state": "ditanam", "rows": rows, "project_id": project_id, "mark": mark}
    finally:
        con.close()


def drop_long_schedule(planted):
    if planted.get("state") != "ditanam":
        return {"state": "tidak ada yang dihapus", "sebab": planted.get("state")}
    con = sqlite3.connect(DB)
    try:
        n = con.execute("DELETE FROM prj_wbs_tasks WHERE project_id = ? AND name LIKE ?",
                        (planted["project_id"], f"{planted['mark']}%")).rowcount
        con.commit()
        left = con.execute("SELECT count(*) FROM prj_wbs_tasks WHERE project_id = ?", (planted["project_id"],)).fetchone()[0]
        return {"state": "dibersihkan" if n == planted["rows"] else f"GAGAL: {n} dari {planted['rows']}", "tersisa": left}
    finally:
        con.close()


@scenario("S26_gantt_cetak_panjang")
def s26p(pg):
    """Cetak jadwal yang lebih tinggi daripada satu kertas.

    S26 mencetak proyek 12 baris yang gambarnya muat satu halaman, jadi
    `print_page_is_landscape` hijau apa pun yang terjadi pada jadwal yang
    sungguhan. Diukur 7 Sep 2026 pada jadwal 66 baris: 7 halaman — halaman 2
    memuat HANYA judul kartu, halaman 3 kosong sama sekali, halaman 5 dan 6
    memuat baris TANPA satu pun sumbu tanggal, dan satu baris terbelah di batas
    halaman. Tidak ada baris yang hilang, tetapi tiga dari empat halaman gambar
    tidak punya cara untuk tahu bar-nya berdiri di bulan apa.
    """
    out = {"viewport": pg.viewport_size}
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))

    planted = plant_long_schedule()
    out["planted"] = planted

    try:
        login(pg, "admin@nusantara.test")
        pg.evaluate("() => { location.hash = '#/d/projects/2'; }")
        pg.wait_for_selector(".tabs button", timeout=20000)
        pg.wait_for_timeout(1500)
        click(pg, ".tabs button:nth-child(2)")
        pg.wait_for_selector(".gantt-sheet svg.chart-gantt", timeout=25000)
        pg.wait_for_timeout(1000)

        pg.emulate_media(media="print")
        pg.wait_for_timeout(400)
        out["print"] = pg.evaluate("""() => {
          const pages = [...document.querySelectorAll('.gantt-print-page')];
          const screenChart = document.querySelector('.gantt-sheet .chart-scroll');
          return {
            screen_rows: [...document.querySelectorAll('.gantt-sheet .chart-scroll text.gantt-label')].map((t) => t.dataset.full),
            screen_chart_display: screenChart ? getComputedStyle(screenChart).display : null,
            print_display: (() => { const p = document.querySelector('.gantt-print');
              return p ? getComputedStyle(p).display : null; })(),
            pages: pages.map((page) => ({
              rows: [...page.querySelectorAll('text.gantt-label')].map((t) => t.dataset.full),
              tick_labels: page.querySelectorAll('text.gantt-tick-label').length,
              month_band: page.querySelectorAll('text.gantt-group').length,
              note: (page.querySelector('text.chart-note') || {}).textContent,
              break_after: getComputedStyle(page).breakAfter,
            })),
          };
        }""")
        pdf_path = f"{OUT}/s26-jadwal-cetak-panjang-p1h.pdf"
        pg.pdf(path=pdf_path, print_background=True)
        out["pdf_pages"] = _pdf_pages(pdf_path)
        pg.emulate_media(media="screen")
    finally:
        out["cleanup"] = drop_long_schedule(planted)

    pages = out["print"]["pages"]
    printed_rows = [row for page in pages for row in page["rows"]]
    landscape = [p for p in out["pdf_pages"] if p["landscape"]]
    out["pageerrors"] = errors

    checks = {
        # Gambarnya DIPOTONG jadi halaman: satu svg tidak bisa dipaginasi.
        "a_long_schedule_is_split_into_pages": len(pages) >= 3,
        "the_screen_chart_is_not_printed_as_well": out["print"]["screen_chart_display"] == "none"
            and out["print"]["print_display"] == "block",
        # Tidak ada baris yang hilang, dan tidak ada yang tercetak dua kali.
        "every_row_is_printed_exactly_once": printed_rows == out["print"]["screen_rows"],
        # SETIAP halaman gambar punya sumbu tanggalnya sendiri — inilah cacatnya.
        "every_printed_page_carries_the_date_axis": all(
            page["tick_labels"] > 0 and page["month_band"] > 0 for page in pages),
        "every_printed_page_says_which_rows_it_carries": all(
            f"halaman {i + 1} dari {len(pages)}" in (page["note"] or "") for i, page in enumerate(pages)),
        # Halaman lanskap sungguhan, sebanyak potongannya — tidak ada halaman
        # kosong yang terselip (dulu: 7 halaman untuk 4 halaman gambar).
        "the_pdf_has_one_landscape_page_per_chunk": len(landscape) == len(pages),
        "no_blank_page_is_produced": len(out["pdf_pages"]) == len(pages) + 1,
        "the_fixture_is_gone_again": out["cleanup"]["state"] == "dibersihkan",
        "no_page_errors": not errors,
    }

    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S26_gantt_kode_kembar")
def s26d(pg):
    """Kode WBS ganda — DI KEDUA SISI, di peramban.

    Basis data tidak menjamin `wbs_code` unik: indeks `(baseline_id, wbs_code)`
    dan `(project_id, wbs_code)` sama-sama bukan `unique` dan validasinya hanya
    `required|string|max:20`. Data demo tidak punya satu pun tabrakan, jadi
    kalimat peringatannya belum pernah dilihat siapa pun (butir "belum
    diverifikasi" #8 laporan paket) — dan sampai verifikasi P1-H sisi HIDUP
    tidak dihitung sama sekali: dua baris memakai baris beku yang sama, 12 bar
    baseline tergambar untuk baseline berisi 11 baris, dan kaki kartu
    mengumumkan "12 dari 12 tugas cocok" tanpa sepatah kata.
    """
    out = {"viewport": pg.viewport_size}
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))

    MEASURE = """() => {
      const svg = document.querySelector('.gantt-sheet svg.chart-gantt');
      return {
        bars: svg.querySelectorAll('rect.gantt-bar').length,
        baselines: svg.querySelectorAll('rect.gantt-baseline').length,
        twin_baseline_titles: [...svg.querySelectorAll('rect.gantt-baseline title')]
          .map((t) => t.textContent).filter((t) => t.startsWith('B.3 ')),
        note: (svg.querySelector('text.chart-note') || {}).textContent,
        foot: [...document.querySelectorAll('.gantt-sheet .card-body p')].map((p) => p.innerText.trim()),
      };
    }"""

    def open_jadwal():
        pg.evaluate("() => { location.hash = '#/dashboard'; }")
        pg.wait_for_timeout(500)
        pg.evaluate("() => { location.hash = '#/d/projects/1'; }")
        pg.wait_for_selector(".tabs button", timeout=20000)
        pg.wait_for_timeout(1200)
        click(pg, ".tabs button:nth-child(2)")
        pg.wait_for_selector(".gantt-sheet svg.chart-gantt", timeout=20000)
        pg.wait_for_timeout(800)

    planted = []

    try:
        login(pg, "admin@nusantara.test")

        # Tahap 1 — kembaran di sisi HIDUP saja: dua baris layar memakai SATU
        # baris beku, jadi jumlah "cocok" melampaui isi baseline.
        live_twin = plant_twin_live_task()
        planted.append(("prj_wbs_tasks", live_twin))
        open_jadwal()
        frozen_rows = len(((api_in_page(pg, "projects/baselines/1", {}) or {}).get("data") or {}).get("tasks") or [])
        out["frozen_rows_before"] = frozen_rows
        out["live_twin"] = pg.evaluate(MEASURE)
        pg.screenshot(path=f"{OUT}/s26-jadwal-kode-kembar-p1h.png", full_page=True)

        # Tahap 2 — kembaran di sisi BEKU juga: penjaga yang sudah ada sejak
        # awal, yang data demo tidak pernah memicunya.
        planted.append(("prj_baseline_tasks", plant_twin_frozen_task()))
        open_jadwal()
        out["both_twins"] = pg.evaluate(MEASURE)
    finally:
        out["cleanup"] = drop_planted_rows(planted)

    live_foot = " | ".join(out["live_twin"]["foot"])
    both_foot = " | ".join(out["both_twins"]["foot"])
    checks = {
        # Sisi HIDUP: dua baris berbagi satu baris beku — dan itu disebut.
        "a_duplicate_live_code_is_named": "Kode WBS ganda pada WBS yang berlaku: B.3" in live_foot,
        "the_note_admits_the_match_count_exceeds_the_baseline":
            f"dari {out['frozen_rows_before']} baris beku" in (out["live_twin"]["note"] or ""),
        "both_twins_draw_their_own_baseline_bar":
            out["live_twin"]["baselines"] == out["live_twin"]["bars"]
            and len(out["live_twin"]["twin_baseline_titles"]) == 2,
        # Sisi BEKU: penjaga yang sudah ada, kini benar-benar terlihat.
        "a_duplicate_frozen_code_is_named": "Kode WBS ganda pada baseline: B.3" in both_foot,
        "no_page_errors": not errors,
        "the_fixtures_are_gone_again": all(row["state"] == "dibersihkan" for row in out["cleanup"]),
    }

    out["pageerrors"] = errors
    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


def _flat_codes(nodes, out=None):
    out = [] if out is None else out
    for node in nodes:
        out.append(node["wbs_code"])
        _flat_codes(node.get("children") or [], out)
    return out


@scenario("S26_gantt_jam_server")
def s26t(browser):
    """Garis "Hari ini" digambar dari tanggal SERVER, bukan dari jam peramban.

    Ini tidak bisa dibuktikan dengan satu peramban di mesin yang sama: harness
    menghitung `today_x` dari `date.today()` Python pada host yang juga
    menjalankan server, jadi jam peramban dan jam pembanding selalu identik dan
    sebuah gantt yang membaca jam KLIEN tetap hijau (itulah keadaan S26 sampai
    verifikasi P1-H, 7 Sep 2026 — diukur: Asia/Jakarta x=484,67 vs
    America/Los_Angeles x=483,27 pada berkas dan jam server yang sama).

    Karena itu dua konteks dengan timezone yang JARAKNYA 25 jam: Pacific/Niue
    (UTC−11) dan Pacific/Kiritimati (UTC+14) tidak pernah berada di tanggal
    lokal yang sama, kapan pun skenario ini dijalankan. Kalau garisnya lahir
    dari jam peramban, kedua x itu berbeda; kalau ia lahir dari `meta.as_of`,
    keduanya identik — dan sama dengan tanggal yang server umumkan.
    """
    out = {"contexts": {}}
    errors = []

    for zone in ("Pacific/Niue", "Pacific/Kiritimati"):
        ctx = browser.new_context(viewport={"width": 1440, "height": 900}, timezone_id=zone)
        pg = ctx.new_page()
        pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
        try:
            login(pg, "admin@nusantara.test")
            pg.evaluate("() => { location.hash = '#/d/projects/1'; }")
            pg.wait_for_selector(".tabs button", timeout=20000)
            pg.wait_for_timeout(1200)
            click(pg, ".tabs button:nth-child(2)")
            pg.wait_for_selector(".gantt-sheet svg.chart-gantt", timeout=20000)
            pg.wait_for_timeout(600)

            tasks = api_in_page(pg, "projects/1/wbs-tasks", {})
            out["contexts"][zone] = {
                "browser_date": pg.evaluate("() => new Date().toLocaleDateString('sv-SE')"),
                "today_x": pg.evaluate("""() => { const l = document.querySelector('.gantt-sheet line.gantt-today');
                    return l ? +(+l.getAttribute('x1')).toFixed(2) : null; }"""),
                "server_as_of": (tasks["meta"] or {}).get("as_of"),
                "as_of_source": (tasks["meta"] or {}).get("as_of_source"),
            }
        finally:
            ctx.close()

    niue, kiri = out["contexts"]["Pacific/Niue"], out["contexts"]["Pacific/Kiritimati"]
    out["pageerrors"] = errors

    checks = {
        # Prasyarat: kalau kedua peramban ternyata sehari, skenario ini tidak
        # menguji apa pun dan harus mengatakannya, bukan hijau dengan percuma.
        "the_two_browsers_really_are_on_different_dates": niue["browser_date"] != kiri["browser_date"],
        "the_server_announces_its_own_date": bool(niue["server_as_of"]) and niue["as_of_source"] == "server"
            and niue["server_as_of"] == kiri["server_as_of"],
        "the_today_line_is_drawn_in_both": niue["today_x"] is not None and kiri["today_x"] is not None,
        "the_today_line_does_not_move_with_the_browser_clock": niue["today_x"] == kiri["today_x"],
        "no_page_errors": not errors,
    }

    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S26_gantt_baseline_gagal")
def s26f(pg):
    """Baseline yang GAGAL dibaca vs baseline yang memang TIDAK ADA.

    Sampai verifikasi P1-H keduanya sama saja bagi layar ini: `.catch(() => null)`
    dan satu kalimat "belum ada baseline beku" — fakta yang dikarang tentang
    rencana beku sebuah proyek yang BARU SAJA terbaca punya baseline disetujui,
    di layar yang justru ada untuk membandingkan rencana dengan kenyataan, dan
    PANDUAN §7.2 mengajarkan pemakainya membaca kalimat itu sebagai "bukan
    galat". Tidak ada satu pun uji PHP yang bisa melihat ini: kalimatnya lahir
    di peramban, dari cabang yang hanya diambil ketika permintaan ditolak.

    Ketiga keadaan dipaksa di sini dengan mencegat permintaannya, lalu
    cegatannya DILEPAS dan tombol "Coba lagi" diklik — sebuah pintu keluar yang
    tidak benar-benar memulihkan bar pembandingnya bukan pintu keluar.
    """
    out = {"viewport": pg.viewport_size}
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))

    login(pg, "admin@nusantara.test")

    def read():
        return pg.evaluate("""() => {
          const sheet = document.querySelector('.gantt-sheet');
          const svg = sheet ? sheet.querySelector('svg.chart-gantt') : null;
          return {
            bars: svg ? svg.querySelectorAll('rect.gantt-bar').length : 0,
            baselines: svg ? svg.querySelectorAll('rect.gantt-baseline').length : 0,
            note: svg ? (svg.querySelector('text.chart-note') || {}).textContent : null,
            legend: svg ? [...svg.querySelectorAll('text.chart-legend')].map((t) => t.textContent) : [],
            foot: sheet ? [...sheet.querySelectorAll('.card-body p')].map((p) => p.innerText.trim()) : [],
            retry: sheet ? [...sheet.querySelectorAll('.card-body button')].map((b) => b.innerText.trim()) : [],
          };
        }""")

    def open_jadwal():
        pg.evaluate("() => { location.hash = '#/d/projects/1'; }")
        pg.wait_for_selector(".tabs button", timeout=20000)
        pg.wait_for_timeout(1200)
        click(pg, ".tabs button:nth-child(2)")
        pg.wait_for_selector(".gantt-sheet svg.chart-gantt", timeout=20000)
        pg.wait_for_timeout(600)

    # 1. Daftar baseline ditolak: keberadaan baselinenya TIDAK diketahui.
    pg.route("**/api/projects/baselines?**", lambda route: route.fulfill(
        status=500, content_type="application/json", body='{"message":"Server Error"}'))
    open_jadwal()
    out["list_500"] = read()
    pg.screenshot(path=f"{OUT}/s26-jadwal-baseline-gagal-p1h.png", full_page=True)
    pg.unroute("**/api/projects/baselines?**")

    # 2. Daftarnya menjawab, ISI baselinenya yang ditolak: kodenya diketahui.
    pg.route("**/api/projects/baselines/*", lambda route: route.fulfill(
        status=500, content_type="application/json", body='{"message":"Server Error"}'))
    pg.evaluate("() => { location.hash = '#/dashboard'; }")
    pg.wait_for_timeout(800)
    open_jadwal()
    out["show_500"] = read()

    # 3. Cegatan dilepas, "Coba lagi" diklik — bar pembandingnya harus kembali.
    pg.unroute("**/api/projects/baselines/*")
    click(pg, ".gantt-sheet .card-body button:has-text('Coba lagi')")
    pg.wait_for_selector(".gantt-sheet svg.chart-gantt", timeout=20000)
    pg.wait_for_timeout(800)
    out["after_retry"] = read()
    out["pageerrors"] = errors

    absence = "belum ada baseline beku"
    checks = {
        # Kegagalan TIDAK BOLEH memakai kalimat ketiadaan — itulah cacatnya.
        "a_failed_baseline_list_does_not_claim_there_is_none": absence not in (out["list_500"]["note"] or ""),
        "a_failed_baseline_list_says_it_does_not_know": "tidak tahu apakah" in (out["list_500"]["note"] or ""),
        "a_failed_baseline_list_names_the_http_status": "HTTP 500" in (out["list_500"]["note"] or ""),
        "a_failed_baseline_show_does_not_claim_there_is_none": absence not in (out["show_500"]["note"] or ""),
        "a_failed_baseline_show_names_the_baseline_it_could_not_read":
            "BSL/2026/VIII/0001" in (out["show_500"]["note"] or ""),
        # Jadwalnya sendiri tetap tergambar: yang hilang hanya pembandingnya.
        "the_schedule_is_still_drawn_without_its_baseline":
            out["list_500"]["bars"] > 0 and out["list_500"]["baselines"] == 0,
        "the_legend_drops_baseline_when_none_is_drawn": "Baseline" not in out["list_500"]["legend"],
        # …dan pembacanya diberi pintu keluar, yang benar-benar bekerja.
        "a_failure_offers_a_retry": "Coba lagi" in out["list_500"]["retry"]
            and "Coba lagi" in out["show_500"]["retry"],
        "retry_brings_the_baseline_bars_back": out["after_retry"]["baselines"] > 0
            and "dicocokkan menurut kode WBS" in (out["after_retry"]["note"] or ""),
        "retry_removes_the_failure_sentence": out["after_retry"]["retry"] == [],
        "no_page_errors": not errors,
    }

    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


# ------------------------------------------------------------ S21 (P1-B)
# Aksen modul, remah roti → beranda modul, kepadatan, keadaan kosong berilustrasi — desktop
# 1440×900 (S21) dan ponsel 390×844 (S21m), masing-masing di tema terang DAN gelap. Yang
# dicatat adalah nilai terukur: token --accent-1..8 (+ -soft, -fg) yang hidup di halaman,
# ΔE2000 antar slot (dihitung di sini, di Lab — rumus yang sama dengan skrip turunan palet) dan
# kontras WCAG (aksen di --surface, -fg di aksen, aksen di -soft); warna terkomputasi penanda grup
# aktif dan remah modul dibandingkan dengan token slot modul itu; href remah = #/m/<prefix> dan
# klik benar-benar berpindah; beranda modul memuat PERSIS tautan grup sidebar (admin: semua grup;
# warehouse@: grupnya sendiri, dan grup yang tidak ia pegang berakhir di keadaan kosong); kontrol
# Kepadatan mengubah tinggi baris satu-baris menjadi 32/38,5/48 dan bertahan setelah muat ulang;
# lima jenis ilustrasi keadaan kosong punya stroke yang resolve ke token; daftar tersaring habis
# menampilkan "Hapus filter" yang bekerja.

def _lin(c):
    return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4

def _rgb(hexs):
    h = hexs.lstrip("#"); return tuple(int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))

def wcag(a, b):
    la = 0.2126 * _lin(_rgb(a)[0]) + 0.7152 * _lin(_rgb(a)[1]) + 0.0722 * _lin(_rgb(a)[2])
    lb = 0.2126 * _lin(_rgb(b)[0]) + 0.7152 * _lin(_rgb(b)[1]) + 0.0722 * _lin(_rgb(b)[2])
    return round((max(la, lb) + 0.05) / (min(la, lb) + 0.05), 2)

def _lab(hexs):
    import math
    r, g, b = [_lin(c) * 100 for c in _rgb(hexs)]
    x = (r * 0.4124 + g * 0.3576 + b * 0.1805) / 95.047; y = (r * 0.2126 + g * 0.7152 + b * 0.0722) / 100.0; z = (r * 0.0193 + g * 0.1192 + b * 0.9505) / 108.883
    f = lambda t: t ** (1 / 3) if t > 0.008856 else (7.787 * t) + 16 / 116
    return 116 * f(y) - 16, 500 * (f(x) - f(y)), 200 * (f(y) - f(z))

def de2000(h1, h2):
    import math
    L1, a1, b1 = _lab(h1); L2, a2, b2 = _lab(h2)
    C1 = math.hypot(a1, b1); C2 = math.hypot(a2, b2); Cb = (C1 + C2) / 2
    G = 0.5 * (1 - math.sqrt(Cb ** 7 / (Cb ** 7 + 25 ** 7)))
    a1p, a2p = (1 + G) * a1, (1 + G) * a2
    C1p, C2p = math.hypot(a1p, b1), math.hypot(a2p, b2)
    hp = lambda a, b: 0 if a == 0 and b == 0 else (math.degrees(math.atan2(b, a)) % 360)
    h1p, h2p = hp(a1p, b1), hp(a2p, b2)
    dLp, dCp = L2 - L1, C2p - C1p
    if C1p * C2p == 0: dhp = 0
    elif abs(h2p - h1p) <= 180: dhp = h2p - h1p
    elif h2p - h1p > 180: dhp = h2p - h1p - 360
    else: dhp = h2p - h1p + 360
    dHp = 2 * math.sqrt(C1p * C2p) * math.sin(math.radians(dhp / 2))
    Lbp, Cbp = (L1 + L2) / 2, (C1p + C2p) / 2
    if C1p * C2p == 0: hbp = h1p + h2p
    elif abs(h1p - h2p) <= 180: hbp = (h1p + h2p) / 2
    elif h1p + h2p < 360: hbp = (h1p + h2p + 360) / 2
    else: hbp = (h1p + h2p - 360) / 2
    T = 1 - 0.17 * math.cos(math.radians(hbp - 30)) + 0.24 * math.cos(math.radians(2 * hbp)) + 0.32 * math.cos(math.radians(3 * hbp + 6)) - 0.20 * math.cos(math.radians(4 * hbp - 63))
    dth = 30 * math.exp(-((hbp - 275) / 25) ** 2)
    RC = 2 * math.sqrt(Cbp ** 7 / (Cbp ** 7 + 25 ** 7))
    SL = 1 + (0.015 * (Lbp - 50) ** 2) / math.sqrt(20 + (Lbp - 50) ** 2)
    SC = 1 + 0.045 * Cbp; SH = 1 + 0.015 * Cbp * T
    RT = -math.sin(math.radians(2 * dth)) * RC
    return round(math.sqrt((dLp / SL) ** 2 + (dCp / SC) ** 2 + (dHp / SH) ** 2 + RT * (dCp / SC) * (dHp / SH)), 1)

def rgb_to_hex(css):
    m = re.findall(r"\d+", css or "")
    return "#%02x%02x%02x" % tuple(int(x) for x in m[:3]) if len(m) >= 3 else None

ACCENT_TOKENS = """() => { const r=getComputedStyle(document.documentElement); const v=(n)=>r.getPropertyValue(n).trim();
    const out={ theme: document.documentElement.dataset.theme || 'system', surface: v('--surface'), tokens: {} };
    for (let n=1;n<=8;n++) out.tokens[n] = { accent: v('--accent-'+n), soft: v('--accent-'+n+'-soft'), fg: v('--accent-'+n+'-fg'), chart: v('--chart-'+n) };
    return out }"""

ACTIVE_MARKER = """() => { const g=document.querySelector('nav.nav .nav-group.has-active'); const b=g&&g.querySelector('button');
    const a=document.querySelector('#crumbs a.crumb-module'); const rect=a&&a.getBoundingClientRect();
    return { group: g ? { label: b.innerText.trim(), prefix: g.dataset.prefix, accent: g.dataset.accent, color: getComputedStyle(b).color,
                          box_shadow: getComputedStyle(b).boxShadow, open: g.dataset.open } : null,
             crumb: a ? { href: a.getAttribute('href'), text: a.innerText.trim(), accent: a.dataset.accent, color: getComputedStyle(a).color,
                          width: Math.round(rect.width), visible: a.checkVisibility() } : null,
             crumbs_text: (document.getElementById('crumbs')||{}).innerText,
             // Struktur aksesibel (verifikasi P1-B 5 Sep 2026): host <nav aria-label>, remah terakhir aria-current="page".
             host_tag: (document.getElementById('crumbs')||{}).tagName, host_aria_label: (document.getElementById('crumbs')||{}).getAttribute?.('aria-label'),
             last_aria_current: (document.querySelector('#crumbs b')||{}).getAttribute?.('aria-current'),
             has_active_count: document.querySelectorAll('nav.nav .nav-group.has-active').length } }"""

# Remah roti vs kontrol header berikutnya (verifikasi P1-B 5 Sep 2026: di 390 px remah terakhir membungkus
# tiga baris dan menimpa tombol Cari). Yang dicatat: anak #crumbs yang tampak, tepi kanannya vs tepi kiri
# kontrol header tampak berikutnya, tinggi remah vs tinggi header, dan apakah pembaca layar masih mendapat
# remah aria-current (tersembunyi visual, bukan display: none).
CRUMB_FIT = """() => { const h=document.getElementById('crumbs'); const hdr=document.querySelector('.header'); const kids=[...hdr.children];
    const next=kids.slice(kids.indexOf(h)+1).find(e => e.getBoundingClientRect().width > 0 && !e.classList.contains('spacer'));
    const vis=[...h.children].filter(e => e.checkVisibility() && e.getBoundingClientRect().width > 1);
    const right=Math.max(...vis.map(e => e.getBoundingClientRect().right), h.getBoundingClientRect().left);
    const b=h.querySelector('b');
    return { hash: location.hash, visible: vis.map(e => (e.tagName + (e.className ? '.' + e.className : '')) + ':' + (e.innerText || '').trim().slice(0, 24)),
             text_visible: vis.map(e => (e.innerText || '').trim()).filter(Boolean).join(' › '), right_edge: Math.round(right), next_left: next ? Math.round(next.getBoundingClientRect().left) : null,
             overlap_next: !!next && right > next.getBoundingClientRect().left + 0.5,
             crumbs_h: Math.round(h.getBoundingClientRect().height), header_h: Math.round(hdr.getBoundingClientRect().height),
             module_lbl_ellipsized: (l => !!l && l.scrollWidth > l.clientWidth + 1)(h.querySelector('a.crumb-module .lbl')),
             last: b ? { text: b.innerText, aria_current: b.getAttribute('aria-current'), display: getComputedStyle(b).display, visible: b.checkVisibility() && b.getBoundingClientRect().width > 1 } : null } }"""

# CRUMB_FIT di atas hanya menyidik dua halaman Keuangan — dua-duanya berakar grup NAV. Verifikasi P1-B
# putaran 2 (6 Sep 2026) menemukan yang tidak: tujuh layar RESOURCES di luar NAV berakar penanda "ERP"
# milik groupLabelFor(), dirender <span>, dan aturan ≤ 760 px menyembunyikan span + chevron + remah
# layar sekaligus — remah roti ponselnya KOSONG (tinggi 0 px di header 56 px). Jadi remahnya dijalani:
# setiap rute NAV yang benar-benar tergambar di sidebar, plus setiap kunci RESOURCES yang tidak ada di
# NAV. Pindah rute lewat location.hash (router hash, tanpa muat ulang dokumen) karena setCrumbs jalan
# sinkron di penangan rute — 220 ms per rute, 131 rute selesai dalam 29 s (diukur 6 Sep 2026).
CRUMB_ROUTES = """async () => { const s = await import('/app/js/schema.js');
    const nav = [...new Set([...document.querySelectorAll('nav.nav .nav-group:not([data-kind]) .nav-items a')].map(a => a.getAttribute('href')))];
    const navSet = new Set(s.NAV.flatMap(g => g.items.map(i => i.route)));
    const outside = Object.keys(s.RESOURCES).filter(k => !navSet.has('r/' + k)).sort().map(k => '#/r/' + k);
    return { nav, outside } }"""

CRUMB_WALK = """async (routes) => { const hdr=document.querySelector('.header'); const h=document.getElementById('crumbs'); const out=[];
    for (const r of routes) {
      location.hash = r;
      await new Promise((res) => setTimeout(res, 220));
      const kids=[...hdr.children];
      const next=kids.slice(kids.indexOf(h)+1).find(e => e.getBoundingClientRect().width > 0 && !e.classList.contains('spacer'));
      const vis=[...h.children].filter(e => e.checkVisibility() && e.getBoundingClientRect().width > 1);
      const right=Math.max(...vis.map(e => e.getBoundingClientRect().right), h.getBoundingClientRect().left);
      const b=h.querySelector('b');
      out.push({ route: r, root: h.dataset.root || null, text: vis.map(e => (e.innerText || '').trim()).filter(Boolean).join(' › '),
                 crumbs_h: Math.round(h.getBoundingClientRect().height), header_h: Math.round(hdr.getBoundingClientRect().height),
                 overlap_next: !!next && right > next.getBoundingClientRect().left + 0.5,
                 aria_current: b ? b.getAttribute('aria-current') : null });
    }
    return out }"""

MODULE_HOME = """() => { const head=document.querySelector('.module-head'); const grid=document.querySelector('.module-grid'); const e=document.querySelector('#view .empty');
    const prefix = head && head.dataset.prefix;
    return { hash: location.hash, head: head ? { prefix, accent: head.dataset.accent, h1: head.querySelector('h1').innerText, desc: head.querySelector('.desc').innerText,
                 border_left: getComputedStyle(head).borderLeftColor, eyebrow_color: getComputedStyle(head.querySelector('.eyebrow')).color,
                 icon_bg: getComputedStyle(head.querySelector('.module-icon')).backgroundColor } : null,
             cards: [...document.querySelectorAll('.module-card')].map(a => a.getAttribute('href')),
             sections: [...document.querySelectorAll('.module-section')].map(s => s.innerText.trim()),
             hints: document.querySelectorAll('.module-card .hint').length,
             columns: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : null,
             // Kisi <ul> berlabel + li per kartu, pemisah <h2> melabeli <section> (verifikasi P1-B 5 Sep 2026).
             structure: grid ? { grid_tag: grid.tagName, grid_labelled: !!(grid.getAttribute('aria-label') || grid.getAttribute('aria-labelledby')),
                                 li_per_card: document.querySelectorAll('.module-grid > li > a.module-card').length === document.querySelectorAll('.module-card').length,
                                 section_tags: [...new Set([...document.querySelectorAll('.module-section')].map(s => s.tagName))],
                                 sections_label_their_grid: [...document.querySelectorAll('.module-section')].every(s => s.id && document.querySelector(`.module-grid[aria-labelledby="${s.id}"]`)) } : null,
             // Baris kroma aplikasi (data-chrome, hari ini hanya Beranda) BUKAN layar modul:
             // ia ada di menu tetapi tidak berkartu di beranda modul, dan penyaringnya penanda
             // yang dipasang aplikasi sendiri — bukan daftar href yang dikarang harness.
             sidebar: prefix ? [...document.querySelectorAll(`nav.nav .nav-group[data-prefix="${prefix}"] .nav-items a:not([data-chrome])`)].map(a => a.getAttribute('href')) : [],
             sidebar_chrome: prefix ? [...document.querySelectorAll(`nav.nav .nav-group[data-prefix="${prefix}"] .nav-items a[data-chrome]`)].map(a => a.getAttribute('href')) : [],
             sidebar_open: prefix ? (document.querySelector(`nav.nav .nav-group[data-prefix="${prefix}"]`)||{}).dataset?.open : null,
             empty: e ? { text: e.innerText.trim(), kind: (e.querySelector('.illus')||{}).dataset?.kind } : null,
             smallest_font_px: Math.min(...[...document.querySelectorAll('#view *')].map(el=>parseFloat(getComputedStyle(el).fontSize)).filter(Boolean)) } }"""

# Panah atas/bawah di beranda modul: dari SETIAP kartu, ArrowDown/ArrowUp harus mendarat pada kartu yang
# secara geometri tepat di bawah/atas pada kolom yang sama (baris terdekat; tidak ada → fokus diam) —
# verifikasi P1-B 5 Sep 2026: indeks ± jumlah kolom meleset 17/20 di #/m/fin begitu pemisah memutus kisi.
ARROWS = """() => { const cards=[...document.querySelectorAll('.module-card')]; const rect=(c)=>c.getBoundingClientRect();
    const neighbour=(i, dir)=>{ const r=rect(cards[i]); let best=-1, bestD=1e9; cards.forEach((c,j)=>{ if (j===i) return; const q=rect(c);
      const d = dir > 0 ? q.top - r.bottom : r.top - q.bottom; if (d < -1 || Math.abs(q.left - r.left) > 2) return; if (d < bestD) { bestD=d; best=j; } }); return best; };
    const out={ cards: cards.length, down_mismatches: [], up_mismatches: [] };
    for (const [key, dir, bucket] of [['ArrowDown', 1, 'down_mismatches'], ['ArrowUp', -1, 'up_mismatches']]) {
      for (let i=0;i<cards.length;i++){ cards[i].focus(); cards[i].dispatchEvent(new KeyboardEvent('keydown',{key, bubbles:true, cancelable:true}));
        const got=cards.indexOf(document.activeElement); const want=neighbour(i, dir); if (want === -1 ? got !== i : got !== want) out[bucket].push({ from: cards[i].querySelector('b').innerText, got: got>=0?cards[got].querySelector('b').innerText:null, want: want>=0?cards[want].querySelector('b').innerText:null }); } }
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    out.ok = out.down_mismatches.length === 0 && out.up_mismatches.length === 0; return out }"""

# Tinggi baris satu-baris (tanpa .cell-sub) per JENIS baris: teks polos, berlencana, bertombol aksi.
ROWS = """() => { const h=(e)=>+e.getBoundingClientRect().height.toFixed(2);
    // Baris tanpa .cell-sub; halaman uji dipilih yang namanya tidak membungkus (Kategori Item), jadi
    // `all` yang lebih dari satu nilai berarti ada baris yang membungkus — dicatat, bukan disembunyikan.
    const rows=[...document.querySelectorAll('table.data tbody tr')].filter(r => !r.querySelector('.cell-sub'));
    const kind=(r)=> r.querySelector('.btn') ? 'button' : r.querySelector('.badge') ? 'badge' : 'text';
    const by={}; for (const r of rows) { (by[kind(r)] ||= []).push(h(r)); }
    const uniq=(a)=>[...new Set(a)].sort((x,y)=>x-y);
    return { density: document.documentElement.dataset.density, row_h_token: getComputedStyle(document.documentElement).getPropertyValue('--row-h').trim(),
             pointer: matchMedia('(pointer: coarse)').matches ? 'coarse' : 'fine', total_rows: document.querySelectorAll('table.data tbody tr').length,
             single_line_rows: rows.length, by_kind: Object.fromEntries(Object.entries(by).map(([k,v]) => [k, uniq(v)])),
             min_by_kind: Object.fromEntries(Object.entries(by).map(([k,v]) => [k, Math.min(...v)])),
             all: uniq(rows.map(h)), th: h(document.querySelector('table.data th')), nav_link: h(document.querySelector('.nav-items a')),
             // Baris kaki (tfoot "Total …") ikut disidik: verifikasi P1-B 5 Sep 2026 menemukan
             // baris total menciut 41 → 39 px pada profil normal karena sidik jari ini dulu
             // hanya membaca tbody.
             tfoot: uniq([...document.querySelectorAll('table.data tfoot tr')].map(h)),
             btn_sm: (b => b ? h(b) : null)(document.querySelector('table.data .btn.sm')),
             stored: Object.keys(localStorage).filter(k => k.startsWith('nusantara_erp_density')) } }"""

EMPTY_KINDS = """async () => { const ui = await import('/app/js/ui.js'); const host=document.createElement('div'); host.id='s21e';
    host.style.cssText='position:absolute;top:0;left:0;right:0;z-index:999;background:var(--surface);color:var(--text);display:grid;grid-template-columns:repeat(5,1fr)';
    for (const kind of ['inbox','search','filter','error','done']) host.appendChild(ui.emptyState('Contoh ' + kind, { kind, title: kind }));
    host.appendChild(ui.emptyState('Ringkas', { kind: 'done', compact: true, title: null }));
    document.body.appendChild(host); return true }"""

EMPTY_MEASURE = """() => { const r=getComputedStyle(document.documentElement); const v=(n)=>r.getPropertyValue(n).trim();
    const tok={ '--border-strong': v('--border-strong'), '--primary': v('--primary'), '--danger': v('--danger'), '--success': v('--success'), '--surface-3': v('--surface-3'), '--primary-soft': v('--primary-soft'), '--danger-soft': v('--danger-soft'), '--success-soft': v('--success-soft') };
    const out={ tokens: tok, kinds: {} };
    for (const svg of document.querySelectorAll('#s21e .illus')) { const k=svg.dataset.kind; const cs=(sel)=>{ const n=svg.querySelector(sel); return n ? getComputedStyle(n) : {}; };
      const box=svg.getBoundingClientRect(); const compact = svg.closest('.empty').classList.contains('compact');
      out.kinds[k + (compact ? '_compact' : '')] = { width: Math.round(box.width), height: Math.round(box.height), bytes: svg.outerHTML.length,
        ln_stroke: cs('.ln').stroke, ac_stroke: cs('.ac').stroke, fl_fill: cs('.fl').fill, fa_fill: cs('.fa').fill, opacity: getComputedStyle(svg).opacity,
        hex_literals: (svg.outerHTML.match(/#[0-9a-fA-F]{3,6}\\b/g) || []).length, has_title: !!svg.closest('.empty').querySelector('h3') } }
    return out }"""

LIST_EMPTY = """() => { const e=document.querySelector('#view .empty'); if (!e) return null; const ln=e.querySelector('.illus .ln');
    // Ikon di tombol keadaan kosong (verifikasi P1-B 5 Sep 2026: aturan lama .empty svg memudarkannya 0,3 dan
    // mengangkatnya 5 px dari tengah tombol) — keburaman 1, tanpa margin, tengah ikon ≤ 1 px dari tengah tombol.
    const btn=e.querySelector('button'); const svg=btn && btn.querySelector('svg'); const mid=(n)=>{ const r=n.getBoundingClientRect(); return r.top + r.height/2; };
    return { title: (e.querySelector('h3')||{}).innerText, text: (e.querySelector('p')||{}).innerText, kind: (e.querySelector('.illus')||{}).dataset?.kind,
             ln_stroke: ln ? getComputedStyle(ln).stroke : null, buttons: [...e.querySelectorAll('button')].map(b => b.innerText.trim()),
             button_icon: svg ? { opacity: getComputedStyle(svg).opacity, margin_bottom: getComputedStyle(svg).marginBottom, dy: +(mid(svg) - mid(btn)).toFixed(1) } : null } }"""

def set_theme(pg, theme):
    pg.evaluate("(t) => { if (t) document.documentElement.dataset.theme = t; else delete document.documentElement.dataset.theme; }", theme)
    pg.wait_for_timeout(150)

def accent_matrix(tokens_out):
    t = tokens_out["tokens"]; surface = tokens_out["surface"]
    pairs = {f"{i}-{j}": de2000(t[str(i)]["accent"], t[str(j)]["accent"]) for i in range(1, 9) for j in range(i + 1, 9)}
    contrast = {n: {"on_surface": wcag(t[n]["accent"], surface), "fg_on_accent": wcag(t[n]["fg"], t[n]["accent"]), "on_soft": wcag(t[n]["accent"], t[n]["soft"])} for n in t}
    # Sudut hue Lab aksen vs --chart-n yang hidup — klaim app.css "±12°" diukur, bukan dipercaya
    # (verifikasi P1-B 5 Sep 2026: slot 8 gelap 14,3° sebelum digeser).
    hue = lambda h: (lambda L, a, b: __import__("math").degrees(__import__("math").atan2(b, a)) % 360)(*_lab(h))
    hue_delta = {n: round(min(abs(hue(t[n]["accent"]) - hue(t[n]["chart"])) % 360, 360 - abs(hue(t[n]["accent"]) - hue(t[n]["chart"])) % 360), 1) for n in t}
    return {"theme": tokens_out["theme"], "surface": surface, "tokens": t, "pairs": pairs, "min_pair_de": min(pairs.values()),
            "min_pair": min(pairs, key=pairs.get), "contrast": contrast,
            "hue_delta": hue_delta, "max_hue_delta": max(hue_delta.values()), "all_hue_within_12": all(v <= 12 for v in hue_delta.values()),
            "min_on_surface": min(c["on_surface"] for c in contrast.values()), "min_fg_on_accent": min(c["fg_on_accent"] for c in contrast.values()),
            "min_on_soft": min(c["on_soft"] for c in contrast.values()),
            "all_pairs_ge_20": all(v >= 20 for v in pairs.values()),
            "all_on_surface_ge_3": all(c["on_surface"] >= 3 for c in contrast.values()),
            "all_fg_ge_4_5": all(c["fg_on_accent"] >= 4.5 for c in contrast.values()),
            "all_on_soft_ge_4_5": all(c["on_soft"] >= 4.5 for c in contrast.values())}

# Dialog Kepadatan: tinggi baris radio (≥ 40 px di layar sentuh — verifikasi P1-B 5 Sep 2026: 30 px) dan
# petunjuknya (di layar sentuh menyebut teks · bertombol, karena baris bertombol 43/55/55 di sana).
DENSITY_DIALOG = """() => { const f=document.querySelector('.density-pick'); if (!f) return null;
    return { rows: [...f.querySelectorAll('label.check-row')].map(l => ({ label: l.querySelector('span').innerText, hint: l.querySelector('.muted').innerText,
             h: +l.getBoundingClientRect().height.toFixed(1), radio: +l.querySelector('input').getBoundingClientRect().height.toFixed(1) })),
             note: (n => n ? n.innerText : null)(f.querySelector('.density-note')), pointer: matchMedia('(pointer: coarse)').matches ? 'coarse' : 'fine' } }"""

def set_density(pg, value):
    click(pg, ".userchip"); pg.wait_for_timeout(500)
    dialog = pg.evaluate(DENSITY_DIALOG)
    click(pg, f".density-pick input[value={value}]"); pg.wait_for_timeout(250)
    pg.keyboard.press("Escape"); pg.wait_for_timeout(300)
    return dialog

def module_vs_sidebar(pg, prefixes):
    out = {}
    for prefix in prefixes:
        pg.goto(BASE + f"#/m/{prefix}"); pg.wait_for_timeout(900)
        m = pg.evaluate(MODULE_HOME)
        arrows = pg.evaluate(ARROWS) if m["cards"] else None
        out[prefix] = {"cards": len(m["cards"]), "sidebar": len(m["sidebar"]), "match": m["cards"] == m["sidebar"], "head": bool(m["head"]),
                       # Baris menu yang bukan layar (data-chrome) dicatat apa adanya: kalau
                       # daftarnya bertambah, yang bertambah harus terbaca di bukti dan bukan
                       # menghilang diam-diam dari pembandingan.
                       "sidebar_chrome": m["sidebar_chrome"],
                       "arrows": arrows and {"ok": arrows["ok"], "down_mismatches": arrows["down_mismatches"][:4], "up_mismatches": arrows["up_mismatches"][:4]},
                       "sections": m["sections"], "hints": m["hints"], "empty": m["empty"], "columns": m["columns"], "smallest_font_px": m["smallest_font_px"],
                       "only_in_cards": sorted(set(m["cards"]) - set(m["sidebar"])), "only_in_sidebar": sorted(set(m["sidebar"]) - set(m["cards"]))}
    return out

def module_accents(pg, tag):
    errors = []; console_errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
    pg.on("console", lambda m: console_errors.append(m.text[:160]) if m.type == "error" else None)
    # Status diputuskan di DB sebelum masuk (bukan klik Lewati yang berlomba dengan fetchGuide): pada DB
    # yang belum memutuskan, panel terbuka sesudah pemeriksaan sekali dan memindah halaman ke #/dashboard.
    # Sejak putaran 2 itu dikerjakan login() untuk SEMUA skenario; yang dicatat di bawah hasilnya.
    login(pg, "admin@nusantara.test")
    out = {"viewport": pg.viewport_size, "onboarding_status": {"admin": onboarding_status("admin@nusantara.test")},
           "dock_open": pg.locator(".onboarding-dock").count()}
    prefixes = pg.evaluate("() => [...document.querySelectorAll('nav.nav .nav-group[data-prefix]')].map(g => g.dataset.prefix)")
    out["sidebar_prefixes"] = prefixes

    for theme in ("light", "dark"):
        set_theme(pg, theme)
        res = {"accent": accent_matrix(pg.evaluate(ACCENT_TOKENS))}
        tok = res["accent"]["tokens"]
        # Penanda grup aktif + remah modul di layar Keuangan (slot 2), lalu klik remahnya.
        pg.goto(BASE + "#/r/finance/ar-invoices"); pg.wait_for_timeout(1500)
        set_theme(pg, theme)
        marker = pg.evaluate(ACTIVE_MARKER)
        slot = marker["group"] and marker["group"]["accent"]
        marker["marker_color_hex"] = rgb_to_hex(marker["group"]["color"]) if marker["group"] else None
        marker["crumb_color_hex"] = rgb_to_hex(marker["crumb"]["color"]) if marker["crumb"] else None
        marker["slot_token"] = tok[slot]["accent"] if slot else None
        marker["marker_matches_token"] = bool(slot) and marker["marker_color_hex"] == tok[slot]["accent"]
        marker["shadow_matches_token"] = bool(slot) and rgb_to_hex(marker["group"]["box_shadow"]) == tok[slot]["accent"]
        marker["crumb_matches_token"] = bool(slot) and marker["crumb_color_hex"] == tok[slot]["accent"]
        marker["crumb_href_ok"] = bool(marker["crumb"]) and marker["crumb"]["href"] == "#/m/fin"
        marker["a11y_ok"] = marker["host_tag"] == "NAV" and bool(marker["host_aria_label"]) and marker["last_aria_current"] == "page"
        res["active_marker"] = marker
        fit = {"list": pg.evaluate(CRUMB_FIT)}
        pg.goto(BASE + "#/d/finance/ar-invoices/1"); pg.wait_for_timeout(1500); fit["detail"] = pg.evaluate(CRUMB_FIT)
        fit["ok"] = all(not f["overlap_next"] and f["crumbs_h"] <= f["header_h"] and f["last"] and f["last"]["aria_current"] == "page" and f["last"]["display"] != "none" for f in (fit["list"], fit["detail"]))
        res["crumb_fit"] = fit
        pg.goto(BASE + "#/r/finance/ar-invoices"); pg.wait_for_timeout(1200); set_theme(pg, theme)
        pg.screenshot(path=f"{OUT}/s21-crumb-{theme}{tag}.png", clip={"x": 0, "y": 0, "width": pg.viewport_size["width"], "height": 120})
        # Klik remah modul — di ponsel remah bisa terjepit (lebar dicatat di atas), jadi klik lewat DOM.
        pg.evaluate("() => document.querySelector('#crumbs a.crumb-module').click()"); pg.wait_for_timeout(1000)
        home = pg.evaluate(MODULE_HOME)
        home["navigated"] = home["hash"] == "#/m/fin"
        home["head_border_hex"] = rgb_to_hex(home["head"]["border_left"]) if home["head"] else None
        home["head_matches_token"] = bool(home["head"]) and home["head_border_hex"] == tok[home["head"]["accent"]]["accent"]
        home["icon_bg_matches_soft"] = bool(home["head"]) and rgb_to_hex(home["head"]["icon_bg"]) == tok[home["head"]["accent"]]["soft"]
        home["cards_equal_sidebar"] = home["cards"] == home["sidebar"]
        st = home["structure"] or {}
        home["structure_ok"] = st.get("grid_tag") == "UL" and st.get("grid_labelled") and st.get("li_per_card") and st.get("section_tags") in (["H2"], []) and st.get("sections_label_their_grid")
        home["marker_after"] = pg.evaluate(ACTIVE_MARKER)["group"]
        res["module_home_fin"] = home
        pg.screenshot(path=f"{OUT}/s21-module-home-{theme}{tag}.png", full_page=False)
        # Media cetak pada tema ini: token aksen harus jatuh ke nilai TERANG (blok cetak app.css) —
        # verifikasi P1-B 5 Sep 2026: tema gelap mencetak --accent-7 #fbcd1a di kertas putih (1,52:1).
        pg.emulate_media(media="print"); pg.wait_for_timeout(150)
        pr = {"tokens": pg.evaluate(ACCENT_TOKENS)["tokens"], "head": pg.evaluate(MODULE_HOME)["head"], "body_bg": pg.evaluate("() => getComputedStyle(document.body).backgroundColor")}
        pg.emulate_media(media="null"); pg.wait_for_timeout(150)
        pr["min_on_paper"] = min(wcag(pr["tokens"][n]["accent"], "#ffffff") for n in pr["tokens"])
        pr["all_on_paper_ge_3"] = pr["min_on_paper"] >= 3
        pr["head_border_hex"] = rgb_to_hex(pr["head"]["border_left"]) if pr["head"] else None
        pr["head_uses_print_token"] = bool(pr["head"]) and pr["head_border_hex"] == pr["tokens"][pr["head"]["accent"]]["accent"]
        res["print_accents"] = pr
        # Lima jenis ilustrasi keadaan kosong, stroke/fill terkomputasi vs token.
        pg.evaluate(EMPTY_KINDS); pg.wait_for_timeout(200)
        em = pg.evaluate(EMPTY_MEASURE)
        t = em["tokens"]
        em["checks"] = {k: {"ln_is_border_strong": rgb_to_hex(v["ln_stroke"]) == t["--border-strong"],
                            "ac_is_token": rgb_to_hex(v["ac_stroke"]) == t[{"error": "--danger", "done": "--success"}.get(k.replace("_compact", ""), "--primary")],
                            "fl_is_surface3": rgb_to_hex(v["fl_fill"]) == t["--surface-3"],
                            # inbox tidak punya bidang aksen (.fa); jenis lain harus memakai token -soft yang benar.
                            "fa_is_soft_token": v["fa_fill"] is None or rgb_to_hex(v["fa_fill"]) == t[{"error": "--danger-soft", "done": "--success-soft"}.get(k.replace("_compact", ""), "--primary-soft")],
                            "bytes_le_1536": v["bytes"] <= 1536, "no_hex_literals": v["hex_literals"] == 0} for k, v in em["kinds"].items()}
        em["all_ok"] = all(all(c.values()) for c in em["checks"].values())
        res["empty_kinds"] = em
        pg.locator("#s21e").screenshot(path=f"{OUT}/s21-empty-kinds-{theme}{tag}.png")
        pg.evaluate("() => document.getElementById('s21e').remove()")
        out[theme] = res
    set_theme(pg, None)

    # Beranda modul vs sidebar, admin: setiap grup.
    out["admin_modules"] = module_vs_sidebar(pg, prefixes)
    out["admin_all_match"] = all(m["match"] for m in out["admin_modules"].values())
    out["admin_arrows_ok"] = all(m["arrows"] is None or m["arrows"]["ok"] for m in out["admin_modules"].values())
    out["unknown_prefix"] = (pg.goto(BASE + "#/m/tidak-ada") or pg.wait_for_timeout(600) or pg.evaluate("() => document.querySelector('#view').innerText.trim().slice(0, 80)"))

    # Remah roti dijalani di SETIAP rute: tidak boleh ada satu pun yang remahnya kosong, dan yang tampil
    # harus tetap muat di header 56 px tanpa menimpa tombol Cari (di ponsel; di desktop syarat yang sama
    # berlaku dan lebih longgar). Sekali per skenario, bukan per tema: yang diuji bentuk, bukan warna.
    routes = pg.evaluate(CRUMB_ROUTES)
    # Jawaban API dipalsukan kosong selama jalan-jalan ini. 131 rute × permintaan daftarnya menembus
    # batas 120 permintaan/menit/pengguna (AppServiceProvider): diukur 6 Sep 2026 — sesudah walk tanpa
    # palsu, GET inventory/item-categories membalas 429 dan langkah kepadatan di bawah menunggu baris
    # tabel sampai 15 s habis. Remah roti digambar penangan rute SEBELUM data tiba, jadi datanya memang
    # tidak dibutuhkan di sini; iam/auth/me dilewatkan apa adanya supaya izin sesi tidak ikut dikosongkan.
    def empty_api(handler):
        url = handler.request.url
        if "iam/auth/me" in url or "iam/me/" in url:
            handler.continue_()
        else:
            handler.fulfill(status=200, content_type="application/json",
                            body='{"data":[],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":0}}')
    stub_from = len(console_errors)
    pg.route("**/api/**", empty_api)
    pg.goto(BASE + "#/dashboard"); pg.wait_for_timeout(800)
    try:
        walk = pg.evaluate(CRUMB_WALK, routes["nav"] + routes["outside"])
    finally:
        pg.unroute("**/api/**", empty_api)
    # Dua layar khusus (rekap alat, retensi) membaca objek berbentuk, bukan daftar, jadi badan palsu di
    # atas — bukan pemakaian sungguhan — yang membuatnya melempar. Dicatat terpisah supaya console_errors
    # skenario tetap berarti "galat saat memakai aplikasi dengan data sungguhan".
    stub_errors = console_errors[stub_from:]
    del console_errors[stub_from:]
    empty = [w for w in walk if not w["text"]]
    overlapping = [w for w in walk if w["overlap_next"]]
    taller = [w for w in walk if w["crumbs_h"] > w["header_h"]]
    no_aria = [w for w in walk if w["aria_current"] != "page"]
    out["crumb_walk"] = {"routes": len(walk), "nav": len(routes["nav"]), "outside": routes["outside"],
                         "by_root": {r: sum(1 for w in walk if w["root"] == r) for r in ("module", "screen", None)},
                         # Rantai berakar 'screen' DINAMAI, bukan hanya dihitung: sejak layar di luar NAV
                         # berakar pada modulnya (eaef2e7) yang tersisa hanyalah rantai satu remah seperti
                         # #/dashboard — kalau daftarnya bertambah, yang bertambah harus terbaca di bukti.
                         "screen_routes": [w["route"] for w in walk if w["root"] == "screen"],
                         "empty": [w["route"] for w in empty], "overlapping": [w["route"] for w in overlapping],
                         "taller_than_header": [w["route"] for w in taller], "without_aria_current": [w["route"] for w in no_aria],
                         "outside_texts": {w["route"]: w["text"] for w in walk if w["route"] in routes["outside"]},
                         "console_errors_from_stub": {"count": len(stub_errors), "first": stub_errors[:3]},
                         "never_empty": not empty, "all_fit": not overlapping and not taller, "all_aria_current": not no_aria}
    out["crumb_walk"]["ok"] = out["crumb_walk"]["never_empty"] and out["crumb_walk"]["all_fit"] and out["crumb_walk"]["all_aria_current"]
    # Gambar untuk salah satu dari tujuh: header yang di ponsel dulu kosong sama sekali.
    pg.goto(BASE + "#/r/projects/defects"); pg.wait_for_timeout(1200)
    pg.screenshot(path=f"{OUT}/s21-crumb-outside-nav{tag}.png", clip={"x": 0, "y": 0, "width": pg.viewport_size["width"], "height": 120})

    # Kepadatan: tiga profil pada satu daftar bertombol aksi (Kategori Item: nama pendek, tidak
    # membungkus), lalu muat ulang. Harapan per penunjuk: pointer fine → 32/48 untuk SEMUA baris
    # satu-baris; pointer coarse (ponsel) → sasaran jempol 36 px menang atas tombol 24 px, jadi baris
    # bertombol 43 (rapat) / 55 (lega) — app.css § kepadatan. Bagan Akun ikut diukur per jenis baris.
    def rows_on(route):
        pg.goto(BASE + route); pg.wait_for_selector("table.data tbody tr", timeout=15000); pg.wait_for_timeout(600)
        return pg.evaluate(ROWS)
    probe = "#/r/inventory/item-categories"
    # Daftar PO ikut: satu-satunya dari ketiga halaman yang punya tfoot ("Total halaman ini").
    po_list = "#/r/procurement/purchase-orders"
    dens = {"baseline": rows_on(probe), "baseline_po": rows_on(po_list), "baseline_accounts": rows_on("#/r/finance/accounts")}
    coarse = dens["baseline"]["pointer"] == "coarse"
    dens["expected"] = {"compact": 43 if coarse else 32, "comfortable": 55 if coarse else 48, "normal": 47 if not coarse else 55}
    for value in ("compact", "comfortable", "normal"):
        dens["dialog_before_" + value] = set_density(pg, value)
        dens[value] = rows_on(probe)
        dens[value + "_po"] = rows_on(po_list)
        # Bagan Akun terakhir: muat ulang di bawah mendarat di halaman ini (baris PO semuanya dua-baris, `all`-nya kosong).
        dens[value + "_accounts"] = rows_on("#/r/finance/accounts")
        if value == "compact":
            pg.screenshot(path=f"{OUT}/s21-density-compact{tag}.png")
    set_density(pg, "compact")
    pg.reload(); pg.wait_for_selector("table.data tbody tr", timeout=20000); pg.wait_for_timeout(600)
    dens["after_reload"] = pg.evaluate(ROWS)
    # Toleransi 0,5 px: baris terakhir tabel tanpa border-bottom (tr:last-child td) — di ponsel
    # lantai tidak mengikat (tombol 36 px), jadi baris itu setengah piksel lebih pendek.
    near = lambda values, want: bool(values) and all(abs(v - want) <= 0.5 for v in values)
    dens["compact_ok"] = near(dens["compact"]["all"], dens["expected"]["compact"])
    dens["comfortable_ok"] = near(dens["comfortable"]["all"], dens["expected"]["comfortable"])
    dens["normal_equals_baseline"] = (dens["normal"]["all"] == dens["baseline"]["all"] and dens["baseline"]["density"] == "normal"
                                      and dens["normal_accounts"]["by_kind"] == dens["baseline_accounts"]["by_kind"]
                                      and dens["normal_po"]["by_kind"] == dens["baseline_po"]["by_kind"]
                                      and dens["normal_po"]["tfoot"] == dens["baseline_po"]["tfoot"])
    # Kaki tabel PO per profil (normal harus 41 = tinggi sebelum token, --foot-py 10 px; rapat 4 px; lega 10 px).
    dens["tfoot_po"] = {k: dens[k + "_po"]["tfoot"] for k in ("baseline", "compact", "comfortable", "normal")}
    # Hanya di viewport lebar: di 390 px label "Total halaman ini" membungkus dua baris (60,5 px) — di sana yang
    # berlaku normal == baseline di atas.
    dens["tfoot_normal_41"] = (bool(dens["normal_po"]["tfoot"]) and all(abs(v - 41) <= 0.5 for v in dens["normal_po"]["tfoot"])) if pg.viewport_size["width"] >= 900 else None
    # Bagan Akun: baris terpendek per jenis (teks/lencana/tombol) di tiap profil — nama panjang membungkus, jadi min-nya yang satu-baris.
    dens["accounts_min_by_kind"] = {k: dens[k + "_accounts"]["min_by_kind"] for k in ("baseline", "compact", "comfortable", "normal")}
    # Muat ulang mendarat di Bagan Akun (nama panjang membungkus): yang dibandingkan baris terpendeknya.
    dens["persisted"] = dens["after_reload"]["density"] == "compact" and bool(dens["after_reload"]["all"]) and min(dens["after_reload"]["all"]) == dens["expected"]["compact"]
    # Layar sentuh (verifikasi P1-B 5 Sep 2026): tautan drawer ≥ 36 px di semua profil (rapat dulu 27,5), baris radio ≥ 40 px,
    # petunjuk menyebut angka bertombol. Di penunjuk halus hanya dicatat (27,5/31,5/35,5).
    dens["nav_link_by_density"] = {k: dens[k]["nav_link"] for k in ("baseline", "compact", "comfortable", "normal")}
    dens["nav_link_ge_36_on_coarse"] = all(v >= 36 for v in dens["nav_link_by_density"].values()) if coarse else None
    dialog = dens["dialog_before_normal"] or {"rows": []}
    dens["dialog_rows_ge_40_on_coarse"] = (bool(dialog["rows"]) and all(r["h"] >= 40 for r in dialog["rows"])) if coarse else None
    dens["dialog_hints_name_button_rows_on_coarse"] = (bool(dialog["rows"]) and all("bertombol" in r["hint"] for r in dialog["rows"]) and bool(dialog.get("note"))) if coarse else None
    set_density(pg, "normal")
    out["density"] = dens

    # Daftar tersaring habis: pencarian saja → search; filter → filter; "Hapus filter" mengembalikan baris.
    pg.goto(BASE + "#/r/procurement/purchase-orders?q=zzzzqq"); pg.wait_for_timeout(1800)
    le = {"search": pg.evaluate(LIST_EMPTY)}
    pg.goto(BASE + "#/r/procurement/purchase-orders?q=zzzzqq&status=approved"); pg.wait_for_timeout(1800)
    le["filter"] = pg.evaluate(LIST_EMPTY)
    click(pg, "#view .empty button:has-text('Hapus filter')"); pg.wait_for_timeout(1500)
    le["after_clear"] = pg.evaluate("() => ({ rows: document.querySelectorAll('table.data tbody tr').length, hash: location.hash, empty: !!document.querySelector('#view .empty') })")
    # Kalimat menyebut penyaringnya (verifikasi P1-B 5 Sep 2026): pencarian saja → "cocok dengan" + Hapus pencarian;
    # filter → "lolos filter" + Hapus filter; judul tidak mengulang kalimat.
    le["copy_ok"] = (bool(le["search"]) and "cocok dengan" in le["search"]["text"] and le["search"]["buttons"] == ["Hapus pencarian"] and le["search"]["title"] != le["search"]["text"]
                     and bool(le["filter"]) and "lolos filter" in le["filter"]["text"] and le["filter"]["buttons"] == ["Hapus filter"] and le["filter"]["title"] != le["filter"]["text"])
    le["button_icon_ok"] = all(bool(le[k]) and bool(le[k]["button_icon"]) and le[k]["button_icon"]["opacity"] == "1" and le[k]["button_icon"]["margin_bottom"] == "0px" and abs(le[k]["button_icon"]["dy"]) <= 1 for k in ("search", "filter"))
    pg.goto(BASE + "#/r/procurement/purchase-orders?q=zzzzqq"); pg.wait_for_timeout(1500)
    pg.screenshot(path=f"{OUT}/s21-empty-filter{tag}.png")
    out["list_empty"] = le

    # Peran sempit: warehouse@ — beranda tiap grupnya = sidebarnya; #/m/fin = keadaan kosong.
    pg.context.clear_cookies(); pg.goto(BASE); pg.evaluate("() => localStorage.clear()")
    login(pg, "warehouse@nusantara.test")
    wh_prefixes = pg.evaluate("() => [...document.querySelectorAll('nav.nav .nav-group[data-prefix]')].map(g => g.dataset.prefix)")
    out["warehouse"] = {"prefixes": wh_prefixes, "onboarding_status": onboarding_status("warehouse@nusantara.test"),
                        "dock_open": pg.locator(".onboarding-dock").count(), "modules": module_vs_sidebar(pg, wh_prefixes)}
    out["warehouse"]["all_match"] = all(m["match"] for m in out["warehouse"]["modules"].values())
    pg.goto(BASE + "#/m/fin"); pg.wait_for_timeout(900)
    out["warehouse"]["fin_home"] = pg.evaluate(MODULE_HOME)
    out["warehouse"]["fin_is_empty_state"] = bool(out["warehouse"]["fin_home"]["empty"]) and not out["warehouse"]["fin_home"]["cards"]
    pg.screenshot(path=f"{OUT}/s21-module-empty-warehouse{tag}.png")

    out["pageerrors"] = errors
    out["console_errors"] = {"count": len(console_errors), "first": console_errors[:3]}
    return out


# ------------------------------------------------- S22 kebenaran hitungan launcher

# Filter per modul yang dipakai untuk MEMERIKSA setiap ubin — ditulis di sini,
# bukan dibaca dari server: pemeriksa yang memanggil kueri yang sama dengan yang
# diperiksanya tidak memeriksa apa pun. Tiap entri menyebut endpoint DAFTAR milik
# modulnya (layar yang dibuka orang dari ubin itu), parameter penyaringnya, dan
# cara membaca angkanya:
#   meta_total       jumlah baris yang dilaporkan meta.total (dijumlahkan bila
#                    filternya beberapa status — endpoint daftar hanya menerima
#                    satu status per permintaan)
#   unread_field     data.unread pada core/notifications/unread-count
#   rows             panjang daftar (endpoint tanpa paginasi)
#   decision_null    baris SDS yang belum diputus (tidak ada filter "belum
#                    diputus"; yang ada filter decision=<nilai>)
#   outstanding_gt0  invoice approved yang masih bersisa (resource-nya sudah
#                    membawa `outstanding`, jadi tidak ada aritmetika di sini)
#
# `perm` adalah izin yang HARUS dipegang agar ubinnya berangka — ditulis lagi di
# sini, sengaja, sebagai pernyataan independen dari registri. Ia bukan sama
# dengan "endpoint daftarnya menjawab": sebagian rute index modul memang tidak
# bergerbang izin di server (hanya tulisnya yang bergerbang), sementara LAYAR-nya
# di SPA bergerbang `<modul>.view`. Selisih itu direkam di bawah sebagai
# endpoint_open_without_permission — temuan yang dilaporkan, bukan diperbaiki
# paket ini (mengubah gerbang rute adalah perubahan izin dengan paketnya sendiri).
LAUNCHER_CHECKS = {
    "ringkasan": {"perm": None, "path": "core/notifications/unread-count", "params": [{}], "read": "unread_field"},
    "crm":       {"perm": "crm.view", "path": "crm/leads", "params": [{"status": s} for s in ("new", "contacted", "qualified", "proposal")], "read": "meta_total"},
    "est":       {"perm": "est.view", "path": "estimation/boqs", "params": [{"status": "submitted"}], "read": "meta_total"},
    "eng":       {"perm": "eng.view", "path": "engineering/drawing-submittals", "params": [{"current_only": 1, "per_page": 500}], "read": "decision_null"},
    "prj":       {"perm": "prj.view", "path": "projects", "params": [{"status": "active"}, {"status": "finishing"}], "read": "meta_total"},
    "qc":        {"perm": "qc.view", "path": "quality/ncr", "params": [{"status": "open"}, {"status": "under_correction"}], "read": "meta_total"},
    "prc":       {"perm": "prc.view", "path": "procurement/purchase-orders", "params": [{"status": "approved"}], "read": "meta_total"},
    "inv":       {"perm": "inv.view", "path": "inventory/stock/low-stock", "params": [{}], "read": "rows"},
    "scm":       {"perm": "scm.view", "path": "subcontract/progress-claims", "params": [{"status": "submitted"}], "read": "meta_total"},
    "fin":       {"perm": "fin.view", "path": "finance/ar-invoices", "params": [{"status": "approved", "per_page": 500}], "read": "outstanding_gt0"},
    "hr":        {"perm": "hr.view", "path": "hr/leave-requests", "params": [{"status": "submitted"}], "read": "meta_total"},
    "svc":       {"perm": "svc.view", "path": "servicedesk/tickets", "params": [{"status": s} for s in ("open", "assigned", "in_progress", "pending_customer")], "read": "meta_total"},
    "ast":       {"perm": "ast.view", "path": "assets/assets", "params": [{"status": "maintenance"}], "read": "meta_total"},
    "iam":       {"perm": "core.update", "path": "core/queue/failed", "params": [{}], "read": "meta_total"},
}

# Permintaan dijalankan DI DALAM halaman dengan token sesi peramban itu sendiri —
# bukan lewat token_for(), yang berarti satu login tambahan per pemeriksaan
# (iam/auth/login dibatasi 10/menit/IP) dan, lebih penting, izin yang belum tentu
# sama dengan sesi yang sedang menggambar ubinnya.
API_IN_PAGE = """async ([url, params]) => {
    const u = new URL(url);
    Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, v));
    const r = await fetch(u, { headers: { Accept: 'application/json', 'X-Api-Token': localStorage.getItem('nusantara_erp_token') } });
    let body = null; try { body = await r.json(); } catch (e) { body = null; }
    return { status: r.status, data: (body && body.data !== undefined) ? body.data : null, meta: (body || {}).meta || null };
}"""

LAUNCHER_TILES = """() => {
    const cell = (t, sel) => (t.querySelector(sel) || {}).innerText || '';
    const groups = [...document.querySelectorAll('nav.nav .nav-group[data-prefix]')];
    return {
        hash: location.hash,
        h1: (document.querySelector('.page-head h1') || {}).innerText || null,
        tiles: [...document.querySelectorAll('.home-tile')].map(t => {
            const r = t.getBoundingClientRect();
            return { prefix: t.dataset.prefix, accent: t.dataset.accent,
                     label: cell(t, '.home-tile-label').trim(), kpi: cell(t, '.home-kpi-value').trim(),
                     unit: cell(t, '.home-kpi-unit').trim(), caption: cell(t, '.home-kpi-label').trim(),
                     screens: cell(t, '.home-tile-screens').trim(),
                     h: +r.height.toFixed(1), w: +r.width.toFixed(1),
                     border: getComputedStyle(t).borderLeftColor };
        }),
        nav_prefixes: groups.map(g => g.dataset.prefix),
        // ':not([data-chrome])' — baris Beranda ada di menu tetapi bukan layar modul, jadi
        // ia tidak dihitung ubin maupun kisi kartu; harness memakai penanda yang sama.
        nav_screens: Object.fromEntries(groups.map(g => [g.dataset.prefix, g.querySelectorAll('.nav-items a:not([data-chrome])').length])),
        nav_chrome: Object.fromEntries(groups.map(g => [g.dataset.prefix, [...g.querySelectorAll('.nav-items a[data-chrome]')].map(a => a.getAttribute('href'))]).filter(([, v]) => v.length)),
        sections: [...document.querySelectorAll('.home-section-title')].map(h => h.innerText.trim()),
        chips: [...document.querySelectorAll('.home-chip')].map(a => ({ href: a.getAttribute('href'), h: +a.getBoundingClientRect().height.toFixed(1) })),
        search_h: (s => s ? +s.getBoundingClientRect().height.toFixed(1) : null)(document.querySelector('.home-search')),
    };
}"""


def api_in_page(pg, path, params):
    return pg.evaluate(API_IN_PAGE, [API + path, {k: str(v) for k, v in params.items()}])


def expected_count(pg, prefix):
    """Angka yang DIJANJIKAN ubin, dihitung ulang dari endpoint daftar modulnya.

    Mengembalikan (angka, panggilan, terpotong). None = endpoint menolak
    (403/401) — dan ubinnya karena itu WAJIB '—': server tidak mengirim entri
    untuk modul yang izin hitungannya tidak dipegang.

    `terpotong` = pembacaan sisi-klien (decision_null / outstanding_gt0 / rows)
    yang halamannya tidak memuat seluruh baris: per_page=500 di atas 500 baris
    akan diam-diam membandingkan 500 dari 700 dan melaporkan cocok. Yang benar
    adalah JATUH, bukan menghitung sebagian (verifikasi P1-C, 6 Sep 2026)."""
    spec = LAUNCHER_CHECKS[prefix]
    total, calls, truncated = 0, [], False
    for params in spec["params"]:
        res = api_in_page(pg, spec["path"], params)
        rows = res["data"] or []
        meta_total = (res["meta"] or {}).get("total")
        calls.append({"path": spec["path"], "params": params, "status": res["status"],
                      "rows": len(rows) if isinstance(rows, list) else None, "meta_total": meta_total})
        if res["status"] != 200:
            return None, calls, False
        if spec["read"] == "meta_total":
            total += int(meta_total or 0)
        elif spec["read"] == "unread_field":
            total += int((res["data"] or {}).get("unread", 0))
        elif spec["read"] == "rows":
            total += len(rows)
        elif spec["read"] == "decision_null":
            total += sum(1 for row in rows if row.get("decision") is None)
        elif spec["read"] == "outstanding_gt0":
            total += sum(1 for row in rows if float(row.get("outstanding") or 0) > 0)
        if spec["read"] in ("rows", "decision_null", "outstanding_gt0") and meta_total is not None and int(meta_total) > len(rows):
            truncated = True
    return total, calls, truncated


def launcher_for(pg, email, tag, theme_probe=False):
    """Satu peran: ubin vs NAV, ubin vs endpoint daftar, dan (bila diminta) warna
    aksen ubin di dua tema."""
    pg.context.clear_cookies()
    pg.goto(BASE)
    pg.evaluate("() => localStorage.clear()")
    login(pg, email)
    landing = pg.evaluate("() => location.hash")

    pg.goto(BASE + "#/home")
    pg.wait_for_selector(".home-tile, #view .empty", timeout=15000)
    pg.wait_for_timeout(1200)
    out = pg.evaluate(LAUNCHER_TILES)
    out["landing_after_login"] = landing
    out["viewport"] = pg.viewport_size

    # 1. Ubin yang tampil == modul dengan sedikitnya satu layar yang bisa dibuka
    #    (grup NAV yang terlihat), dalam urutan yang sama.
    shown = [t["prefix"] for t in out["tiles"]]
    out["tiles_equal_nav"] = shown == out["nav_prefixes"]
    out["only_in_tiles"] = sorted(set(shown) - set(out["nav_prefixes"]))
    out["only_in_nav"] = sorted(set(out["nav_prefixes"]) - set(shown))
    out["screens_label_ok"] = all(t["screens"] == f"{out['nav_screens'].get(t['prefix'], -1)} layar" for t in out["tiles"])

    # 2. Setiap angka ubin == angka endpoint daftar modulnya dengan filter yang
    #    sama — dan ubin yang izinnya tidak dipegang WAJIB '—', tidak pernah 0.
    held = pg.evaluate("() => (JSON.parse(localStorage.getItem('nusantara_erp_user') || '{}').permissions || [])")
    out["permissions"] = len(held)
    checks, open_endpoints = {}, []
    for tile in out["tiles"]:
        prefix = tile["prefix"]
        if prefix not in LAUNCHER_CHECKS:
            checks[prefix] = {"ERROR": "tidak ada filter pembanding untuk modul ini"}
            continue
        spec = LAUNCHER_CHECKS[prefix]
        may = spec["perm"] is None or spec["perm"] in held
        expected, calls, truncated = expected_count(pg, prefix)
        shown_kpi = tile["kpi"]
        if not may and expected is not None:
            # Rute index-nya menjawab walau izin layarnya tidak dipegang.
            open_endpoints.append({"prefix": prefix, "perm": spec["perm"], "path": spec["path"], "answered": expected})
        if may:
            ok = expected is not None and shown_kpi == str(expected) and not truncated
            checks[prefix] = {"perm": spec["perm"], "expected": expected, "tile": shown_kpi, "unit": tile["unit"],
                              "caption": tile["caption"], "calls": calls, "truncated": truncated,
                              # Sebuah kecocokan 0 == 0 tidak membedakan filter status yang benar
                              # dari yang salah; yang MEMBEDAKAN adalah angka bukan-nol di kedua sisi.
                              "discriminating": expected not in (None, 0), "ok": ok}
        else:
            ok = shown_kpi == "—"
            checks[prefix] = {"perm": spec["perm"], "held": False, "tile": shown_kpi, "calls": calls, "ok": ok,
                              "why": "izin hitungan tidak dipegang → ubin wajib '—', bukan 0"}
    out["kpi_checks"] = checks
    out["kpi_all_match"] = all(c.get("ok") for c in checks.values())
    out["kpi_mismatches"] = [p for p, c in checks.items() if not c.get("ok")]
    out["kpi_truncated"] = [p for p, c in checks.items() if c.get("truncated")]
    # BERAPA BANYAK ARTI "cocok" itu: modul yang diadu dengan angka bukan-nol di
    # kedua sisi, versus yang hanya membandingkan 0 dengan 0 atau menuntut '—'.
    out["kpi_power"] = {
        "discriminating": sorted(p for p, c in checks.items() if c.get("discriminating")),
        "zero_vs_zero": sorted(p for p, c in checks.items() if c.get("expected") == 0),
        "dash_only": sorted(p for p, c in checks.items() if c.get("held") is False),
    }
    # Kejujuran: tidak satu pun ubin tanpa izin menulis angka.
    out["no_fake_zero"] = not any(c.get("held") is False and c["tile"] != "—" for c in checks.values())
    # …dan '—' tetap MENYEBUT angka apa yang tidak diketahui. Kasus yang paling
    # sering adalah izin hitungan yang tidak dipegang, dan em dash telanjang
    # tanpa keterangan tidak memberi tahu pembacanya apa pun (terukur 6 Sep 2026:
    # warehouse@ 390×844 — Engineering, Aset dan Sistem '—' dengan caption '').
    out["captions"] = {t["prefix"]: t["caption"] for t in out["tiles"]}
    out["tiles_without_caption"] = [t["prefix"] for t in out["tiles"] if not t["caption"]]
    out["every_tile_names_its_number"] = not out["tiles_without_caption"]
    # Temuan terpisah, DILAPORKAN dan tidak diperbaiki di sini: rute daftar yang
    # menjawab tanpa gerbang izin sementara layarnya di SPA bergerbang.
    out["endpoint_open_without_permission"] = open_endpoints

    pg.screenshot(path=f"{OUT}/s22-home-{email.split('@')[0]}{tag}-p1c.png", full_page=True)

    if theme_probe:
        themes = {}
        for theme in ("light", "dark"):
            set_theme(pg, theme)
            tok = pg.evaluate(ACCENT_TOKENS)["tokens"]
            tiles = pg.evaluate(LAUNCHER_TILES)["tiles"]
            rows = {t["prefix"]: {"accent": t["accent"], "border": rgb_to_hex(t["border"]),
                                 "token": tok[t["accent"]]["accent"]} for t in tiles}
            themes[theme] = {"tiles": rows, "all_match": all(r["border"] == r["token"] for r in rows.values())}
            pg.screenshot(path=f"{OUT}/s22-home-{theme}{tag}-p1c.png", full_page=True)
        set_theme(pg, None)
        out["accents"] = themes
        out["accents_ok"] = all(t["all_match"] for t in themes.values())

    return out


def landing_boundary(pg):
    """Aturan landing DI TITIK POTONGNYA, bukan hanya di 1440 dan 390.

    `@media (max-width: 760px)` inklusif, jadi 760 px tepat sudah memakai laci;
    aturan landing yang juga inklusif (`>= 760`) memberi lebar itu laci DAN
    dasbor — gabungan yang aturan itu ada untuk mencegah (terukur 6 Sep 2026,
    admin@ 760 px: #/dashboard dengan nav di luar layar). Yang diperiksa di sini
    bukan angkanya melainkan KESEPAKATANNYA: setiap lebar yang melipat sidebar
    menjadi laci harus mendarat di launcher. Diukur dengan mengubah ukuran
    viewport pada sesi yang sudah masuk — tanpa login tambahan (10/menit/IP)."""
    original = dict(pg.viewport_size)
    out = {}
    for width in (759, 760, 761):
        pg.set_viewport_size({"width": width, "height": original["height"]})
        pg.goto(BASE)  # tanpa hash: aturan landing yang memilih
        pg.wait_for_selector("nav.nav", timeout=15000)
        pg.wait_for_timeout(900)
        out[str(width)] = pg.evaluate("""() => ({ hash: location.hash,
            drawer: matchMedia('(max-width: 760px)').matches,
            nav_offscreen: document.querySelector('nav.nav').getBoundingClientRect().right <= 0,
            menu_toggle: getComputedStyle(document.querySelector('.header .menu-toggle')).display })""")
    pg.set_viewport_size(original)
    out["ok"] = all(v["hash"] == ("#/home" if v["drawer"] else "#/dashboard") for v in
                    (out["759"], out["760"], out["761"]))
    out["drawer_and_dashboard"] = [w for w in ("759", "760", "761") if out[w]["drawer"] and out[w]["hash"] == "#/dashboard"]
    return out


def launcher_truth(pg, tag):
    errors, console_errors = [], []
    pg.on("pageerror", lambda e: errors.append(str(e)[:200]))
    pg.on("console", lambda m: console_errors.append(m.text[:160]) if m.type == "error" else None)
    # <= 760, bukan < 760: titik potong laci app.css inklusif, dan harapan yang
    # dibangun dari perbandingan yang berbeda dari aplikasi tidak memeriksa apa pun.
    mobile = pg.viewport_size["width"] <= 760
    out = {"viewport": pg.viewport_size}

    # ---------------------------------------------------------------- migrasi
    # Kunci localStorage P1-B DITANAM sebelum masuk, lalu dibuktikan: server
    # memilikinya sesudah boot, dan kunci lokalnya sudah tidak ada. Dijalankan
    # lebih dulu karena ia satu-satunya langkah yang menuntut peramban BERSIH.
    pg.context.clear_cookies()
    pg.goto(BASE)
    pg.evaluate("() => localStorage.clear()")
    decide_onboarding("teknisi@nusantara.test")
    teknisi_id = user_id_of("teknisi@nusantara.test")
    # Skenario ini MEMBUAT fixture-nya sendiri (pola S16, verifikasi P1-B putaran 3):
    # jalan sebelumnya di salinan DB yang sama sudah memindahkan preferensi teknisi
    # ke server, dan "migrasi dari nol" yang mengukur baris yang sudah ada di sana
    # akan melaporkan gagal untuk alasan yang bukan produknya.
    reset_preferences(teknisi_id)
    pg.evaluate("""(id) => {
        localStorage.setItem('nusantara_erp_fav:' + id, JSON.stringify(['r/servicedesk/tickets']));
        localStorage.setItem('nusantara_erp_density:' + id, 'compact');
        localStorage.setItem('nusantara_erp_recent:' + id, JSON.stringify([{ route: 'd/servicedesk/tickets/1', label: 'TKT-LAMA', sub: 'Tiket' }]));
    }""", teknisi_id)
    before = preferences_rows(teknisi_id)
    login(pg, "teknisi@nusantara.test")
    pg.wait_for_timeout(2500)
    after = preferences_rows(teknisi_id)
    local_after = pg.evaluate("""(id) => ['nusantara_erp_fav:', 'nusantara_erp_density:', 'nusantara_erp_recent:']
        .filter(k => localStorage.getItem(k + id) !== null)""", teknisi_id)
    out["migration"] = {
        "server_before": before, "server_after": {k: v for k, v in after.items()},
        "local_keys_left": local_after,
        "density_applied": pg.evaluate("() => document.documentElement.dataset.density"),
        "ok": before == {} and after.get("favorites") == ["r/servicedesk/tickets"] and after.get("density") == "compact"
              and [e.get("route") for e in (after.get("recent") or [])] == ["d/servicedesk/tickets/1"]
              and local_after == [],
    }
    # …dan sekali saja: masuk lagi tidak boleh menulis ulang apa pun dari lokal.
    pg.goto(BASE)
    pg.reload()
    pg.wait_for_timeout(2500)
    out["migration"]["server_after_second_boot"] = preferences_rows(teknisi_id)
    out["migration"]["ran_once"] = out["migration"]["server_after_second_boot"] == after

    # ------------------------------------------------------- tiga peran penuh
    emails = ["admin@nusantara.test", "warehouse@nusantara.test", "teknisi@nusantara.test"]
    # Fixture pembeda dulu: tanpa baris ini 8 dari 14 ubin admin berangka 0 dan
    # pemeriksaannya membandingkan 0 dengan 0 (lihat plant_launcher_fixtures).
    out["planted"] = plant_launcher_fixtures(emails)
    out["roles"] = {}
    for index, email in enumerate(emails):
        out["roles"][email.split("@")[0]] = launcher_for(pg, email, tag, theme_probe=(index == 0))

    # Daya beda skenario ini, dinyatakan sebagai angka dan bukan disimpulkan
    # dari "14/14": modul yang PERNAH diadu dengan angka bukan-nol di kedua
    # sisi, dan yang tidak pernah.
    exercised = set()
    for role in out["roles"].values():
        exercised |= set((role.get("kpi_power") or {}).get("discriminating") or [])
    checks_all = [c for role in out["roles"].values() for c in (role.get("kpi_checks") or {}).values()]
    out["kpi_power"] = {
        "modules": len(LAUNCHER_CHECKS),
        "exercised_with_a_nonzero_count": sorted(exercised),
        "modules_never_exercised": sorted(set(LAUNCHER_CHECKS) - exercised),
        "checks_total": len(checks_all),
        "checks_discriminating": sum(1 for c in checks_all if c.get("discriminating")),
        "checks_zero_vs_zero": sum(1 for c in checks_all if c.get("expected") == 0),
        "checks_dash_only": sum(1 for c in checks_all if c.get("held") is False),
    }
    out["kpi_power"]["every_module_exercised"] = not out["kpi_power"]["modules_never_exercised"]

    # --------------------------------------------------------- aturan landing
    # Diukur di viewport skenario ini; pasangannya diukur skenario kembarannya.
    out["landing"] = {"viewport_w": pg.viewport_size["width"],
                      "expected": "#/dashboard" if not mobile else "#/home",
                      "measured": out["roles"]["teknisi"]["landing_after_login"]}
    out["landing"]["ok"] = out["landing"]["measured"] == out["landing"]["expected"]
    # Titik potongnya sendiri — sekali, di skenario desktop (mengubah ukuran
    # konteks is_mobile tidak mengubah pointer/touch-nya, jadi 761 px di sana
    # bukan "desktop" yang sama).
    if not mobile:
        out["landing_boundary"] = landing_boundary(pg)

    # ------------------------------------------------- favorit lintas konteks
    # Bintang dipasang di SATU peramban, dibaca di peramban BARU (konteks baru =
    # localStorage kosong): kalau ia masih ada, ia datang dari server.
    pg.goto(BASE + "#/m/svc")
    pg.wait_for_selector(".module-cell > button.star", timeout=15000)
    pg.wait_for_timeout(600)
    star = ".module-cell:has(a[data-route='r/servicedesk/preventive-schedules']) > button.star"
    click(pg, star)
    pg.wait_for_timeout(1200)
    out["favorite_write"] = {"pressed": pg.evaluate(f"() => document.querySelector({star!r}).getAttribute('aria-pressed')"),
                             "server": preferences_rows(teknisi_id).get("favorites")}
    fresh_ctx = pg.context.browser.new_context(viewport=pg.viewport_size)
    fresh = fresh_ctx.new_page()
    try:
        login(fresh, "teknisi@nusantara.test")
        fresh.goto(BASE + "#/home")
        fresh.wait_for_selector(".home-tile", timeout=15000)
        fresh.wait_for_timeout(1200)
        out["favorite_new_context"] = fresh.evaluate("""() => ({
            sections: [...document.querySelectorAll('.home-section-title')].map(h => h.innerText.trim()),
            favorites: [...document.querySelectorAll('.home-section')].filter(s => /FAVORIT/i.test(s.innerText)).flatMap(s => [...s.querySelectorAll('a')].map(a => a.getAttribute('href'))),
            sidebar: [...document.querySelectorAll("nav.nav .nav-group[data-kind='shortcut'] a")].map(a => a.getAttribute('href')),
            local_prefs_before_load: null })""")
        out["favorite_new_context"]["ok"] = "#/r/servicedesk/preventive-schedules" in out["favorite_new_context"]["favorites"]
    finally:
        fresh_ctx.close()

    # ------------------------------------------------- ketuk ke Lapangan (ponsel)
    if mobile:
        pg.context.clear_cookies()
        pg.goto(BASE)
        pg.evaluate("() => localStorage.clear()")
        login(pg, "site-manager@nusantara.test")
        pg.wait_for_timeout(1500)
        start_hash = pg.evaluate("() => location.hash")
        CLICKS[0] = 0
        tap(pg, ".home-tile[data-prefix=prj]")
        pg.wait_for_timeout(1400)
        tap(pg, ".module-card[data-route=lapangan]")
        pg.wait_for_timeout(1800)
        assert_screen(pg, "#/lapangan")
        out["taps_to_lapangan"] = {"from": start_hash, "taps": CLICKS[0], "hash": pg.evaluate("() => location.hash"),
                                   "target_le_2": CLICKS[0] <= 2}
        pg.screenshot(path=f"{OUT}/s22-lapangan-2-taps{tag}-p1c.png")

    out["pageerrors"] = errors
    # Galat 403 di konsol berasal dari PEMERIKSA ini sendiri: setiap ubin diadu
    # dengan endpoint daftar modulnya, termasuk untuk peran yang memang tidak
    # boleh membacanya (itulah cara kita membuktikan ubinnya '—'). Dipisahkan
    # supaya console_errors skenario tetap berarti "galat saat memakai aplikasi".
    # …dan 429 berasal dari batas 10 login/menit/IP yang ditembus skenario ini
    # sendiri (tiga peran + konteks baru + peran ponsel); login() menunggu
    # Retry-After lalu mencoba lagi, jadi ia bukan kegagalan yang dilihat orang.
    noisy = lambda c: "403" in c or "429" in c
    from_probe = [c for c in console_errors if noisy(c)]
    rest = [c for c in console_errors if not noisy(c)]
    out["console_errors"] = {"count": len(rest), "first": rest[:3]}
    out["console_errors_from_probe"] = {"count": len(from_probe), "first": from_probe[:2]}
    return out


# ------------------------------------------- fixture ubin: baris yang MEMBEDAKAN
#
# Pada salinan data demo 8 dari 14 ubin admin berangka 0, dan "0 == 0" tidak
# membedakan filter status yang benar dari yang salah: registri mengembalikan 0
# dan penyaring pembanding di atas juga 0, jadi 'est' yang menghitung draft
# alih-alih submitted lulus diam-diam. Terukur pada jalan pembangun (verifikasi
# P1-C, 6 Sep 2026): dari 46 pemeriksaan ubin di dua viewport, 26 membandingkan
# 0 dengan 0, 6 hanya menuntut '—', dan 14 sisanya menyentuh 5 dari 14 modul.
#
# Karena itu skenario ini MEMBUAT fixture-nya sendiri (pola yang sama dengan
# langkah migrasi preferensi): satu baris tambahan per modul yang COCOK dengan
# filter ubinnya, disalin dari baris yang sudah ada di tabel itu — jadi setiap
# kolom NOT NULL dan setiap kunci asingnya benar tanpa harus ditulis di sini —
# dengan kolom penentu status yang diganti. Kodenya tetap ('UJI-S22-…'), jadi
# menjalankan skenario dua kali tidak menumpuk baris.
#
# Yang TIDAK dilakukan: menanam baris yang tidak cocok. Baris demo yang ada
# sudah memainkan peran itu (est punya draft, prc punya closed, svc punya
# resolved), dan sisi server-nya dipaku ModuleCountsTest dengan 12 mutasi
# status/scope yang merah.
PLANT_CODE = "UJI-S22"
NOW = time.strftime("%Y-%m-%d %H:%M:%S")

# prefix → (tabel, kolom yang diganti, jumlah minimum baris yang ditanam).
# Jumlah SEBENARNYA dihitung dari datanya (rows_needed): angka yang cocok harus
# LEBIH BESAR daripada jumlah baris status lain mana pun di tabel itu, karena
# kalau tidak, filter status yang salah bisa kebetulan menghasilkan angka yang
# sama — pada salinan demo est punya 1 submitted DAN 1 draft, jadi menanam satu
# baris saja masih meloloskan 'submitted' → 'draft'.
LAUNCHER_PLANTS = [
    ("est", "est_boqs", {"status": "submitted"}, 1),
    ("qc", "qc_ncr", {"status": "open"}, 1),
    ("ast", "ast_assets", {"status": "maintenance"}, 1),
    ("scm", "scm_progress_claims", {"status": "submitted"}, 1),
    ("fin", "fin_ar_invoices", {"status": "approved", "amount_paid": 0, "faktur_pajak_no": None}, 1),
    # Tanpa kolom status: yang membedakan adalah `decision` null vs berisi, dan
    # data demo punya SATU yang sudah diputus — jadi dua baris, bukan satu.
    ("eng", "eng_drawing_submittals", {"decision": None, "superseded_at": None}, 2),
]


def rows_needed(prefix, table, overrides, minimum):
    """Berapa baris yang harus ditanam supaya angka ubinnya MEMBEDAKAN.

    Untuk modul berstatus: satu lebih banyak daripada status lain yang paling
    ramai di tabel itu (status yang dihitung dibaca dari LAUNCHER_CHECKS —
    daftar pembanding harness sendiri, bukan registri yang diperiksanya)."""
    counted = [p["status"] for p in LAUNCHER_CHECKS[prefix]["params"] if "status" in p]
    if "status" not in overrides or not counted:
        return minimum
    con = sqlite3.connect(DB)
    try:
        marks = ",".join("?" * len(counted))
        alive = "deleted_at IS NULL AND" if [r[1] for r in con.execute(f"PRAGMA table_info({table})") if r[1] == "deleted_at"] else ""
        matching = con.execute(f"SELECT COUNT(*) FROM {table} WHERE {alive} status IN ({marks})", counted).fetchone()[0]
        others = con.execute(f"SELECT COUNT(*) FROM {table} WHERE {alive} status NOT IN ({marks}) GROUP BY status", counted).fetchall()
    finally:
        con.close()
    biggest = max([row[0] for row in others] or [0])
    return max(minimum, biggest + 1 - matching)


def plant_row(table, overrides, code=None, source_where=None):
    """Satu baris tambahan di `table`, disalin dari baris yang sudah ada di sana.

    Mengembalikan catatan apa adanya — "ditanam", "sudah ada", "tabel kosong"
    atau "GAGAL: …" — supaya bukti menyebut modul yang fixture-nya TIDAK jadi,
    alih-alih diam dan melaporkan 0 == 0 sebagai kecocokan."""
    con = sqlite3.connect(DB)
    try:
        cols = [r[1] for r in con.execute(f"PRAGMA table_info({table})")]
        if not cols:
            return {"table": table, "state": "tabel tidak ada"}
        if code and "code" in cols:
            if con.execute(f"SELECT COUNT(*) FROM {table} WHERE code = ?", (code,)).fetchone()[0]:
                return {"table": table, "state": "sudah ada", "code": code}
        where = source_where or ("deleted_at IS NULL" if "deleted_at" in cols else "1=1")
        src = con.execute(f"SELECT * FROM {table} WHERE {where} ORDER BY id LIMIT 1").fetchone()
        if src is None:
            return {"table": table, "state": "tabel kosong — tidak ada yang bisa disalin"}
        row = dict(zip(cols, src))
        row.pop("id", None)
        if code and "code" in cols:
            row["code"] = code
        row.update(overrides)
        names = ",".join(f'"{c}"' for c in row)
        cur = con.execute(f'INSERT INTO {table} ({names}) VALUES ({",".join("?" * len(row))})', list(row.values()))
        con.commit()
        return {"table": table, "state": "ditanam", "code": code, "id": cur.lastrowid,
                "overrides": {k: ("null" if v is None else str(v)) for k, v in overrides.items()}}
    except Exception as e:
        return {"table": table, "state": f"GAGAL: {str(e)[:140]}"}
    finally:
        con.close()


def plant_launcher_fixtures(emails):
    """Baris pembeda untuk modul yang ubinnya berangka 0 pada data demo."""
    planted = {}
    for prefix, table, overrides, minimum in LAUNCHER_PLANTS:
        need = rows_needed(prefix, table, overrides, minimum)
        rows = []
        for n in range(need):
            extra = dict(overrides)
            # Kolom unik selain `code` yang ikut disalin dari baris sumber.
            if table == "scm_progress_claims":
                extra["claim_no"] = 90 + n
            if table == "eng_drawing_submittals":
                extra["revision"] = f"R-UJI{n}"
            rows.append(plant_row(table, extra, code=f"{PLANT_CODE}-{prefix.upper()}-{n}"))
        planted[prefix] = {"needed": need, "rows": rows}

    # Persediaan: item baru dengan minimum yang mustahil + saldo 0 di gudang
    # hidup — "item di bawah stok minimum" tidak punya satu kolom status.
    item = plant_row("inv_items", {"min_stock": 999999, "is_active": 1}, code=f"{PLANT_CODE}-ITM",
                     source_where="deleted_at IS NULL AND is_active = 1")
    planted["inv"] = item
    if item.get("state") == "ditanam":
        con = sqlite3.connect(DB)
        warehouse = con.execute("SELECT id FROM inv_warehouses WHERE deleted_at IS NULL ORDER BY id LIMIT 1").fetchone()
        con.close()
        planted["inv_balance"] = (plant_row("inv_stock_balances", {"warehouse_id": warehouse[0], "item_id": item["id"], "qty": 0})
                                  if warehouse else {"state": "tidak ada gudang hidup"})

    # Antrean gagal: tabelnya kosong pada data demo, jadi tidak ada yang bisa
    # disalin — barisnya ditulis kolom per kolom (semuanya sederhana).
    con = sqlite3.connect(DB)
    try:
        if not con.execute("SELECT COUNT(*) FROM failed_jobs WHERE uuid = ?", (PLANT_CODE,)).fetchone()[0]:
            con.execute("INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES (?,?,?,?,?,?)",
                        (PLANT_CODE, "database", "default", "{}", "Uji S22", NOW))
            con.commit()
            planted["iam"] = {"table": "failed_jobs", "state": "ditanam", "code": PLANT_CODE}
        else:
            planted["iam"] = {"table": "failed_jobs", "state": "sudah ada", "code": PLANT_CODE}

        # Notifikasi belum dibaca: angkanya PER ORANG, jadi satu baris per akun
        # yang diukur skenario ini.
        rows = []
        for email in emails:
            uid = user_id_of(email)
            if uid is None:
                rows.append({"email": email, "state": "akun tidak ada"})
                continue
            if con.execute("SELECT COUNT(*) FROM core_notifications WHERE user_id = ? AND title = ?", (uid, PLANT_CODE)).fetchone()[0]:
                rows.append({"email": email, "state": "sudah ada"})
                continue
            con.execute("INSERT INTO core_notifications (user_id, event, title, read_at, created_at, updated_at) VALUES (?,?,?,NULL,?,?)",
                        (uid, "document.submitted", PLANT_CODE, NOW, NOW))
            rows.append({"email": email, "state": "ditanam"})
        con.commit()
        planted["ringkasan"] = rows
    except Exception as e:
        planted["ERROR"] = str(e)[:160]
    finally:
        con.close()

    return planted


def user_id_of(email):
    con = sqlite3.connect(DB); row = con.execute("SELECT id FROM users WHERE email=?", (email,)).fetchone(); con.close()
    return row[0] if row else None


def reset_preferences(user_id):
    con = sqlite3.connect(DB); con.execute("DELETE FROM core_user_preferences WHERE user_id=?", (user_id,)); con.commit(); con.close()


def preferences_rows(user_id):
    """Isi core_user_preferences milik satu pengguna, dibaca LANGSUNG dari sqlite —
    bukti sisi-server yang tidak bisa dipalsukan localStorage peramban."""
    con = sqlite3.connect(DB)
    rows = con.execute("SELECT key, value FROM core_user_preferences WHERE user_id=?", (user_id,)).fetchall()
    con.close()
    return {k: json.loads(v) for k, v in rows}


@scenario("S22_launcher_truth")
def s22(pg):
    return launcher_truth(pg, "")


@scenario("S22_launcher_truth_mobile")
def s22m(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        return launcher_truth(pg, "-mobile")
    finally:
        ctx.close()


@scenario("S22_roles_with_tiles")
def s22r(pg):
    """Target metrik Fase 1 "0 peran tanpa ubin", diukur dengan MASUK sebagai
    kedua belas akun demo — bukan dengan membaca RoleSeeder. Yang dicatat per
    peran: jumlah ubin launcher, jumlah ubin angka dasbor (.stat), dan angka
    yang tidak diketahui ('—'), supaya "punya ubin" tidak pernah berarti "ubin
    yang tak satu pun berisi"."""
    roles = ["admin", "direktur", "project-manager", "site-manager", "estimator", "procurement",
             "warehouse", "finance", "finance-manager", "hr", "sales", "teknisi"]
    out = {}
    for role in roles:
        pg.context.clear_cookies()
        pg.goto(BASE)
        pg.evaluate("() => localStorage.clear()")
        try:
            login(pg, f"{role}@nusantara.test")
        except Exception as e:
            out[role] = {"ERROR": str(e)[:140]}
            continue
        pg.goto(BASE + "#/dashboard")
        # TUNGGU SAMPAI SELESAI, bukan 1800 ms. Sejak P1-D dasbor memuat per
        # BATCH 4 secara berurutan, jadi sebuah jeda tetap menghitung ubin
        # setengah jalan — angka yang berbeda tiap jalan dan tidak berarti apa
        # pun (verifikasi kedua P1-D). Yang ditunggu adalah hilangnya seluruh
        # kerangka; batas atasnya tetap ada supaya satu widget yang menggantung
        # tidak menggantung skenario.
        try:
            pg.wait_for_function(
                "() => document.querySelector('.dash-grid') && "
                "!document.querySelector('.dash-grid .skeleton')",
                timeout=20000)
        except Exception:
            pass
        pg.wait_for_timeout(400)
        stats = pg.evaluate("() => document.querySelectorAll('.stat').length")
        pg.goto(BASE + "#/home")
        pg.wait_for_selector(".home-tile, #view .empty", timeout=15000)
        pg.wait_for_timeout(1200)
        out[role] = pg.evaluate("""(stats) => {
            const tiles = [...document.querySelectorAll('.home-tile')];
            return { launcher_tiles: tiles.length,
                     dashboard_stats: stats,
                     prefixes: tiles.map(t => t.dataset.prefix),
                     unknown_counts: tiles.filter(t => t.querySelector('.home-kpi-value').innerText.trim() === '—').map(t => t.dataset.prefix),
                     empty_state: !!document.querySelector('#view .empty') };
        }""", stats)
    ok = [r for r, v in out.items() if not v.get("ERROR")]
    return {"roles": out,
            "roles_measured": len(ok),
            "roles_without_launcher_tile": [r for r in ok if out[r]["launcher_tiles"] == 0],
            "roles_without_dashboard_stat": [r for r in ok if out[r]["dashboard_stats"] == 0],
            "tiles_by_role": {r: out[r]["launcher_tiles"] for r in ok}}


@scenario("S21_module_accents_breadcrumb")
def s21(pg):
    return module_accents(pg, "")

@scenario("S21_module_accents_breadcrumb_mobile")
def s21m(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        return module_accents(pg, "-mobile")
    finally:
        ctx.close()

# ---------------------------------------------------------------- S23 (P1-D)
#
# Dasbor yang bisa diatur: apakah setiap peran mendapat kartu, berapa permintaan
# yang dibayar susunan BAWAANNYA, apakah pemuatan benar-benar per batch 4, apakah
# laci menyimpan apa yang dipilih orangnya, dan apakah kartu yang sumbernya jatuh
# MENGAKU jatuh alih-alih menulis Rp 0.
#
# Angka terakhir itulah alasan skenario ini ada. Uji PHP hanya bisa membuktikan
# bahwa setiap berkas widget MEMANGGIL failure(); yang tidak bisa dibuktikannya
# adalah bahwa yang tergambar ketika sumbernya benar-benar jatuh adalah kalimat
# "gagal dimuat" dan bukan angka nol yang meyakinkan. Di sini permintaannya
# benar-benar dijatuhkan (route abort) dan yang dibaca adalah teks di layar.

# Widget yang mengirim LEBIH DARI SATU permintaan. Satu-satunya hari ini, dan
# alasannya ditulis di views/widgets/ncr.js: "NCR terbuka" berarti open ATAU
# under_correction, endpoint daftar hanya menerima satu status, dan menyaring
# sisi klien atas halaman pertama adalah Temuan 79.
MULTI_REQUEST_WIDGETS = {"ncr"}

S23_ACCOUNTS = [
    "admin@nusantara.test", "direktur@nusantara.test", "project-manager@nusantara.test",
    "site-manager@nusantara.test", "estimator@nusantara.test", "procurement@nusantara.test",
    "warehouse@nusantara.test", "finance@nusantara.test", "finance-manager@nusantara.test",
    "hr@nusantara.test", "sales@nusantara.test", "teknisi@nusantara.test",
]

# Baris preferensi `dashboard.layout` di SERVER, dibaca lewat API — bukan
# lewat cermin localStorage, yang menjawab sama dengan atau tanpa baris server.
PREF_LAYOUT_READ = """async () => {
    const r = await fetch('/api/core/me/preferences', { headers: {
        'X-Api-Token': localStorage.getItem('nusantara_erp_token'), Accept: 'application/json' } });
    const j = await r.json();
    return (j.data || []).filter((x) => x.key === 'dashboard.layout').map((x) => x.value)[0] || null;
}"""


DASH_CARDS = """() => [...document.querySelectorAll('.dash-grid .card.widget')].map((c) => ({
    id: c.dataset.widget,
    size: c.dataset.size,
    span: getComputedStyle(c).gridColumnEnd,
    title: (c.querySelector('.card-head h2') || {}).innerText || null,
    body: ((c.querySelector('.widget-body') || {}).innerText || '').trim(),
    // Kaki kartu = pintu ke layar yang memuat angka ini secara lengkap. Sebuah
    // kartu tanpa kaki adalah angka tanpa cara memeriksanya, dan itu paling
    // menyakitkan justru pada kartu yang isinya kosong (verifikasi kedua P1-D:
    // 10 dari 19 kartu tanpa .card-foot, termasuk seluruh kartu umur piutang/
    // hutang/pajak yang sedang kosong).
    foot: ((c.querySelector('.card-foot') || {}).innerText || '').trim() || null,
}))"""


# Permintaan yang dibayar SETIAP layar, bukan oleh susunan dasbor: sesi, izin,
# lencana lonceng, dan pemuatan preferensi di boot.
#
# `core/health` TIDAK ada di sini sejak verifikasi kedua P1-D. Docstring lama
# menyebutnya "dibayar setiap layar", dan itu tidak benar: grep menunjukkan
# satu-satunya pemanggilnya di seluruh SPA adalah views/dashboard.js
# (schedulerBanner, hanya pemegang core.update), dan navigasi ke #/home atau ke
# layar daftar mana pun mengirim NOL core/health. Membukukannya sebagai shell
# berarti permintaan kesembilan dasbor admin tidak dihitung — tidak di
# api_widgets, tidak di anggaran serentak. Di localhost ia kebetulan selesai
# sebelum batch pertama; di lapangan ia berjalan bersamanya, dan angka yang
# diumumkan harness akan salah tepat pada keadaan yang paling penting.
SHELL_REQUESTS = ("iam/auth", "core/notifications/unread-count", "core/me/preferences")


def dash_probe(pg):
    """Pasang penghitung permintaan WIDGET yang berjalan bersamaan.

    Diukur dari sisi peramban, bukan dari log server: yang dijanjikan paket ini
    adalah berapa banyak yang BERANGKAT sekaligus, dan hanya klien yang tahu itu.

    Permintaan shell sengaja tidak dihitung dalam serentaknya. Bukan karena
    murah, melainkan karena ia bukan yang dibatasi: `prefs.load()` di boot
    berangkat sendiri dan bisa tumpang tindih dengan batch pertama — terukur
    6 Sep 2026 pada finance-manager sebagai 5 permintaan serentak untuk susunan
    yang tidak memuat satu pun widget dua-permintaan. Jumlah SELURUH permintaan
    tetap dicatat terpisah (`api_total`), karena itulah angka yang dibandingkan
    dengan target metrik Fase 1.
    """
    inflight = {"now": 0, "max": 0, "urls": []}

    def is_widget(url):
        if "/api/" not in url:
            return False
        return not url.split("/api/")[1].startswith(SHELL_REQUESTS)

    def start(req):
        if "/api/" not in req.url:
            return
        inflight["urls"].append(req.url.split("/api/")[1])
        if not is_widget(req.url):
            return
        inflight["now"] += 1
        inflight["max"] = max(inflight["max"], inflight["now"])

    def done(req):
        if is_widget(req.url):
            inflight["now"] = max(0, inflight["now"] - 1)

    pg.on("request", start)
    pg.on("requestfinished", done)
    pg.on("requestfailed", done)
    return inflight


@scenario("S23_dashboard_per_role")
def s23(pg):
    out = {"roles": {}, "roles_without_cards": [], "cards_total": 0}

    for email in S23_ACCOUNTS:
        ctx = pg.context.browser.new_context(viewport={"width": 1440, "height": 900})
        page = ctx.new_page()
        probe = dash_probe(page)
        try:
            login(page, email)
            page.wait_for_timeout(2500)
            assert_screen(page, "#/dashboard")
            cards = page.evaluate(DASH_CARDS)
            if email in ("direktur@nusantara.test", "teknisi@nusantara.test"):
                page.screenshot(path=f"{OUT}/s23-dashboard-{email.split('@')[0]}-p1d.png", full_page=True)
            # Permintaan widget saja (SHELL_REQUESTS di atas dibayar setiap layar).
            widget_reqs = [u for u in probe["urls"] if not u.startswith(SHELL_REQUESTS)]
            out["roles"][email.split("@")[0]] = {
                "cards": [c["id"] for c in cards],
                "sizes": {c["id"]: c["size"] for c in cards},
                "api_total": len(probe["urls"]),
                "api_widgets": len(widget_reqs),
                "max_concurrent": probe["max"],
                # Kartu yang badannya kosong tidak pernah benar: sebuah widget
                # menggambar angkanya, keadaan kosongnya, atau kalimat gagalnya.
                "empty_bodies": [c["id"] for c in cards if len(c["body"]) < 3],
                # …dan kartu tanpa KAKI adalah angka tanpa cara memeriksanya.
                "cards_without_foot": [c["id"] for c in cards if not c["foot"]],
            }
            # Batch 4 membatasi WIDGET, bukan permintaan: satu widget boleh
            # mengirim lebih dari satu (hanya `ncr`, yang menjumlah dua status
            # karena endpoint daftar menerima satu status per permintaan dan
            # angkanya harus sama dengan ubin launcher). Batas atas yang benar
            # karena itu 4 + jumlah permintaan EKSTRA milik widget semacam itu
            # yang ada di susunan ini — bukan 4 mentah, dan bukan "berapa pun".
            extra = sum(1 for c in cards if c["id"] in MULTI_REQUEST_WIDGETS)
            # `core/health` adalah permintaan DASBOR, bukan shell (lihat
            # SHELL_REQUESTS): satu-satunya pemanggilnya di seluruh SPA adalah
            # spanduk penjadwal di views/dashboard.js, dan hanya untuk pemegang
            # core.update. Ia sengaja tidak menahan batch mana pun — docblock
            # schedulerBanner: "slot kosong dulu, spanduk menyusul" — jadi ia
            # boleh berjalan di samping batch pertama, dan anggarannya menyebut
            # itu alih-alih menyembunyikannya sebagai "bukan permintaan dasbor".
            banner = sum(1 for u in probe["urls"] if u.startswith("core/health"))
            out["roles"][email.split("@")[0]]["scheduler_banner_requests"] = banner
            out["roles"][email.split("@")[0]]["concurrent_budget"] = 4 + extra + banner
            out["cards_total"] += len(cards)
            if not cards:
                out["roles_without_cards"].append(email)
        finally:
            ctx.close()

    out["max_concurrent_any_role"] = max(r["max_concurrent"] for r in out["roles"].values())
    out["over_budget"] = [
        name for name, r in out["roles"].items() if r["max_concurrent"] > r["concurrent_budget"]
    ]

    # --- MUAT ULANG BERTUBI-TUBI -----------------------------------------
    #
    # Angka di atas diukur pada SATU pemuatan yang tenang, dan itulah keadaan
    # yang paling jarang terjadi di lapangan. renderDashboard() dipanggil ulang
    # oleh tombol Muat ulang, sakelar 'Proyek saya' dan laci yang menyimpan;
    # sampai verifikasi kedua P1-D gambar LAMA tidak berhenti — `clear(host)`
    # melepaskan kartunya, perulangan batch-nya jalan terus. Terukur: tiga klik
    # berjarak 120 ms = 27 permintaan, 12 berjalan bersamaan, tiga batch
    # tumpang tindih. Yang dijaga di sini adalah janji paketnya sendiri:
    # tidak pernah lebih dari BATCH sekaligus, berapa kali pun tombolnya
    # ditekan.
    ctx = pg.context.browser.new_context(viewport={"width": 1440, "height": 900})
    page = ctx.new_page()
    probe = dash_probe(page)
    try:
        login(page, "direktur@nusantara.test")
        page.wait_for_timeout(3000)
        cards = page.evaluate(DASH_CARDS)
        budget = 4 + sum(1 for c in cards if c["id"] in MULTI_REQUEST_WIDGETS)

        probe["urls"].clear()
        probe["max"] = 0
        for _ in range(3):
            page.evaluate("() => { const b = document.querySelector(\".page-head .actions button.icon\"); if (b && !b.disabled) b.click(); }")
            page.wait_for_timeout(120)
        page.wait_for_timeout(4000)

        widget_reqs = [u for u in probe["urls"] if not u.startswith(SHELL_REQUESTS)]
        out["rapid_reload"] = {
            "clicks": 3,
            "gap_ms": 120,
            "cards": len(cards),
            "api_widgets": len(widget_reqs),
            "max_concurrent": probe["max"],
            "concurrent_budget": budget,
            # Kartu tetap terisi sesudahnya: berhenti bukan berarti menyerah.
            "empty_bodies_after": [c["id"] for c in page.evaluate(DASH_CARDS) if len(c["body"]) < 3],
        }
    finally:
        ctx.close()

    # --- SAKELAR 'Proyek saya' menyaring KEDUA kartu proyek ----------------
    #
    # ringkasan-uang mengirim `mine`, dan docblock-nya menjanjikan bahwa widget
    # itu dan "Progres proyek" "selalu bercerita tentang himpunan proyek yang
    # SAMA saat sakelar Proyek saya menyala". Sampai verifikasi kedua P1-D
    # proyek-progres.js tidak mengirim `mine` sama sekali (regresi dari P1-C,
    # yang mengirimkannya): dasbor project-manager dengan sakelar menyala
    # menuliskan ubin uang untuk proyek MILIKNYA di sebelah daftar yang memuat
    # seluruh portofolio. Yang diukur di sini adalah parameter yang benar-benar
    # berangkat, dan judul kartu yang ikut berganti.
    ctx = pg.context.browser.new_context(viewport={"width": 1440, "height": 900})
    page = ctx.new_page()
    sent = []
    page.on("request", lambda r: sent.append(r.url.split("/api/")[1]) if "/api/" in r.url else None)
    try:
        login(page, "project-manager@nusantara.test")
        page.wait_for_timeout(3000)
        sent.clear()
        # Sakelar menyala; kalau sudah menyala, dimatikan lalu dinyalakan lagi.
        state = page.evaluate("() => localStorage.getItem('nusantara_erp_dash_mine')")
        if state == "1":
            page.click(".page-head .actions button:has-text('Proyek saya')")
            page.wait_for_timeout(2500)
            sent.clear()
        page.click(".page-head .actions button:has-text('Proyek saya')")
        page.wait_for_timeout(3500)

        out["mine_switch"] = {
            "summary_request": next((u for u in sent if u.startswith("core/dashboard/summary")), None),
            "projects_request": next((u for u in sent if u.startswith("projects")), None),
            "card_titles": page.evaluate(
                "() => [...document.querySelectorAll('.dash-grid .card.widget')]"
                ".map((c) => ({ id: c.dataset.widget, title: (c.querySelector('.card-head h2')||{}).innerText }))"),
        }
        page.screenshot(path=f"{OUT}/s23-proyek-saya-p1d.png", full_page=True)
    finally:
        ctx.close()

    mine = out["mine_switch"]
    titles = {c["id"]: c["title"] for c in mine["card_titles"]}
    mine["both_filtered"] = (
        "mine=1" in (mine["summary_request"] or "")
        and "mine=1" in (mine["projects_request"] or "")
    )
    # Kartu yang berganti makna berganti judul: pola ringkasan-uang.
    mine["progres_card_says_mine"] = titles.get("proyek-progres") == "Progres proyek saya"

    out["ok"] = (
        not out["roles_without_cards"]
        and not out["over_budget"]
        and not any(r["empty_bodies"] for r in out["roles"].values())
        and out["rapid_reload"]["max_concurrent"] <= out["rapid_reload"]["concurrent_budget"]
        and not out["rapid_reload"]["empty_bodies_after"]
        and mine["both_filtered"]
        and mine["progres_card_says_mine"]
        and not any(r["cards_without_foot"] for r in out["roles"].values())
    )
    return out


@scenario("S23_setup_drawer")
def s23s(pg):
    """Laci: tambah, hapus, ubah ukuran, urutkan — lalu MUAT ULANG dan baca lagi."""
    login(pg, "direktur@nusantara.test")
    pg.wait_for_timeout(2500)
    before = [c["id"] for c in pg.evaluate(DASH_CARDS)]

    # Baris preferensi SEBELUM skenario ini menyentuhnya, supaya jalan ini bisa
    # diulang: tanpa mengembalikannya, jalan kedua berangkat dari susunan yang
    # ditinggalkan jalan pertama.
    initial_pref = pg.evaluate(PREF_LAYOUT_READ)

    # Bawaan peran menurut REGISTRI yang dikapalkan — bukan menurut apa yang
    # kebetulan tergambar sebelum skenario ini mulai. Sampai verifikasi kedua
    # P1-D "Kembalikan ke bawaan" dibandingkan dengan `before`, yang sama
    # dengan bawaan HANYA selama dasbor mengabaikan susunan tersimpan pada
    # kunjungan pertama (d-correct-1): begitu itu diperbaiki, perbandingan itu
    # merah pada jalan kedua walau tombolnya benar.
    default_layout = pg.evaluate("""async () => {
        const reg = await import('/app/js/views/widgets/registry.js');
        const api = await import('/app/js/api.js');
        const user = api.session.user || {};
        return reg.defaultLayout(user.roles || [], (perm) => api.session.can(perm));
    }""")
    default_ids = [e["id"] for e in default_layout]

    click(pg, ".page-head .actions button:has-text('Atur dasbor')")
    pg.wait_for_selector(".modal .dash-setup-list", timeout=10000)
    pg.screenshot(path=f"{OUT}/s23-atur-dasbor-p1d.png", full_page=True)

    spare_first = pg.locator(".dash-setup-spare .dash-setup-row").first
    added = spare_first.locator(".dash-setup-name .cell-main").inner_text()
    click(pg, ".dash-setup-spare .dash-setup-row:first-child button:has-text('Tambah')")
    pg.wait_for_timeout(150)

    removed = pg.locator(".dash-setup-list .dash-setup-row").first.get_attribute("data-id")
    click(pg, ".dash-setup-list .dash-setup-row:first-child button:has-text('Hapus')")
    pg.wait_for_timeout(150)

    # Ukuran baris pertama diubah ke pilihan yang BUKAN nilainya sekarang.
    sel = pg.locator(".dash-setup-list .dash-setup-row").first.locator("select.dash-setup-size")
    options = sel.evaluate("(s) => [...s.options].map((o) => o.value)")
    now = sel.input_value()
    target = next(o for o in options if o != now)
    sel.select_option(target)
    resized = pg.locator(".dash-setup-list .dash-setup-row").first.get_attribute("data-id")

    # Urutan: baris kedua dinaikkan lewat tombol (jalur papan ketik, tanpa vendor).
    second = pg.locator(".dash-setup-list .dash-setup-row").nth(1).get_attribute("data-id")
    click(pg, ".dash-setup-list .dash-setup-row:nth-child(2) button:has-text('Naik')")
    pg.wait_for_timeout(150)
    # JALUR PAPAN KETIK: fokus tidak boleh dibuang setiap kali daftar digambar ulang.
    #
    # docblock dashsetup.js menjanjikan "PAPAN KETIK LEBIH DULU, SERET
    # BELAKANGAN" — Naik/Turun yang "bekerja tanpa satu byte vendor pun".
    # Tetapi setiap penekanan memanggil paint(), yang membangun ulang seluruh
    # daftar dan MENGHANCURKAN tombol yang sedang dipegang: terukur sebelum
    # verifikasi kedua P1-D, Enter pada Naik baris ke-3 memindahkan barisnya
    # lalu melempar fokus ke <select> BARIS PERTAMA, jadi memindahkan satu
    # widget dari posisi 9 ke 1 berarti delapan penekanan yang masing-masing
    # didahului jalan-jalan Tab yang makin panjang.
    kbd_row = pg.locator(".dash-setup-list .dash-setup-row").nth(2).get_attribute("data-id")
    pg.evaluate("""(id) => {
        const row = document.querySelector(`.dash-setup-row[data-id="${id}"]`);
        const up = [...row.querySelectorAll(".btn")].find((b) => b.textContent.trim() === "Naik");
        up.focus();
    }""", kbd_row)
    pg.keyboard.press("Enter")
    pg.wait_for_timeout(300)
    keyboard_focus = pg.evaluate("""() => {
        const a = document.activeElement;
        const row = a && a.closest ? a.closest(".dash-setup-row") : null;
        return {
            tag: a ? a.tagName : null,
            // Dipotong: activeElement bisa jadi <body>, dan seluruh teks halaman
            // di dalam results.json membuat berkas bukti itu tidak terbaca.
            label: a ? a.textContent.trim().slice(0, 40) : null,
            row_id: row ? row.dataset.id : null,
            row_index: row ? [...document.querySelectorAll(".dash-setup-list .dash-setup-row")].indexOf(row) : null,
        };
    }""")
    keyboard_focus["moved_row"] = kbd_row
    # Fokus tetap pada tombol Naik baris YANG SAMA, yang kini satu tingkat naik.
    keyboard_focus["stays_on_the_moved_row"] = (
        keyboard_focus["row_id"] == kbd_row and keyboard_focus["label"] == "Naik"
    )

    order_in_drawer = pg.evaluate(
        "() => [...document.querySelectorAll('.dash-setup-list .dash-setup-row')].map((r) => r.dataset.id)")

    # PENJAGA "perubahan belum disimpan", sebelum menyimpan apa pun.
    #
    # docblock dashsetup.js memilih modal() justru KARENA aplikasi ini sudah
    # punya penjaga itu — tetapi `dirty` adalah opsi yang harus dilewatkan, dan
    # sampai verifikasi kedua P1-D panggilannya tidak melewatkannya: satu
    # ketukan Escape membuang urutan yang baru saja ditata, tanpa satu
    # pertanyaan pun. Di sini Escape ditekan dengan draft yang SUDAH berubah,
    # dan yang diukur adalah dialognya muncul dan lacinya tetap terbuka.
    pg.keyboard.press("Escape")
    pg.wait_for_timeout(600)
    dirty_guard = pg.evaluate("""() => ({
        prompt: (document.querySelector('.overlay-stacked .modal') || {}).innerText || null,
        drawer_still_open: !!document.querySelector('.modal .dash-setup-list'),
    })""")
    # "Kembali menata": lacinya harus utuh, termasuk urutan yang sudah diubah.
    pg.evaluate("""() => {
        const buttons = [...document.querySelectorAll('.overlay-stacked .modal-foot button')];
        const back = buttons.find((b) => !b.classList.contains('primary')) || buttons[0];
        if (back) back.click();
    }""")
    pg.wait_for_timeout(400)
    dirty_guard["order_kept"] = pg.evaluate(
        "() => [...document.querySelectorAll('.dash-setup-list .dash-setup-row')].map((r) => r.dataset.id)")

    click(pg, ".modal-foot button:has-text('Simpan')")
    pg.wait_for_timeout(1200)
    after_save = [c["id"] for c in pg.evaluate(DASH_CARDS)]

    # KONTEKS PERAMBAN BARU, bukan pg.reload().
    #
    # Sampai verifikasi kedua P1-D baris ini berbunyi "muat ulang penuh: yang
    # diuji adalah baris preferensi di SERVER" — dan itu tidak benar: prefs.js
    # menyimpan CERMIN localStorage per pengguna yang dibaca sinkron, jadi
    # sebuah reload di konteks yang sama mengembalikan susunannya dengan atau
    # tanpa baris server. Terbukti: jalan pertama verifikasi memakai salinan
    # database/database.sqlite yang belum dimigrasi (tanpa tabel
    # core_user_preferences, setiap panggilan preferensi 500) dan after_reload
    # tetap sama dengan after_save.
    #
    # Konteks baru = cermin kosong = hanya server yang bisa menjawab. Itu juga
    # persis keadaan yang membuat dasbor pernah menggambar BAWAAN PERAN pada
    # kunjungan pertama dan tidak pernah memperbaikinya (temuan d-correct-1 /
    # d-ux-01): kalau pendengar 'erp:prefs-loaded' di views/dashboard.js hilang
    # lagi, kolom di bawah ini merah.
    fresh_ctx = pg.context.browser.new_context(viewport={"width": 1440, "height": 900})
    fresh = fresh_ctx.new_page()
    try:
        login(fresh, "direktur@nusantara.test")
        fresh.wait_for_selector("nav.nav", timeout=15000)
        # Cukup lama untuk prefs.load() + gambar ulang, tetapi TIDAK dibantu
        # navigasi apa pun: kunjungan PERTAMA yang harus benar.
        fresh.wait_for_timeout(6000)
        reloaded = fresh.evaluate(DASH_CARDS)
        fresh.screenshot(path=f"{OUT}/s23-konteks-baru-p1d.png", full_page=True)
    finally:
        fresh_ctx.close()

    reloaded_ids = [c["id"] for c in reloaded]

    stored = pg.evaluate(PREF_LAYOUT_READ)

    click(pg, ".page-head .actions button:has-text('Atur dasbor')")
    pg.wait_for_selector(".modal .dash-setup-list", timeout=10000)
    click(pg, ".modal-foot button:has-text('Kembalikan ke bawaan')")
    pg.wait_for_timeout(200)
    restored = pg.evaluate(
        "() => [...document.querySelectorAll('.dash-setup-list .dash-setup-row')].map((r) => r.dataset.id)")
    click(pg, ".modal-foot button:has-text('Batal')")

    # Skenario ini mengembalikan baris preferensi ke keadaan semula: tidak ada
    # endpoint yang MENGHAPUS preferensi, jadi yang bisa dilakukan adalah
    # menulis kembali nilai awalnya. Bila BELUM ada barisnya, yang ditulis
    # adalah bawaan perannya — bukan `[]`, yang berarti "sengaja dikosongkan"
    # dan membuat jalan berikutnya membuka laci tanpa satu baris pun.
    # Tanpa ini jalan kedua berangkat dari susunan yang ditinggalkan jalan
    # pertama, dan angkanya tidak bisa dibandingkan antar jalan.
    pg.evaluate("""async (value) => {
        await fetch('/api/core/me/preferences/dashboard.layout', {
            method: 'PUT',
            headers: { 'X-Api-Token': localStorage.getItem('nusantara_erp_token'),
                       Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ value }),
        });
        localStorage.clear();
    }""", initial_pref if initial_pref is not None else default_layout)

    return {
        "before": before,
        "added_label": added,
        "removed": removed,
        "resized": resized, "resized_from": now, "resized_to": target,
        "moved_up": second,
        "order_in_drawer": order_in_drawer,
        "keyboard_focus": keyboard_focus,
        "dirty_guard": dirty_guard,
        "after_save": after_save,
        "after_fresh_context": reloaded_ids,
        "stored_preference": stored,
        "role_default": default_ids,
        "restore_default_matches_role_default": restored == default_ids,
        "sizes_after_fresh_context": {c["id"]: c["size"] for c in reloaded},
        # Ukuran yang diubah harus SELAMAT juga — `sizes_after_reload` dicatat
        # sejak awal tetapi tidak pernah dibandingkan dengan `resized_to`, jadi
        # satu-satunya perubahan yang tidak berupa urutan tidak pernah diuji.
        "resized_survived": {c["id"]: c["size"] for c in reloaded}.get(resized) == target,
        "ok": (
            # Escape pada draf yang berubah BERTANYA dulu, dan lacinya utuh.
            keyboard_focus["stays_on_the_moved_row"]
            and bool(dirty_guard["prompt"]) and "belum disimpan" in (dirty_guard["prompt"] or "")
            and dirty_guard["drawer_still_open"]
            and dirty_guard["order_kept"] == order_in_drawer
            and removed not in reloaded_ids
            and reloaded_ids == order_in_drawer
            and reloaded_ids == after_save
            and isinstance(stored, list) and len(stored) == len(reloaded_ids)
            and {c["id"]: c["size"] for c in reloaded}.get(resized) == target
            and restored == default_ids
        ),
    }


@scenario("S23_honest_failure")
def s23f(pg):
    """Satu sumber dijatuhkan sungguhan; kartunya harus MENGAKU, bukan menulis 0."""
    login(pg, "finance@nusantara.test")
    pg.wait_for_timeout(2500)
    healthy = {c["id"]: c["body"] for c in pg.evaluate(DASH_CARDS)}

    # `core/dashboard/summary` memberi makan KETIGA ubin uang Temuan 79.
    pg.route("**/api/core/dashboard/summary*", lambda route: route.abort())
    pg.reload()
    pg.wait_for_selector("nav.nav", timeout=15000)
    pg.wait_for_timeout(3500)
    broken = {c["id"]: c["body"] for c in pg.evaluate(DASH_CARDS)}
    pg.screenshot(path=f"{OUT}/s23-gagal-jujur-p1d.png", full_page=True)
    pg.unroute("**/api/core/dashboard/summary*")

    money = broken.get("ringkasan-uang", "")
    others = [i for i in healthy if i != "ringkasan-uang"]

    # …dan PEMULIHANNYA. Mengaku gagal tanpa menawarkan jalan keluar hanya
    # setengah janji paket ini ("satu widget yang gagal memuat ulang dirinya
    # sendiri"): sampai verifikasi kedua P1-D kartu uang yang jatuh punya NOL
    # tombol, dan satu-satunya pemulihannya adalah Muat ulang di kepala
    # halaman — sembilan permintaan untuk memperbaiki satu, yaitu perilaku
    # P1-C yang paket ini menyatakan sudah digantikannya. Yang diukur di sini
    # adalah tombolnya DAN berapa permintaan yang dibayar satu klik.
    retry_buttons = pg.evaluate(
        "() => [...document.querySelectorAll('.dash-grid .card.widget[data-widget=\"ringkasan-uang\"] button')]"
        ".map((b) => b.innerText.trim())")

    requests_on_retry = []
    pg.on("request", lambda r: requests_on_retry.append(r.url.split("/api/")[1]) if "/api/" in r.url else None)
    if retry_buttons:
        pg.click(".dash-grid .card.widget[data-widget='ringkasan-uang'] button")
        pg.wait_for_timeout(2000)

    return {
        "healthy_money_card": healthy.get("ringkasan-uang", "")[:120],
        "broken_money_card": money[:160],
        "card_still_drawn": "ringkasan-uang" in broken,
        "says_failed": "Gagal dimuat" in money,
        "writes_em_dash": money.count("—") >= 3,
        # Yang paling penting: TIDAK ada angka rupiah yang dikarang.
        "no_zero_rupiah": "Rp 0" not in money,
        "retry_buttons": retry_buttons,
        "requests_on_retry": requests_on_retry,
        "other_cards_still_loaded": [i for i in others if i in broken and len(broken[i]) > 3],
        "other_cards_lost": [i for i in others if i not in broken],
        "ok": (
            "ringkasan-uang" in broken
            and "Gagal dimuat" in money
            and "Rp 0" not in money
            and retry_buttons == ["Coba lagi"]
            # Satu klik = satu permintaan: widget itu saja, bukan dasbor.
            and len(requests_on_retry) == 1
            and not [i for i in others if i not in broken]
        ),
    }


@scenario("S23_dashboard_mobile")
def s23m(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        login(pg, "teknisi@nusantara.test")
        # Aturan landing P1-C: ponsel mendarat di #/home, jadi dasbor dibuka sendiri.
        pg.evaluate("() => { location.hash = '#/dashboard'; }")
        pg.wait_for_timeout(3000)
        cards = pg.evaluate(DASH_CARDS)
        geom = pg.evaluate("""() => {
            const grid = document.querySelector('.dash-grid');
            const rects = [...document.querySelectorAll('.dash-grid .card.widget')].map((c) => c.getBoundingClientRect());
            return {
                cols: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : null,
                widths: rects.map((r) => Math.round(r.width)),
                lefts: [...new Set(rects.map((r) => Math.round(r.left)))],
                page_scroll_x: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                height: Math.round(document.documentElement.scrollHeight),
            };
        }""")
        pg.screenshot(path=f"{OUT}/s23-dashboard-teknisi-mobile-p1d.png", full_page=True)

        # Laci "Atur dasbor" di ponsel: TINGGI KENDALINYA, diukur.
        #
        # app.css menyebut "target 44 px" tepat di atas aturan laci ini sejak
        # P1-D; yang terukur sampai verifikasi kedua adalah 36 px (Naik/Turun/
        # Hapus), 34 px (select ukuran) dan 34 px (tombol kaki) — di atas lantai
        # WCAG 2.5.8 (24 px), tetapi bukan angka yang ditulis komentarnya. Satu
        # komentar yang menjanjikan ukuran yang tidak diberikannya lebih buruk
        # daripada tidak ada.
        pg.click(".page-head .actions button:has-text('Atur dasbor')")
        pg.wait_for_selector(".modal .dash-setup-list", timeout=10000)
        pg.wait_for_timeout(400)
        touch = pg.evaluate("""() => {
            const px = (node) => Math.round(node.getBoundingClientRect().height);
            const rows = [...document.querySelectorAll(".dash-setup-row")];
            return {
                row_buttons: [...new Set(rows.flatMap((r) => [...r.querySelectorAll(".btn")].map(px)))],
                selects: [...new Set(rows.map((r) => r.querySelector("select.dash-setup-size")).filter(Boolean).map(px))],
                foot_buttons: [...new Set([...document.querySelectorAll(".modal-foot .btn")].map(px))],
            };
        }""")
        pg.screenshot(path=f"{OUT}/s23-atur-dasbor-mobile-p1d.png", full_page=True)
        touch["min_px"] = min((touch["row_buttons"] + touch["selects"] + touch["foot_buttons"]) or [0])

        return {
            "cards": [c["id"] for c in cards],
            "grid": geom,
            "drawer_touch_targets": touch,
            # Satu kolom: setiap kartu mulai di tepi kiri yang sama, dan halaman
            # tidak menggulung mendatar.
            "ok": (
                geom["cols"] == 1 and len(geom["lefts"]) == 1 and not geom["page_scroll_x"] and bool(cards)
                # …dan angka yang ditulis app.css benar-benar diberikan.
                and touch["min_px"] >= 44
            ),
        }
    finally:
        ctx.close()



# ------------------------------------------------------------------ S27 PWA

# Berkas worker di pohon kerja — dinaikkan versinya lalu DIPULIHKAN untuk
# mengukur toast "Versi baru siap" (pola yang sama dengan fixture yang ditanam
# S26 di basis data: ditanam, diukur, dicabut di `finally`).
SW_FILE = os.environ.get("ERP_SW", os.path.join(os.path.dirname(os.path.dirname(SPA_EVIDENCE)), "public/app/sw.js"))

SW_FACTS = """async () => {
  const reg = await navigator.serviceWorker.getRegistration();
  const names = await caches.keys();
  let keys = [];
  for (const name of names) keys = keys.concat((await (await caches.open(name)).keys()).map((r) => new URL(r.url).pathname));
  return {
    scope: reg ? reg.scope : null,
    script: reg && reg.active ? reg.active.scriptURL : null,
    state: reg && reg.active ? reg.active.state : null,
    controlled: !!navigator.serviceWorker.controller,
    cache_names: names,
    cached: keys.length,
    cached_api: keys.filter((p) => p.startsWith('/api/')),
    cached_outside_scope: keys.filter((p) => !p.startsWith('/app/')),
  };
}"""

# deliveryType MEMBEDAKAN dua "cache" yang biasanya tertukar (diukur 7 Sep 2026):
# 'cache' = cache HTTP peramban, '' = jaringan, 'cache-storage' = CacheStorage,
# yaitu SATU-SATUNYA yang bisa diisi service worker. Klaim tengah paket ini —
# "0 respons API dari cache SW" — karena itu bisa dibaca, bukan diyakini.
ENTRIES = """() => performance.getEntriesByType('resource').map((e) => ({
  name: e.name, delivery: e.deliveryType, transfer: e.transferSize, worker: Math.round(e.workerStart) }))"""

RIBBON = """() => { const r = document.querySelector('.offline-ribbon');
  return r ? { present: true, hidden: r.hidden, visible: r.checkVisibility(), text: r.innerText.trim() } : { present: false }; }"""


def pwa_facts(pg):
    e = pg.evaluate(ENTRIES)
    api = [x for x in e if "/api/" in x["name"]]
    shell = [x for x in e if "/app/" in x["name"]]
    return {
        "api_entries": len(api),
        "api_from_cache_storage": [x["name"].split("/api/")[1] for x in api if x["delivery"] == "cache-storage"],
        "shell_from_cache_storage": len([x for x in shell if x["delivery"] == "cache-storage"]),
        "shell_entries": len(shell),
    }


def pwa_scenario(pg, tag, mobile=False):
    ctx = pg.context
    out = {"tag": tag}
    seen = []
    pg.on("response", lambda r: seen.append((r.url, r.from_service_worker)))

    login(pg, "teknisi@nusantara.test")
    # Pendaftaran worker sengaja menunggu `load` + satu putaran idle (app.js),
    # jadi yang ditunggu di sini adalah AKIBATNYA, bukan waktu tetap.
    pg.wait_for_function("() => !!navigator.serviceWorker.controller", timeout=30000)
    pg.wait_for_timeout(2500)
    out["worker"] = pg.evaluate(SW_FACTS)

    # DARING: satu berkas cangkang diminta lagi. Ia ADA di CacheStorage, jadi
    # kalau strateginya "cache dulu" jawabannya akan bertanda deliveryType
    # 'cache-storage' dan transferSize 0. Jaringan-dulu berarti keduanya bukan.
    mark = len(seen)
    out["online_shell_fetch"] = pg.evaluate("""async () => {
      const r = await fetch('app.css', { cache: 'no-store' });
      const all = performance.getEntriesByName(new URL('app.css', location.href).href);
      const e = all[all.length - 1];
      return { status: r.status, delivery: e ? e.deliveryType : null, transfer: e ? e.transferSize : null };
    }""")
    # …dan worker MEMANG yang menjawabnya (sisi Playwright, bukan sisi halaman).
    out["online_shell_via_worker"] = any(u.endswith("/app/app.css") and sw for u, sw in seen[mark:])

    pg.evaluate("() => { location.hash = '#/lapangan'; }")
    pg.wait_for_timeout(2500)
    out["ribbon_online"] = pg.evaluate(RIBBON)

    # ---- pita: putus, lalu sambung lagi. TANPA muat ulang di antaranya, karena
    # emulasi luring Playwright hilang saat dokumen baru dibuat: sesudah reload
    # navigator.onLine kembali true meski jaringannya masih terputus (diukur
    # 7 Sep 2026), sehingga peristiwa 'online' yang memadamkan pita tidak pernah
    # menyala. Urutan ini mengukur kontraknya, bukan artefak alatnya.
    ctx.set_offline(True)
    pg.wait_for_timeout(1000)
    out["ribbon_offline"] = pg.evaluate(RIBBON)
    pg.screenshot(path=f"{OUT}/s27-pita-luring{tag}.png", full_page=False)

    # Kalimat pita bergantung pada ISI antrean, dan kedua keadaan diukur di sini.
    # Sampai 7 Sep 2026 pita selalu berbunyi 'tekan "Kirim ulang" pada barisnya'
    # — termasuk pada antrean KOSONG, ketika tidak ada satu pun tombol itu di
    # halaman (terukur: kartu "Foto belum terkirim" tersembunyi, 0 tombol).
    # Satu butir ditanam langsung di localStorage (bentuk yang sama dengan yang
    # ditulis enqueue()) lalu dicabut lagi, karena mengambil foto sungguhan
    # butuh kamera.
    out["retry_buttons_with_empty_queue"] = pg.evaluate(
        "() => [...document.querySelectorAll('button')].filter((b) => b.innerText.trim() === 'Kirim ulang').length")
    # Butirnya ditanam SEKARANG tetapi dibaca sesudah muat ulang luring di bawah:
    # readQueue() menyimpan cache per pengguna dan tidak membaca localStorage lagi
    # selama halaman yang sama hidup (terukur: pita tetap berkalimat antrean-kosong
    # ketika butirnya ditanam di tengah halaman yang sudah berjalan).
    out["queue_seeded"] = pg.evaluate("""() => {
      const id = (JSON.parse(localStorage.getItem('nusantara_erp_user') || 'null') || {}).id || 0;
      const key = 'nusantara_erp_upload:' + id + ':uji-s27';
      localStorage.setItem(key, JSON.stringify({ key: 'uji-s27', userId: id, state: 'failed',
        error: 'Ditanam harness S27.', attempts: 1, savedAt: Date.now(), slug: 'daily-report', id: 1,
        label: 'Laporan harian (uji)', filename: 'uji-s27.jpg', position: null, size: 12,
        content: 'data:image/gif;base64,R0lGODlhAQABAAAAACw=' }));
      return key; }""")

    ctx.set_offline(False)
    pg.wait_for_timeout(1500)
    out["ribbon_back_online"] = pg.evaluate(RIBBON)

    # ---- PORTAL: navigator.onLine berkata true, paketnya tetap tidak sampai.
    # Kasus inilah yang pita ini ada untuk melaporkannya (Wi-Fi lokasi yang
    # halaman login-nya belum dilewati, satu bar 4G di lantai basement), dan ia
    # hanya muncul dalam urutan tertentu: satu kegagalan, lalu satu peristiwa
    # `online`, TANPA satu pun permintaan berhasil di antaranya. Karena itu
    # abort dipasang SEBELUM set_offline(False) dan tidak dilepas sampai
    # pengukurannya selesai — permintaan yang berhasil di sela akan menyetel
    # ulang ingatan api.js dan menyembunyikan cacatnya (terukur 7 Sep 2026:
    # versi pertama syarat ini hijau di atas kode yang RUSAK karena satu
    # permintaan sempat sampai selama jeda 1,5 detik).
    pg.route("**/api/**", lambda route: route.abort("connectionfailed"))
    ctx.set_offline(True)
    pg.evaluate("() => { location.hash = '#/home'; }")
    pg.wait_for_timeout(2200)
    ctx.set_offline(False)          # ← peristiwa `online` menyala di sini
    pg.wait_for_timeout(1200)
    pg.evaluate("() => { location.hash = '#/lapangan'; }")
    pg.wait_for_timeout(2600)
    out["ribbon_captive_portal"] = pg.evaluate(RIBBON)
    out["captive_portal_online_flag"] = pg.evaluate("() => navigator.onLine")
    pg.unroute("**/api/**")
    pg.evaluate("() => { location.hash = '#/home'; }")
    pg.wait_for_timeout(2200)
    pg.evaluate("() => { location.hash = '#/lapangan'; }")
    pg.wait_for_timeout(2600)
    out["ribbon_after_portal_cleared"] = pg.evaluate(RIBBON)

    # ---- muat ulang TANPA jaringan: cangkang harus tetap tergambar…
    ctx.set_offline(True)
    pg.wait_for_timeout(500)
    pg.reload(wait_until="load")
    pg.wait_for_timeout(4500)
    out["offline_shell"] = pg.evaluate("""() => ({
      shell: !!document.querySelector('.shell'),
      nav_items: document.querySelectorAll('nav.nav a').length,
      login_form: !!document.querySelector('input[type=email]'),
      title: document.title,
      html_chars: document.documentElement.outerHTML.length,
      toasts: [...document.querySelectorAll('.toast')].map((t) => t.innerText.replace(/\\n/g, ' / ')),
      ribbon_visible: !!document.querySelector('.offline-ribbon:not([hidden])'),
    })""")

    # …dan permintaan /api/* harus GAGAL, bukan dijawab dari cache.
    out["offline_api"] = pg.evaluate("""async () => {
      try {
        const r = await fetch('/api/core/dashboard/summary', { headers: { 'X-Api-Token': localStorage.getItem('nusantara_erp_token') || '' } });
        return { threw: false, status: r.status };
      } catch (e) { return { threw: true, error: String(e) }; }
    }""")
    # Halaman ini baru, jadi antrean yang ditanam di atas terbaca sekarang: pita
    # yang sama harus berganti kalimat dan menunjuk tombol yang MEMANG ada.
    out["ribbon_offline_with_queue"] = pg.evaluate(RIBBON)
    out["retry_buttons_with_queue"] = pg.evaluate(
        "() => [...document.querySelectorAll('button')].filter((b) => b.innerText.trim() === 'Kirim ulang').length")
    pg.screenshot(path=f"{OUT}/s27-pita-luring-antrean{tag}.png", full_page=False)
    pg.evaluate("(key) => localStorage.removeItem(key)", out["queue_seeded"])

    out["offline_entries"] = pwa_facts(pg)
    pg.screenshot(path=f"{OUT}/s27-luring{tag}.png", full_page=True)
    ctx.set_offline(False)
    pg.wait_for_timeout(800)

    # Toast "Mode luring" harus MEMBETULKAN dirinya sendiri. Sampai 7 Sep 2026 ia
    # dipasang dengan timeout 0 dan tanpa pendengar apa pun: layar sudah memuat
    # data hidup, pita luring sudah padam, dan toast itu masih berbunyi "Tanpa
    # koneksi" sampai orangnya menekan silang — di 390x844 ia menutup 104 px
    # paling bawah layar selamanya.
    #
    # Yang memadamkannya adalah permintaan pertama yang SAMPAI, bukan jam:
    # sesudah muat ulang luring navigator.onLine sudah true lagi (artefak
    # emulasi Playwright, terukur), jadi set_offline(False) tidak menyalakan
    # peristiwa `online` sama sekali. Karena itu di sini dibuka satu layar —
    # yang juga yang dilakukan orangnya. Tanpa itu pun toast padam paling lambat
    # pada polling notifikasi 90 detik.
    pg.evaluate("() => { location.hash = '#/home'; }")
    pg.wait_for_timeout(3500)
    out["toasts_after_reconnect"] = toasts(pg)
    pg.screenshot(path=f"{OUT}/s27-kembali-daring{tag}.png", full_page=False)

    # Bukti kedua, dari sisi Playwright dan bukan dari halaman: tidak satu pun
    # respons /api/ yang datang dari service worker, sepanjang skenario.
    out["api_responses_from_worker"] = sorted({u.split("/api/")[1] for u, sw in seen if "/api/" in u and sw})
    out["api_responses_total"] = len([1 for u, _ in seen if "/api/" in u])

    # ---- "Pasang aplikasi", dilaporkan apa adanya
    out["beforeinstallprompt"] = pg.evaluate("""() => new Promise((res) => {
      let fired = false;
      window.addEventListener('beforeinstallprompt', () => { fired = true; res('menyala'); });
      setTimeout(() => res(fired ? 'menyala' : 'tidak menyala'), 4000);
    })""")
    click(pg, "button.userchip")
    pg.wait_for_selector(".modal", timeout=10000)
    pg.wait_for_timeout(400)
    out["account_dialog_install"] = pg.evaluate("""() => {
      const rows = [...document.querySelectorAll('.modal .modal-body p')]
        .map((n) => (n.innerText || '').trim()).filter((t) => /Pasang aplikasi|sudah terpasang/.test(t));
      return { line: rows.length ? rows[rows.length - 1].slice(0, 200) : null,
               button: [...document.querySelectorAll('.modal button')].some((b) => b.innerText.trim() === 'Pasang aplikasi') };
    }""")
    pg.screenshot(path=f"{OUT}/s27-akun-pasang{tag}.png", full_page=False)
    pg.keyboard.press("Escape")
    pg.wait_for_timeout(400)

    checks = {
        "scope_is_app": out["worker"]["scope"] == ORIGIN + "/app/",
        "script_is_app_sw": (out["worker"]["script"] or "").endswith("/app/sw.js"),
        "page_is_controlled": out["worker"]["controlled"] is True,
        "one_versioned_cache": len(out["worker"]["cache_names"]) == 1 and out["worker"]["cache_names"][0].startswith("nusantara-shell-v"),
        "shell_precached": out["worker"]["cached"] >= 100,
        "zero_api_in_cache": out["worker"]["cached_api"] == [],
        "zero_outside_scope_in_cache": out["worker"]["cached_outside_scope"] == [],
        "online_shell_from_network": (out["online_shell_fetch"]["status"] == 200
                                      and out["online_shell_fetch"]["delivery"] != "cache-storage"
                                      and (out["online_shell_fetch"]["transfer"] or 0) > 0),
        "online_shell_answered_by_worker": out["online_shell_via_worker"] is True,
        "ribbon_hidden_online": out["ribbon_online"]["present"] and out["ribbon_online"]["hidden"] is True,
        "ribbon_shown_offline": out["ribbon_offline"]["visible"] is True and "Tanpa koneksi" in out["ribbon_offline"]["text"],
        "empty_queue_ribbon_names_no_button": ("Kirim ulang" not in out["ribbon_offline"]["text"]
                                               and out["retry_buttons_with_empty_queue"] == 0),
        "queued_photo_ribbon_points_at_the_button": ('"Kirim ulang" pada barisnya' in out["ribbon_offline_with_queue"]["text"]
                                                     and out["retry_buttons_with_queue"] >= 1),
        "ribbon_hidden_again": out["ribbon_back_online"]["hidden"] is True,
        "ribbon_shown_behind_captive_portal": (out["captive_portal_online_flag"] is True
                                               and out["ribbon_captive_portal"]["visible"] is True),
        "ribbon_hidden_when_requests_arrive_again": out["ribbon_after_portal_cleared"]["hidden"] is True,
        "offline_shell_renders": out["offline_shell"]["shell"] and out["offline_shell"]["nav_items"] > 0 and not out["offline_shell"]["login_form"],
        "offline_ribbon_survives_reload": out["offline_shell"]["ribbon_visible"] is True,
        "offline_boot_toast_shown": any("Mode luring" in t for t in out["offline_shell"]["toasts"]),
        "offline_boot_toast_clears_itself": not any("Mode luring" in t for t in out["toasts_after_reconnect"]),
        "reconnect_says_the_session_was_refreshed": any("Kembali daring" in t for t in out["toasts_after_reconnect"]),
        "offline_api_failed": out["offline_api"]["threw"] is True,
        "offline_shell_came_from_cache_storage": out["offline_entries"]["shell_from_cache_storage"] > 0,
        "zero_api_from_cache_storage": out["offline_entries"]["api_from_cache_storage"] == [],
        "zero_api_responses_from_worker": out["api_responses_from_worker"] == [],
        "install_row_present": bool(out["account_dialog_install"]["line"]),
    }
    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S27_pwa")
def s27(pg):
    return pwa_scenario(pg, "-p1i")


@scenario("S27_pwa_mobile")
def s27m(browser):
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        return pwa_scenario(pg, "-mobile-p1i", mobile=True)
    finally:
        ctx.close()


@scenario("S27_pwa_pembaruan")
def s27u(pg):
    """Toast "Versi baru siap" — dan SATU muat ulang, tidak pernah dua.

    SHELL_VERSION dinaikkan DI BERKASNYA lalu dipulihkan di `finally`: peramban
    membandingkan BYTE sw.js, jadi tidak ada cara lain memunculkan worker yang
    menunggu selain benar-benar mengubah berkasnya."""
    if not os.path.exists(SW_FILE):
        return {"SKIPPED": f"sw.js tidak ada di {SW_FILE} (setel ERP_SW)"}

    original = open(SW_FILE, encoding="utf-8").read()
    m = re.search(r"const SHELL_VERSION = '([^']+)';", original)
    if not m:
        return {"SKIPPED": "SHELL_VERSION tidak ditemukan di sw.js"}
    bumped = original.replace(m.group(0), f"const SHELL_VERSION = '{m.group(1)}-uji';", 1)

    out = {"version_before": m.group(1)}
    navigations = []
    pg.on("framenavigated", lambda f: navigations.append(f.url) if f == pg.main_frame else None)

    try:
        login(pg, "teknisi@nusantara.test")
        pg.wait_for_function("() => !!navigator.serviceWorker.controller", timeout=30000)
        pg.wait_for_timeout(2000)
        out["cache_before"] = pg.evaluate("async () => await caches.keys()")
        out["toasts_before"] = toasts(pg)

        open(SW_FILE, "w", encoding="utf-8").write(bumped)
        pg.evaluate("async () => { const r = await navigator.serviceWorker.getRegistration(); await r.update(); }")
        pg.wait_for_selector(".toast:has-text('Versi baru siap')", timeout=25000)
        # Toast masuk dengan animasi; tangkapan layar tanpa jeda ini memotret
        # separuh transisinya (terukur 7 Sep 2026: teks tembus pandang di atas
        # kartu di belakangnya).
        pg.wait_for_timeout(700)
        out["toast"] = toasts(pg)
        out["waiting_worker"] = pg.evaluate("async () => { const r = await navigator.serviceWorker.getRegistration(); return !!(r && r.waiting); }")
        pg.screenshot(path=f"{OUT}/s27-toast-versi-baru-p1i.png", full_page=False)

        # RILIS KEDUA di tab yang sama, tanpa ada yang menekan apa pun. Sebuah
        # tablet lapangan yang tidak pernah ditutup melihat setiap rilis; sampai
        # 7 Sep 2026 setiap rilis menambah SATU toast permanen berbunyi persis
        # sama (terukur: dua toast identik sesudah rilis ketiga, 88 px masing-
        # masing di atas hosting toast yang sudah menutup 104 px dasar layar).
        open(SW_FILE, "w", encoding="utf-8").write(
            original.replace(m.group(0), f"const SHELL_VERSION = '{m.group(1)}-uji2';", 1))
        pg.evaluate("async () => { const r = await navigator.serviceWorker.getRegistration(); await r.update(); }")
        pg.wait_for_timeout(4000)
        out["toasts_after_second_release"] = toasts(pg)
        pg.screenshot(path=f"{OUT}/s27-toast-rilis-kedua-p1i.png", full_page=False)

        navigations.clear()
        click(pg, ".toast button:has-text('Muat ulang')")
        # Cukup lama untuk memergoki muat ulang KEDUA kalau ada.
        pg.wait_for_timeout(9000)
        out["navigations_after_click"] = len(navigations)
        out["cache_after"] = pg.evaluate("async () => await caches.keys()")
        out["toasts_after"] = toasts(pg)
        out["controlled_after"] = pg.evaluate("() => !!navigator.serviceWorker.controller")
    finally:
        open(SW_FILE, "w", encoding="utf-8").write(original)

    checks = {
        "no_toast_on_first_install": not any("Versi baru siap" in t for t in out["toasts_before"]),
        "toast_says_the_sentence": any("Versi baru siap — Muat ulang" in t for t in out.get("toast", [])),
        "toast_has_reload_button": any("Muat ulang" in t for t in out.get("toast", [])),
        "a_worker_was_waiting": out.get("waiting_worker") is True,
        "a_second_release_does_not_stack_a_second_toast":
            len([t for t in out.get("toasts_after_second_release", []) if "Versi baru siap" in t]) == 1,
        "reloaded_exactly_once": out.get("navigations_after_click") == 1,
        "old_cache_deleted": len(out.get("cache_after", [])) == 1 and out.get("cache_after") != out.get("cache_before"),
        "still_controlled": out.get("controlled_after") is True,
        "no_toast_left_over": not any("Versi baru siap" in t for t in out.get("toasts_after", [])),
        "sw_file_restored": open(SW_FILE, encoding="utf-8").read() == original,
    }
    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


INSTALL_ROW = """() => {
  const rows = [...document.querySelectorAll('.modal .modal-body p')]
    .map((n) => (n.innerText || '').trim())
    .filter((t) => /Pasang aplikasi|sudah terpasang|Tawaran pemasangan/.test(t));
  return { line: rows.length ? rows[rows.length - 1] : null,
           button: [...document.querySelectorAll('.modal button')].some((b) => b.innerText.trim() === 'Pasang aplikasi'),
           button_disabled: [...document.querySelectorAll('.modal button')]
             .filter((b) => b.innerText.trim() === 'Pasang aplikasi').map((b) => b.disabled) }; }"""

# beforeinstallprompt TIDAK menyala di Chromium headless (diukur ulang 7 Sep 2026:
# "tidak menyala" sesudah 4 detik), jadi peristiwanya dikirim sendiri dengan
# prompt()/userChoice palsu. Yang diuji tetap kode yang dikirim: preventDefault,
# penangkapan, render tombol, pemanggilan prompt(), dan — yang jadi cacatnya —
# kalimat yang dibaca orangnya SESUDAH tawaran itu dipakai.
FIRE_PROMPT = """() => {
  window.__promptCalls = 0;
  window.__choice = 'dismissed';
  const event = new Event('beforeinstallprompt', { cancelable: true });
  event.prompt = () => { window.__promptCalls += 1; return Promise.resolve(); };
  event.userChoice = Promise.resolve({ outcome: 'dismissed', platform: '' });
  window.dispatchEvent(event);
  return event.defaultPrevented; }"""


def open_account(pg):
    click(pg, "button.userchip")
    pg.wait_for_selector(".modal", timeout=10000)
    pg.wait_for_timeout(400)


@scenario("S27_pwa_pasang")
def s27p(pg):
    """Dialog Akun: keempat keadaan baris "Pasang aplikasi", termasuk yang belum
    pernah dijalankan siapa pun — apa yang dibaca orangnya SESUDAH ia menekan
    tombolnya. Sampai 7 Sep 2026 jawabannya "Peramban ini belum menawarkannya
    dari dalam halaman", kepada orang yang baru saja menawarkannya."""
    out = {}
    login(pg, "teknisi@nusantara.test")

    open_account(pg)
    out["state_never_offered"] = pg.evaluate(INSTALL_ROW)
    pg.keyboard.press("Escape")
    pg.wait_for_timeout(400)

    out["prevent_default"] = pg.evaluate(FIRE_PROMPT)
    open_account(pg)
    out["state_offered"] = pg.evaluate(INSTALL_ROW)
    pg.screenshot(path=f"{OUT}/s27-pasang-tombol-p1i.png", full_page=False)
    click(pg, ".modal button:has-text('Pasang aplikasi')")
    pg.wait_for_timeout(800)
    out["prompt_calls"] = pg.evaluate("() => window.__promptCalls")
    out["state_while_open"] = pg.evaluate(INSTALL_ROW)
    pg.keyboard.press("Escape")
    pg.wait_for_timeout(400)

    open_account(pg)
    out["state_after_use"] = pg.evaluate(INSTALL_ROW)
    pg.screenshot(path=f"{OUT}/s27-pasang-sesudah-dipakai-p1i.png", full_page=False)
    pg.keyboard.press("Escape")
    pg.wait_for_timeout(400)

    pg.evaluate("() => window.dispatchEvent(new Event('appinstalled'))")
    open_account(pg)
    out["state_installed"] = pg.evaluate(INSTALL_ROW)
    pg.screenshot(path=f"{OUT}/s27-pasang-terpasang-p1i.png", full_page=False)
    pg.keyboard.press("Escape")

    never = out["state_never_offered"]["line"] or ""
    used = out["state_after_use"]["line"] or ""
    checks = {
        "never_offered_names_both_ways_in": "Instal aplikasi" in never and "Tambahkan ke Layar Utama" in never,
        "never_offered_says_so": "belum menawarkannya" in never,
        "the_event_is_captured": out["prevent_default"] is True,
        "the_offer_becomes_a_button": out["state_offered"]["button"] is True,
        "pressing_it_calls_prompt_once": out["prompt_calls"] == 1,
        "the_button_locks_itself": out["state_while_open"]["button_disabled"] == [True],
        "after_use_it_does_not_claim_it_was_never_offered": "belum menawarkannya" not in used,
        "after_use_it_says_the_offer_was_spent": "Tawaran pemasangan sudah dipakai" in used,
        "after_use_it_names_the_way_back": "Muat ulang halaman" in used,
        "appinstalled_flips_the_row": "sudah terpasang" in (out["state_installed"]["line"] or ""),
    }
    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


BOOT_ROOT = """() => ({ spinner: !!document.querySelector('.boot-spinner'),
  shell: !!document.querySelector('.shell'),
  failed_panel: !!document.querySelector('#root.boot-failed'),
  head: (document.querySelector('#root.boot-failed h1') || {}).textContent || null,
  body: (document.querySelector('#root.boot-failed p') || {}).textContent || null,
  button: (document.querySelector('#root.boot-failed button') || {}).textContent || null })"""


@scenario("S27_pwa_cangkang_sebagian")
def s27k(browser):
    """Cangkang yang tidak lengkap: kuota penuh, dan berkas yang tidak sampai.

    Dua keadaan yang tidak bisa dibuktikan uji PHP mana pun, karena keduanya
    hanya ada di dalam peramban:

    (A) KUOTA. Kuota origin dibatasi 1,2 MB lewat CDP (cangkangnya ~2,1 MB),
        jadi install-nya sebagian. Sampai 7 Sep 2026 hasilnya: 42 dari 102 entri
        masuk, worker aktif, DARING baik-baik saja — lalu muat ulang tanpa
        jaringan berhenti selamanya di pemutar boot (body kosong, 1.532 char).
        Sekarang cache yang tidak lengkap dibuang, jadi perangkatnya turun ke
        "tidak punya lapisan luring", bukan ke aplikasi yang membeku.

    (B) PENGAWAS BOOT. Satu modul cangkang digugurkan (persis rilis yang
        kehilangan berkas — 7 Sep 2026 satu 404 menggantung seluruh SPA):
        index.html harus mengganti pemutar dengan kalimat, dan kalimatnya
        berbeda ketika perangkatnya luring."""
    out = {}
    decide_onboarding("teknisi@nusantara.test")

    # ---- (A0) KENDALI. Konteks yang sama TANPA batas kuota, tanpa masuk —
    # pendaftaran worker tidak menunggu sesi. Tanpa baris ini "0 entri" tidak
    # membuktikan apa pun: navigator.storage.estimate() TIDAK melaporkan kuota
    # yang ditimpa CDP (terukur 7 Sep 2026: tetap 4,3 GB sementara install-nya
    # nyata-nyata terpotong), jadi yang membuktikan batasnya bekerja adalah
    # selisih dengan jalan kendali ini.
    ctx = browser.new_context(viewport={"width": 1440, "height": 900})
    pg = ctx.new_page()
    try:
        pg.goto(BASE)
        pg.wait_for_function("() => !!navigator.serviceWorker.controller", timeout=40000)
        pg.wait_for_timeout(8000)
        out["control_cache"] = pg.evaluate(SW_FACTS)
    finally:
        ctx.close()

    # ---------------------------------------------------------------- (A)
    ctx = browser.new_context(viewport={"width": 1440, "height": 900})
    pg = ctx.new_page()
    try:
        cdp = ctx.new_cdp_session(pg)
        cdp.send("Storage.overrideQuotaForOrigin", {"origin": ORIGIN, "quotaSize": 1_200_000})
        login(pg, "teknisi@nusantara.test")
        pg.wait_for_function("() => !!navigator.serviceWorker.controller", timeout=40000)
        pg.wait_for_timeout(8000)   # 102 berkas dicoba satu per satu
        out["cache_after_install"] = pg.evaluate(SW_FACTS)
        pg.evaluate("() => { location.hash = '#/lapangan'; }")
        pg.wait_for_timeout(2500)
        out["online_after_quota"] = pg.evaluate("() => ((document.querySelector('.page-head h1') || {}).innerText || null)")
        ctx.set_offline(True)
        try:
            pg.reload(wait_until="commit")
            pg.wait_for_timeout(6000)
            out["offline_reload"] = pg.evaluate(BOOT_ROOT)
        except Exception as error:
            # Tanpa index.html di cache peramban menggambar halaman galatnya
            # sendiri: itulah "tidak punya lapisan luring", dan itu jujur.
            out["offline_reload"] = {"navigation_error": str(error).splitlines()[0][:120], "spinner": False}
    finally:
        ctx.close()

    # ---------------------------------------------------------------- (B)
    for label, offline in (("daring", False), ("luring", True)):
        ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
        pg = ctx.new_page()
        try:
            pg.route("**/app/js/ui.js", lambda route: route.abort("connectionfailed"))
            if offline:
                pg.add_init_script("Object.defineProperty(navigator, 'onLine', { get: () => false });")
            pg.goto(BASE, wait_until="commit")
            pg.wait_for_timeout(4000)
            out[f"boot_{label}"] = pg.evaluate(BOOT_ROOT)
            pg.screenshot(path=f"{OUT}/s27-boot-gagal-{label}-p1i.png", full_page=False)
        finally:
            ctx.close()

    checks = {
        "control_installs_the_whole_shell": out["control_cache"]["cached"] >= 100,
        "quota_override_took": out["cache_after_install"]["cached"] < out["control_cache"]["cached"],
        # Aturan sejak verifikasi ulang P1-I (7 Sep 2026): cache dibuang HANYA bila
        # berkas INTI yang hilang. Kuota yang habis di tengah pemasangan biasanya
        # menyisakan inti yang lengkap dan kehilangan layar-layar yang dimuat malas —
        # dan cangkang sebagian yang JUJUR (panel pengawas menjelaskan keadaannya,
        # dengan tombol Muat ulang) lebih berguna daripada tidak ada cangkang sama
        # sekali, yang luring hanya memberi halaman galat bawaan peramban. Yang
        # dijaga bukan lagi "dibuang", melainkan "tidak pernah menggantung".
        "half_shell_keeps_its_core_or_is_discarded": (
            out["cache_after_install"]["cached"] == 0
            or out["cache_after_install"]["cached"] >= 8),
        "half_shell_never_holds_api_or_foreign_entries": (
            out["cache_after_install"]["cached_api"] == []
            and out["cache_after_install"]["cached_outside_scope"] == []),
        "app_still_works_online": out["online_after_quota"] == "Lapangan",
        "offline_never_hangs_on_the_spinner": out["offline_reload"].get("spinner") is not True,
        "watchdog_replaces_the_spinner": (out["boot_daring"]["failed_panel"] is True
                                          and out["boot_daring"]["spinner"] is False),
        "watchdog_says_what_to_do": out["boot_daring"]["button"] == "Muat ulang" and "Muat ulang halaman" in (out["boot_daring"]["body"] or ""),
        "watchdog_knows_it_is_offline": "tanpa koneksi" in (out["boot_luring"]["head"] or ""),
        "offline_sentence_names_the_photo_queue": "antrean" in (out["boot_luring"]["body"] or ""),
    }
    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


# ------------------------------------------------------- S28 (Fase 2 / F-1)
#
# MATRIKS PERSETUJUAN + DELEGASI "a.n." + SETUJUI MASSAL.
#
# Yang dibuktikan di sini bukan "layarnya tergambar" melainkan empat kalimat
# yang hanya bisa dijawab peramban sungguhan:
#
#   1. matriks membawa NILAI HARI INI  — PO Rp 100 juta, SPK Rp 200 juta,
#      award berjenjang, addendum mengikuti SPK, dan tidak ada satu pun
#      "Rp 0" di 28 barisnya (ambang nol berarti setiap dokumen menuntut
#      direktur — kebalikan persis keadaannya);
#   2. mengubahnya butuh DUA izin — core.update saja ditolak dengan kalimat
#      yang menyebut *.approve-director;
#   3. delegasi terlihat SEBELUM menekan Setujui (spanduk), dan jejaknya
#      berbunyi "Budi a.n. Sari" sesudahnya;
#   4. setujui massal TIDAK ADA selama plafonnya kosong;
#   5. (putaran kedua) delegatnya SAMPAI ke pekerjaannya tanpa mengetik URL —
#      "Tugas Saya" ada di bilah sampingnya, tombol Setujui ada di layar
#      dokumennya, tombol itu menyebut hak siapa yang dipinjamnya, dan
#      menekannya benar-benar menyetujui "a.n." pemberinya;
#   6. dan TIDAK LEBIH: `fin.approve` yang sama menggerbangi "Posting Jurnal",
#      yang bukan pintu keputusan dokumen — pemegang aslinya melihatnya,
#      delegatnya tidak. Sebuah gerbang klien yang meleburkan hak pinjaman ke
#      dalam user.permissions akan menggambar tombol yang dijawab 403.
#
# Skenario ini MENULIS ke basis datanya (setelan, delegasi, persetujuan), jadi
# ia dijalankan atas salinan coretan seperti seluruh harness — dan ia
# mengembalikan plafon ke kosong di awal, bukan di akhir: sebuah pengulangan
# yang mewarisi plafon dari jalannya sendiri akan menghijaukan syarat "fitur
# mati secara bawaan" tanpa fakta apa pun di belakangnya.

MATRIX_CARD = """() => {
  const card = [...document.querySelectorAll('.card')].find(c => /Matriks Persetujuan/.test(c.innerText));
  if (!card) return { found: false, cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText) };
  const rows = [...card.querySelectorAll('tbody tr')];
  const cells = (re) => { const r = rows.find(r => re.test(r.innerText)); return r ? [...r.children].map(td => td.innerText.replace(/\\s+/g, ' ').trim()) : null; };
  return {
    found: true,
    head: card.querySelector('h2').innerText,
    count_label: card.querySelector('.card-head span.muted').innerText,
    rows: rows.length,
    editable_cells: card.querySelectorAll('tbody input, tbody select').length,
    // Putaran verifikasi F-1: sel MODE hanya boleh ada di baris yang
    // persetujuannya boleh berhenti di tengah (keputusan pemenang). Pada
    // sebelas baris lain ia memposting jurnal dua kali.
    mode_cells: card.querySelectorAll('tbody select').length,
    // Kolom kedua saja: kolom keempat (ambang tingkat ketiga) juga sebuah
    // input, dan menghitung keduanya bersama menjawab pertanyaan lain.
    threshold_cells: rows.filter(r => r.children[1] && r.children[1].querySelector('input')).length,
    headers: [...card.querySelectorAll('thead th')].map(th => th.innerText),
    po: cells(/Pesanan pembelian/),
    spk: cells(/SPK subkontraktor/),
    addendum: cells(/Addendum SPK/),
    award: cells(/Keputusan pemenang/),
    izin_kerja: cells(/Izin kerja lapangan/),
    cuti: cells(/Pengajuan cuti/),
    zero_rows: rows.filter(r => /Rp\\s*0(\\D|$)/.test(r.innerText)).map(r => r.innerText.split('\\n')[0]),
    dash_rows: rows.filter(r => /Tanpa nilai rupiah/.test(r.innerText)).length,
    follows_rows: rows.filter(r => /Mengikuti /.test(r.innerText)).length,
  };
}"""

INBOX_BULK = """() => {
  // Tabel kotak masuk dikenali dari kolomnya sendiri ("Menunggu"), bukan dari
  // kata pertama yang kebetulan ada di halaman: kartu Delegasi Persetujuan di
  // layar yang sama juga sebuah table.data.
  const table = [...document.querySelectorAll('table.data')]
    // /i wajib: app.css memberi th text-transform uppercase, dan innerText
    // mengembalikan teks TERGAMBAR — "MENUNGGU", bukan "Menunggu".
    .find(t => [...t.querySelectorAll('thead th')].some(th => /menunggu/i.test(th.innerText)));
  const boxes = table ? [...table.querySelectorAll('tbody input[type=checkbox]')] : [];
  return {
    rows: table ? table.querySelectorAll('tbody tr').length : 0,
    checkboxes: boxes.length,
    disabled: boxes.filter(b => b.disabled).length,
    bulk_button: [...document.querySelectorAll('button')].some(b => /Setujui terpilih/.test(b.innerText)),
    bulk_text: ([...document.querySelectorAll('.filters')].find(f => /maksimum|dipilih/i.test(f.innerText)) || {}).innerText || null,
  };
}"""

DELEGATION_BANNER = """() => (([...document.querySelectorAll('.alert')].find(a => /delegasi/i.test(a.innerText)) || {}).innerText || null)"""

# Grup "Ringkasan" di bilah samping. Putaran kedua verifikasi F-1: seorang
# delegat murni tidak melihat "Tugas Saya" di sini, dan S28 tidak dapat
# melihatnya karena ia membuka layarnya dengan mengetik #/tugas — jalan yang
# tidak dimiliki pemakainya.
NAV_RINGKASAN = """() => [...document.querySelectorAll('nav.nav a')].map(a => a.innerText.trim())
  .filter(t => ['Beranda','Dasbor','Tugas Saya','Tenggat','Kalender','Laporan Bebas'].includes(t))"""

# Tombol aksi sebuah layar dokumen, DENGAN title-nya: "a.n." pada tombol Setujui
# adalah satu-satunya tempat seorang delegat diberi tahu hak siapa yang sedang
# dipakainya sebelum ia menekannya (layar dokumen tidak punya spanduk).
ACTION_BUTTONS = """() => [...document.querySelectorAll('.page-head .actions button, .card .actions button, main button.btn')]
  .map(b => ({ label: b.innerText.trim(), title: b.title || null })).filter(x => x.label)"""

TRAIL = """() => {
  const card = [...document.querySelectorAll('.card')].find(c => /Riwayat|Persetujuan/.test((c.querySelector('h2') || {}).innerText || ''));
  const items = [...document.querySelectorAll('.timeline-item')].map(i => i.innerText.replace(/\\s+/g, ' ').trim());
  return { items, card: !!card };
}"""


def s28_set_settings(tok, values):
    return api("core/settings", tok, "PUT", {"settings": values})


@scenario("S28_matriks_persetujuan")
def s28(pg):
    out = {}
    admin = token_for("admin@nusantara.test")

    # (0) Plafon setujui massal dikosongkan DI AWAL — lihat catatan di atas.
    s28_set_settings(admin, {"approvals.batch_cap": None})

    # (0b) SEBUAH JURNAL DRAF, dan ia adalah alat ukur, bukan hiasan.
    #      "Posting Jurnal" digerbangi fin.approve — izin yang SAMA dengan yang
    #      dipinjamkan delegasi — tetapi ia BUKAN pintu keputusan dokumen
    #      (path {id}/post, bukan {id}/approve). Server hanya menghormati hak
    #      pinjaman pada …/{id}/approve|reject, jadi tombol ini harus TETAP
    #      hilang bagi seorang delegat. Ia mengukur bahwa perbaikan "delegat
    #      akhirnya melihat tombol Setujui" tidak sekalian membuka lima belas
    #      pintu lain yang izinnya kebetulan sama — di antaranya memposting
    #      jurnal manual dan membuka kembali periode fiskal.
    fin_token = token_for("finance@nusantara.test")
    status, accounts = api("finance/accounts?per_page=200&is_postable=1", fin_token)
    acc = [a["id"] for a in accounts.get("data", [])][:2]
    status, jv = api("finance/journals", fin_token, "POST", {
        "journal_date": date.today().isoformat(),
        "description": "Fixture S28 — tombol non-keputusan bergerbang fin.approve",
        "lines": [
            {"account_id": acc[0], "description": "d", "debit": 1000, "credit": 0},
            {"account_id": acc[1], "description": "k", "debit": 0, "credit": 1000},
        ],
    })
    jv_id = (jv.get("data") or {}).get("id")
    out["draft_jv"] = {"status": status, "code": (jv.get("data") or {}).get("code")}

    # (1) Matriks membawa nilai hari ini.
    login(pg, "admin@nusantara.test")
    pg.goto(BASE + "#/settings")
    pg.wait_for_timeout(3500)
    out["matrix"] = pg.evaluate(MATRIX_CARD)
    pg.screenshot(path=f"{OUT}/s28-matriks-persetujuan-f1.png", full_page=True)

    # (2) Mengubah aturan persetujuan butuh DUA izin.
    #     Perannya dibuat di sini dan bukan diasumsikan ada: tidak satu pun
    #     login demo memegang core.update TANPA izin direktur, dan justru
    #     kombinasi itu yang harus ditolak.
    out["role_created"], role = api("iam/roles", admin, "POST", {
        "name": "uji-f1-core-update", "permissions": ["core.view", "core.update"]})
    out["user_created"], user = api("iam/users", admin, "POST", {
        "name": "Penyunting Tanpa Direktur", "email": "f1-editor@nusantara.test",
        "password": "password", "is_active": True, "roles": ["uji-f1-core-update"]})
    editor = token_for("f1-editor@nusantara.test")
    status, refused = s28_set_settings(editor, {"approvals.purchase_order.threshold_two_level": 5000000000})
    out["edit_without_director"] = {
        "status": status,
        "message": json.dumps(refused.get("errors") or refused.get("message"), ensure_ascii=False)[:400],
    }
    status, allowed = s28_set_settings(admin, {"approvals.purchase_order.threshold_two_level": 250000000})
    out["edit_with_both"] = {"status": status, "message": allowed.get("message")}
    # …dan dikembalikan: skenario ini adalah bukti, bukan perubahan kebijakan.
    s28_set_settings(admin, {"approvals.purchase_order.threshold_two_level": None})
    status, back = api("core/settings", admin)
    out["po_threshold_after_reset"] = next(
        (row["value"] for group in back["data"]["groups"] for row in group["settings"]
         if row["key"] == "approvals.purchase_order.threshold_two_level"), None)

    # (3) Setujui massal TIDAK ADA selama plafonnya kosong — diukur pada
    #     antrean yang BERISI. Diukur pada kotak masuk kosong, syarat ini
    #     hijau tanpa fakta apa pun di belakangnya (admin sendiri mengajukan
    #     hampir seluruh dataset demo, jadi antreannya nol).
    pg.context.clear_cookies()
    pg.goto(BASE)
    pg.evaluate("() => localStorage.clear()")
    login(pg, "direktur@nusantara.test")
    pg.goto(BASE + "#/tugas")
    pg.wait_for_timeout(2500)
    out["bulk_off"] = pg.evaluate(INBOX_BULK)
    # …dan pembanding untuk (6c): pemegang fin.approve ASLINYA melihat tombol
    # non-keputusan itu. Tanpa baris ini, "delegat tidak melihatnya" bisa saja
    # berarti tombolnya memang tidak pernah ada.
    pg.goto(BASE + f"#/d/finance/journals/{jv_id}")
    pg.wait_for_timeout(2500)
    out["jv_buttons_native"] = pg.evaluate(ACTION_BUTTONS)

    # (4) Delegasi: admin menyerahkan haknya kepada login finance bulan ini.
    status, users = api("iam/users?per_page=200", admin)
    finance = next(u for u in users["data"] if u["email"].startswith("finance@"))
    status, delegation = api("core/approval-delegations", admin, "POST", {
        "delegate_user_id": finance["id"],
        "scope": None,
        "starts_at": date.today().replace(day=1).isoformat(),
        "ends_at": (date.today() + timedelta(days=20)).isoformat(),
        "reason": "Cuti tahunan (fixture S28)",
    })
    out["delegation_created"] = {"status": status, "state": delegation.get("data", {}).get("state")}

    # (5) Pemilik mengisi plafonnya.
    s28_set_settings(admin, {"approvals.batch_cap": 5})

    pg.context.clear_cookies()
    pg.goto(BASE)
    pg.evaluate("() => localStorage.clear()")
    login(pg, "finance@nusantara.test")
    # BILAH SAMPINGNYA DULU, sebelum satu URL pun diketik. Skenario ini membuka
    # #/tugas dengan pg.goto, dan sampai putaran kedua verifikasi F-1 itulah
    # satu-satunya cara seorang delegat bisa sampai ke sana: gerbangnya
    # (schema.js ANY_APPROVE) membaca user.permissions dari auth/me, yang tidak
    # pernah memuat hak pinjaman. Diukur 7 Sep 2026 pada login yang sama:
    # ['Beranda','Dasbor','Tenggat','Kalender','Laporan Bebas'] — tanpa "Tugas
    # Saya", sementara kotak masuk di baliknya berisi dua baris.
    out["delegate_nav"] = pg.evaluate(NAV_RINGKASAN)
    pg.goto(BASE + "#/tugas")
    pg.wait_for_timeout(3000)
    out["delegate_banner"] = pg.evaluate(DELEGATION_BANNER)
    out["bulk_on"] = pg.evaluate(INBOX_BULK)
    pg.screenshot(path=f"{OUT}/s28-spanduk-delegasi-f1.png", full_page=True)

    # (6) Dua dokumen dipilih dan disetujui lewat endpoint modulnya
    #     masing-masing. Barisnya DICATAT sebelum diklik: sesudah disetujui
    #     mereka keluar dari kotak masuk, dan menebak tautannya kembali
    #     berarti menebak.
    finance_token = token_for("finance@nusantara.test")
    status, trail_source = api("core/inbox", finance_token)
    # Putaran verifikasi F-1: antrean seorang delegat tidak boleh memuat
    # pengajuan pemberinya — F-1 yang dikirim menawarkan 2 dari 4 baris yang
    # dijamin menjawab 422, lengkap dengan kotak centang.
    out["delegate_queue_submitters"] = sorted({
        (r.get("submitted_by") or "—") for r in (trail_source.get("data") or [])})
    out["delegate_queue_submitter_ids"] = sorted({
        r.get("submitted_by_id") for r in (trail_source.get("data") or [])}, key=lambda v: (v is None, v))
    # SETIAP baris yang ditawarkan dipilih, bukan dua yang pertama. Sebelum
    # putaran verifikasi F-1 antrean seorang delegat memuat baris yang dijamin
    # menjawab 422 (pengajuan pemberinya), dan memilih dua yang pertama —
    # kebetulan dua yang berhasil — menghijaukan skenario ini di atas antrean
    # yang separuhnya tidak dapat disetujui. Memilih semuanya berarti sebuah
    # baris yang tidak dapat disetujui MEMBUAT skenario ini merah.
    boxes = pg.query_selector_all("table.data tbody input[type=checkbox]:not([disabled])")
    picked = []
    for box in boxes[:5]:
        row = box.evaluate_handle("b => b.closest('tr')")
        # Kode dokumennya, bukan baris innerText-nya: sejak kolom kotak
        # centang ada, baris innerText dimulai dengan sel kosong.
        picked.append(row.evaluate("r => (r.querySelector('.cell-main') || {}).innerText || null"))
        box.click()
    pg.wait_for_timeout(400)
    out["selected"] = {"codes": picked, "bar": pg.evaluate(INBOX_BULK)["bulk_text"]}

    if picked:
        click(pg, "button:has-text('Setujui terpilih')")
        # Toast-nya hidup 6 detik. Ditunggu SAMPAI MUNCUL lalu dibaca segera —
        # sebuah sleep tetap yang lebih panjang membaca layar yang toast-nya
        # sudah lewat dan mencatat "tidak ada laporan" untuk laporan yang ada.
        pg.wait_for_function(
            "() => [...document.querySelectorAll('.toast')].some(t => /disetujui|tidak disetujui/i.test(t.innerText))",
            timeout=20000)
        out["bulk_toasts"] = toasts(pg)
        pg.wait_for_timeout(2500)
        out["after_bulk"] = pg.evaluate(INBOX_BULK)

    # (6b) DAN SATU DOKUMEN DISETUJUI DARI LAYARNYA SENDIRI, oleh delegat, dengan
    #      tombol Setujui yang sampai putaran kedua verifikasi F-1 tidak pernah
    #      digambar untuknya (diukur 7 Sep 2026 pada CTI/2026/VIII/0002: delegat
    #      melihat ['Cetak'], admin melihat ['Cetak','Setujui','Tolak']).
    #      Dokumennya BARU dan diajukan login hr — bukan salah satu dari dua
    #      baris di atas: keduanya sudah disetujui massal, dan bukan pula
    #      pengajuan pemberinya, yang memang tidak boleh disetujuinya.
    hr_token = token_for("hr@nusantara.test")
    status, leave = api("hr/leave-requests", hr_token, "POST", {
        "employee_id": 5, "leave_type": "tahunan",
        "start_date": (date.today() + timedelta(days=30)).isoformat(),
        "end_date": (date.today() + timedelta(days=31)).isoformat(),
        "reason": "Fixture S28 — delegat menekan Setujui sendiri.",
    })
    leave_id = (leave.get("data") or {}).get("id")
    api(f"hr/leave-requests/{leave_id}/submit", hr_token, "POST", {})
    out["delegate_press"] = {"code": (leave.get("data") or {}).get("code")}

    pg.goto(BASE + f"#/d/hr/leave-requests/{leave_id}")
    pg.wait_for_timeout(3000)
    out["delegate_press"]["buttons"] = pg.evaluate(ACTION_BUTTONS)
    if any(b["label"] == "Setujui" for b in out["delegate_press"]["buttons"]):
        click(pg, "button:has-text('Setujui')")
        pg.wait_for_timeout(3000)
    status, after = api(f"hr/leave-requests/{leave_id}", finance_token)
    entries = (after.get("data") or {}).get("approvals") or []
    approved = next((e for e in entries if e.get("action") == "approved"), None)
    out["delegate_press"]["status_after"] = (after.get("data") or {}).get("status")
    out["delegate_press"]["trail"] = None if approved is None else {
        "actor": (approved.get("user") or {}).get("name"),
        "on_behalf_of": (approved.get("on_behalf_of") or {}).get("name"),
    }

    # (6c) …dan tombol non-keputusan yang izinnya SAMA tetap tidak ada.
    pg.goto(BASE + f"#/d/finance/journals/{jv_id}")
    pg.wait_for_timeout(2500)
    out["jv_buttons_delegate"] = pg.evaluate(ACTION_BUTTONS)

    # (7) Jejaknya berbunyi "a.n." — dibaca dari API dokumen yang tadi
    #     disetujui, karena barisnya sudah keluar dari kotak masuk.
    out["an_trail"] = None
    for row in (trail_source.get("data") or []):
        if row["code"] not in picked:
            continue
        status, doc = api(f"{row['resource']}/{row['id']}", finance_token)
        entries = (doc.get("data") or {}).get("approvals") or []
        behalf = next((e for e in entries if e.get("on_behalf_of")), None)
        if behalf:
            out["an_trail"] = {
                "code": row["code"],
                "actor": (behalf.get("user") or {}).get("name"),
                "on_behalf_of": behalf["on_behalf_of"]["name"],
                "reads": f"{(behalf.get('user') or {}).get('name')} a.n. {behalf['on_behalf_of']['name']}",
            }
            break

    status, delegated_rows = api("core/approval-delegations", admin)
    out["delegations_seen_by_giver"] = [
        {"delegate": r["delegate"]["name"], "state": r["state"], "scope": r["scope_label"]}
        for r in delegated_rows.get("data", [])]

    m = out["matrix"]
    checks = {
        # 28 saat F-1; baris ke-29 adalah Anggaran overhead (OVB) yang
        # ditambahkan F-2 ke registri Approvable — dikirim TANPA ambang.
        "matrix_renders_every_approvable_type": m.get("rows") == 29,
        "matrix_is_labelled_by_document_type": m.get("count_label") == "29 jenis dokumen",
        "po_row_carries_todays_threshold": any("100.000.000" in c for c in (m.get("po") or [])),
        "spk_row_carries_todays_threshold": any("200.000.000" in c for c in (m.get("spk") or [])),
        "award_row_carries_todays_ladder": any("1.000.000.000" in c for c in (m.get("award") or [])),
        "addendum_row_follows_the_spk_row": any("Mengikuti" in c for c in (m.get("addendum") or [])),
        "amountless_rows_print_the_rule": any("Tanpa nilai rupiah" in c for c in (m.get("izin_kerja") or [])),
        "thirteen_rows_have_no_amount": m.get("dash_rows") == 13,
        # Syarat terpenting paket ini: tidak ada satu pun Rp 0 karangan.
        "no_row_ships_a_fabricated_zero": m.get("zero_rows") == [],
        # 15 ambang = 29 - 13 tanpa nilai rupiah - 1 yang mengikuti SPK.
        # (14 saat F-1; OVB MEMBAWA kolom nilai rupiah, jadi selnya bisa diisi.)
        "a_threshold_cell_only_where_something_enforces_it": m.get("threshold_cells") == 15,
        # dan SATU sel mode, pada satu-satunya jenis yang dapat membawanya.
        "the_mode_cell_only_on_the_type_that_can_carry_it": m.get("mode_cells") == 1,
        "core_update_alone_is_refused": out["edit_without_director"]["status"] == 422,
        "the_refusal_names_the_permission_needed": "approve-director" in out["edit_without_director"]["message"],
        "a_director_with_core_update_may_edit": out["edit_with_both"]["status"] == 200,
        "the_edit_is_reversible_to_the_shipped_default": (
            float(out["po_threshold_after_reset"] or 0) == 100000000.0),
        # Syarat yang membuat syarat berikutnya berarti sesuatu.
        "the_queue_measured_for_bulk_off_is_not_empty": out["bulk_off"]["rows"] > 0,
        "bulk_is_invisible_while_the_cap_is_empty": (
            out["bulk_off"]["checkboxes"] == 0 and out["bulk_off"]["bulk_button"] is False),
        "the_delegate_is_told_before_approving": (
            out["delegate_banner"] is not None and "a.n." in (out["delegate_banner"] or "")),
        "the_banner_names_the_giver": "Administrator" in (out["delegate_banner"] or ""),
        "bulk_appears_once_the_owner_sets_a_cap": (
            out["bulk_on"]["bulk_button"] is True and out["bulk_on"]["checkboxes"] > 0),
        "the_cap_is_printed_not_implied": "maksimum 5" in (out["bulk_on"]["bulk_text"] or ""),
        "two_documents_were_actually_picked": len(picked) == 2,
        "every_row_the_queue_offered_was_picked": len(picked) == out["bulk_on"]["checkboxes"],
        "the_delegates_queue_holds_nothing_the_giver_submitted": (
            "Administrator Sistem" not in out["delegate_queue_submitters"]
            and 1 not in out["delegate_queue_submitter_ids"]),
        # PUTARAN KEDUA — delegasi yang tidak terlihat oleh orang yang
        # memegangnya. Keempat syarat di bawah mengukur satu kalimat: seorang
        # delegat SAMPAI ke pekerjaannya sendiri, tanpa mengetik URL.
        "the_delegate_can_reach_the_queue_from_the_sidebar": (
            "Tugas Saya" in (out.get("delegate_nav") or [])),
        "the_delegate_is_offered_the_approve_button_on_the_document": any(
            b["label"] == "Setujui" for b in (out["delegate_press"].get("buttons") or [])),
        # …dan tombol itu MENYEBUT hak siapa yang dipakainya, sebelum ditekan.
        "the_button_says_whose_right_it_borrows": any(
            b["label"] == "Setujui" and "a.n. Administrator Sistem" in (b.get("title") or "")
            for b in (out["delegate_press"].get("buttons") or [])),
        "pressing_it_approves_the_document_a_n_the_giver": (
            out["delegate_press"].get("status_after") == "approved"
            and (out["delegate_press"].get("trail") or {}).get("on_behalf_of") == "Administrator Sistem"),
        # DAN TIDAK LEBIH DARI ITU: fin.approve juga menggerbangi "Posting
        # Jurnal", yang BUKAN pintu keputusan dokumen. Server menolaknya untuk
        # seorang delegat; layarnya harus setuju.
        "the_native_holder_sees_the_non_decision_button": any(
            b["label"] == "Posting Jurnal" for b in (out.get("jv_buttons_native") or [])),
        "the_delegate_does_not": not any(
            b["label"] == "Posting Jurnal" for b in (out.get("jv_buttons_delegate") or [])),
    }
    if picked:
        checks["bulk_approve_names_every_document_it_touched"] = all(
            any(code in t for t in (out.get("bulk_toasts") or [])) for code in picked)
        # Dan tidak satu pun yang ditawarkan ditolak: itulah kalimat yang
        # dijaga saringan antrean, diucapkan oleh peramban.
        checks["not_one_offered_row_was_refused"] = not any(
            "tidak disetujui" in t.lower() for t in (out.get("bulk_toasts") or []))
        checks["approved_rows_leave_the_queue"] = (
            out["after_bulk"]["rows"] == out["bulk_on"]["rows"] - len(picked))
        checks["the_trail_reads_budi_a_n_sari"] = (
            out["an_trail"] is not None and " a.n. " in out["an_trail"]["reads"])

    out["checks"] = checks
    out["failed_checks"] = [k for k, v in checks.items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S28_matriks_persetujuan_mobile")
def s28m(browser):
    """Matriks 28 baris × 4 kolom di 390 px: tabel LEBAR harus menggulir di
    dalam .table-wrap, bukan membuat halamannya menggulir mendatar."""
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        login(pg, "admin@nusantara.test")
        pg.goto(BASE + "#/settings")
        pg.wait_for_timeout(3500)
        out = pg.evaluate("""() => {
          const card = [...document.querySelectorAll('.card')].find(c => /Matriks Persetujuan/.test(c.innerText));
          const wrap = card ? card.querySelector('.table-wrap') : null;
          return {
            found: !!card,
            rows: card ? card.querySelectorAll('tbody tr').length : 0,
            wrap_scrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
          };
        }""")
        pg.screenshot(path=f"{OUT}/s28-matriks-ponsel-f1.png", full_page=False)
        out["checks"] = {
            "matrix_renders_on_a_phone": out["found"] and out["rows"] == 29,
            "the_wide_table_scrolls_inside_its_own_box": out["wrap_scrolls"] is True,
            "the_page_never_scrolls_sideways": out["page_scrolls_sideways"] is False,
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()


# ------------------------------------------------------- S29 (Fase 2 / F-2)
#
# ANGGARAN VS REALISASI — dan empat kalimat yang hanya bisa dijawab peramban
# sungguhan di atas data demo yang sebenarnya:
#
#   1. ANGKA LAYAR = ANGKA GERBANG. "Sisa" yang tercetak pada baris portofolio
#      adalah DPP terbesar yang masih diterima gerbang: sebuah PO sungguhan
#      sebesar angka itu LOLOS, dan satu sen di atasnya DITOLAK 422 dengan
#      kalimat yang menyebut sisa yang sama. Sampai F-2 tidak ada satu layar
#      pun yang menampilkannya, jadi tidak ada yang bisa berselisih; sejak F-2
#      ada dua pembaca dan hanya satu implementasi.
#   2. ANGGARAN BULANAN BERLABEL TURUNAN. Kalimat penurunannya menyebut RAP dan
#      baseline yang dipakainya, apa adanya, di atas tabelnya.
#   3. TANPA BASELINE = DIGARIS, BUKAN DITAKSIR. Proyek tanpa baseline
#      disetujui tidak punya satu pun sel anggaran bulanan berisi angka, dan
#      tidak satu pun berbunyi "Rp 0" — yang tercetak adalah sebabnya.
#   4. PERINGATAN 90 % MUNCUL DI TEMPAT UANGNYA DIBELANJAKAN. Sesudah sebuah
#      REVISI RAP menurunkan anggarannya, proyek yang tadinya 11 % terpakai
#      melewati ambang, dan peringatannya muncul di layar proyek DAN di bawah
#      kotak Proyek pada formulir PO — sebelum satu baris item pun diketik.
#
# Skenario ini MENULIS ke basis datanya (menyetujui RAP demo, membuat PO,
# membuat revisi RAP), jadi ia dijalankan atas salinan coretan seperti seluruh
# harness.

ANGGARAN_ROW = """(code) => {
  const table = [...document.querySelectorAll('table.data')]
    .find(t => [...t.querySelectorAll('thead th')].some(th => /terpakai/i.test(th.innerText)));
  if (!table) return null;
  const row = [...table.querySelectorAll('tbody tr')].find(r => r.innerText.includes(code));
  if (!row) return { found: false };
  return {
    found: true,
    cells: [...row.children].map(td => td.innerText.replace(/\\s+/g, ' ').trim()),
    headers: [...table.querySelectorAll('thead th')].map(th => th.innerText.replace(/\\s+/g, ' ').trim()),
    rows: table.querySelectorAll('tbody tr').length,
    zero_cells: [...table.querySelectorAll('tbody td')].map(td => td.innerText.trim()).filter(t => /^Rp\\s*0$/.test(t)),
  };
}"""

BULANAN = """() => {
  const alert = document.querySelector('.alert');
  const table = [...document.querySelectorAll('table.data')]
    .find(t => [...t.querySelectorAll('thead th')].some(th => /bobot fase/i.test(th.innerText)));
  const rows = table ? [...table.querySelectorAll('tbody tr')] : [];
  const cell = (r, i) => (r.children[i] ? r.children[i].innerText.replace(/\\s+/g, ' ').trim() : null);
  return {
    derivation: alert ? alert.innerText.replace(/\\s+/g, ' ').trim() : null,
    alert_tone: alert ? alert.className : null,
    headers: table ? [...table.querySelectorAll('thead th')].map(th => th.innerText.trim()) : [],
    months: rows.length,
    budget_cells: rows.map(r => cell(r, 2)),
    actual_cells: rows.map(r => cell(r, 3)),
    zero_budget_cells: rows.map(r => cell(r, 2)).filter(t => /^Rp\\s*0$/.test(t || '')),
    total_budget: table ? (table.querySelector('tfoot tr td:nth-child(3)') || {}).innerText : null,
    // Ada tidaknya SATU angka rupiah pun di badan tab ini: sebuah proyek yang
    // digaris tidak boleh menumbuhkan angka anggaran dari mana pun.
    rupiah_on_screen: [...document.querySelectorAll('.card')].some(c => /Rp\\s/.test(c.innerText)),
  };
}"""

PROJECT_WARNING = """() => {
  const tiles = [...document.querySelectorAll('.stat')].map(s => s.innerText.replace(/\\s+/g, ' ').trim());
  const alerts = [...document.querySelectorAll('.alert')].map(a => a.innerText.replace(/\\s+/g, ' ').trim());
  return {
    // /i WAJIB: app.css memberi .stat .label text-transform uppercase dan
    // innerText memulangkan teks TERGAMBAR — "ANGGARAN TERPAKAI".
    budget_tile: tiles.find(t => /anggaran terpakai/i.test(t)) || null,
    warning: alerts.find(a => /terpakai/i.test(a)) || null,
    warning_class: ([...document.querySelectorAll('.alert')].find(a => /terpakai/i.test(a.innerText)) || {}).className || null,
  };
}"""

FORM_NOTE = """() => {
  const labels = [...document.querySelectorAll('.modal .field, .field')];
  const box = labels.find(f => /^Proyek/.test((f.querySelector('label') || {}).innerText || ''));
  if (!box) return { found: false, labels: labels.map(f => ((f.querySelector('label') || {}).innerText || '').trim()).slice(0, 20) };
  const help = [...box.querySelectorAll('.help')].map(h => ({ text: h.innerText.replace(/\\s+/g, ' ').trim(), hidden: h.hidden, color: h.style.color }));
  return { found: true, help };
}"""

AMBANG_ROW = """(code) => {
  const card = [...document.querySelectorAll('.card')]
    .find(c => /Anggaran proyek terpakai/.test((c.querySelector('h2') || {}).innerText || ''));
  if (!card) return { found: false, cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText) };
  const rows = [...card.querySelectorAll('tbody tr')];
  const i = rows.findIndex(r => r.innerText.includes(code));
  if (i < 0) return { found: false, rows: rows.length };
  return {
    found: true,
    index: i,
    rows: rows.length,
    cells: [...rows[i].children].map(td => td.innerText.replace(/\\s+/g, ' ').trim()),
    states: rows.map(r => (r.querySelector('.badge') || {}).innerText || null),
  };
}"""

REVISION_CHAIN = """() => {
  const card = [...document.querySelectorAll('.card')].find(c => /Riwayat revisi/.test((c.querySelector('h2') || {}).innerText || ''));
  if (!card) return { found: false, cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText) };
  return {
    found: true,
    rows: [...card.querySelectorAll('tbody tr')].map(r => [...r.children].map(td => td.innerText.replace(/\\s+/g, ' ').trim())),
  };
}"""


def s29_po(tok, dpp, project_id=1):
    """PO sungguhan lewat API, sekecil mungkin: satu baris, tanpa PPN."""
    status, vendors = api("procurement/vendors?per_page=5&is_subcontractor=0", tok)
    vendor = (vendors.get("data") or [{}])[0].get("id")
    return api("procurement/purchase-orders", tok, "POST", {
        "vendor_id": vendor,
        "project_id": project_id,
        "order_date": date.today().isoformat(),
        "expected_date": (date.today() + timedelta(days=30)).isoformat(),
        "ppn_rate": 0,
        # PO tanpa PR wajib beralasan (T3.8) — dan alasannya tersimpan di
        # dokumennya, jadi ia ditulis apa adanya, bukan diakali.
        "pr_bypass_reason": "Fixture S29 — uji batas gerbang anggaran.",
        "items": [{"description": "Uji gerbang anggaran S29", "qty": 1, "unit": "ls", "unit_price": dpp}],
    })


@scenario("S29_anggaran_vs_realisasi")
def s29(pg):
    out = {}
    admin = token_for("admin@nusantara.test")
    direktur = token_for("direktur@nusantara.test")

    # (0) FIXTURE. RAP/2026/0001 dikirim demo dalam status 'submitted' — itulah
    #     kenapa SETIAP proyek di data demo membaca "Belum ada RAP disetujui".
    #     Disetujui di sini supaya ada anggaran yang bisa dibandingkan sama
    #     sekali; idempoten, jadi menjalankan ulang skenario ini tidak jatuh.
    status, rap = api("estimation/cost-budgets/1", admin)
    if (rap.get("data") or {}).get("status") != "approved":
        s, _ = api("estimation/cost-budgets/1/approve", direktur, "POST", {})
        out["rap_approved"] = s

    status, budget = api("finance/budget/projects/1", admin)
    b = budget.get("data") or {}
    out["budget_before"] = {k: b.get(k) for k in ("budget", "used", "pct", "state", "remaining_non_subcon", "rap_code")}
    remaining = float(b["remaining_non_subcon"])

    # (1) KESETARAAN DI BATASNYA — dua PO sungguhan, diukur pada keadaan yang
    #     SAMA (keduanya hanya diajukan; PO yang diajukan belum komitmen).
    s_at, po_at = s29_po(admin, remaining)
    s_sub_at, sub_at = api(f"procurement/purchase-orders/{po_at['data']['id']}/submit", admin, "POST", {})
    s_over, po_over = s29_po(admin, round(remaining + 0.01, 2))
    s_sub_over, sub_over = api(f"procurement/purchase-orders/{po_over['data']['id']}/submit", admin, "POST", {})

    out["gate"] = {
        "at_limit_status": s_sub_at,
        "over_limit_status": s_sub_over,
        "over_limit_key": list((sub_over.get("errors") or {}).keys()),
        "over_limit_message": ((sub_over.get("errors") or {}).get("budget") or [None])[0],
    }

    # (2) LAYAR PORTOFOLIO — angka yang sama, dibaca dari DOM.
    login(pg, "admin@nusantara.test")
    pg.goto(BASE + "#/anggaran")
    pg.wait_for_timeout(3000)
    assert_screen(pg, "#/anggaran", "Anggaran")
    out["portfolio"] = pg.evaluate(ANGGARAN_ROW, "PRJ-2026-001")
    # …dan sebuah proyek yang TIDAK punya RAP disetujui, di tabel yang sama.
    out["portfolio_without_rap"] = pg.evaluate(ANGGARAN_ROW, "PRJ-2026-002")
    pg.screenshot(path=f"{OUT}/s29-portofolio-f2.png", full_page=False)

    # (3) PER BULAN — anggaran TURUNAN, berlabel.
    click(pg, "button.tab:has-text('Per bulan')")
    pg.wait_for_timeout(800)
    pg.select_option(".filters select", "1")
    pg.wait_for_timeout(2500)
    out["monthly_with_baseline"] = pg.evaluate(BULANAN)
    pg.screenshot(path=f"{OUT}/s29-bulanan-turunan-f2.png", full_page=False)

    # …dan di KERTAS. app.css menyembunyikan .filters dan .tabs @media print,
    # jadi kotak pilih proyek — satu-satunya penanda proyek pada versi pertama
    # layar ini — menghilang justru pada lembar yang dibawa orang ke rapat.
    pg.emulate_media(media="print")
    pg.wait_for_timeout(400)
    out["monthly_print"] = pg.evaluate("""() => ({
      names_the_project: /PRJ-2026-001/.test(document.querySelector('.main').innerText),
      filters_hidden: getComputedStyle(document.querySelector('.filters')).display === 'none',
      head: (document.querySelector('.card .card-head') || {}).innerText || null,
    })""")
    pg.emulate_media(media="screen")
    pg.wait_for_timeout(300)

    # (4) …dan proyek TANPA baseline: digaris, bukan ditaksir.
    pg.select_option(".filters select", "2")
    pg.wait_for_timeout(2500)
    out["monthly_without_baseline"] = pg.evaluate(BULANAN)
    pg.screenshot(path=f"{OUT}/s29-bulanan-digaris-f2.png", full_page=False)

    # (5) REVISI RAP — dibuat lewat pintu yang sungguh ada di layar: "Buat
    #     Revisi" lalu "Buat dari BOQ" dengan target margin lain. Margin 100 %
    #     membelah anggarannya menjadi setengah, jadi rantai revisinya membawa
    #     SELISIH yang nyata alih-alih dua baris kembar.
    status, rev = api("estimation/cost-budgets/1/revise", admin, "POST",
                      {"revision_reason": "S29 — revisi target margin (uji riwayat selisih)"})
    rev_id = (rev.get("data") or {}).get("id")
    api(f"estimation/cost-budgets/{rev_id}/generate-from-boq", admin, "POST", {"target_margin_pct": 100})
    api(f"estimation/cost-budgets/{rev_id}/submit", admin, "POST", {})
    s_rev, _ = api(f"estimation/cost-budgets/{rev_id}/approve", direktur, "POST", {})
    out["revision_approved_status"] = s_rev

    # (6) MELEWATI AMBANG 90 % — dengan komitmen sungguhan, bukan dengan
    #     menggeser ambangnya. PO yang melampaui sisa DIAKUI pengajunya
    #     (confirm_over_budget, jalur yang memang disediakan gerbang) lalu
    #     disetujui direktur, sehingga ia menjadi komitmen berjalan.
    status, mid = api("finance/budget/projects/1", admin)
    m = mid.get("data") or {}
    out["budget_after_revision"] = {k: m.get(k) for k in ("budget", "used", "pct", "state", "rap_code", "rap_revision")}
    target = round(0.93 * float(m["budget"]) - float(m["used"]), 2)
    s_po, po = s29_po(admin, target)
    s_sub, sub = api(f"procurement/purchase-orders/{po['data']['id']}/submit", admin, "POST",
                     {"confirm_over_budget": True})
    s_apr, _ = api(f"procurement/purchase-orders/{po['data']['id']}/approve", direktur, "POST", {})
    status, after = api("finance/budget/projects/1", admin)
    a = after.get("data") or {}
    out["threshold_fixture"] = {"dpp": target, "submit": s_sub, "approve": s_apr}
    out["budget_after_commitment"] = {k: a.get(k) for k in ("budget", "used", "pct", "state", "worst_side", "worst_state")}

    # (6) PERINGATAN DI TEMPAT UANGNYA DIBELANJAKAN — layar proyek…
    pg.goto(BASE + "#/d/projects/1")
    pg.wait_for_timeout(3500)
    out["project_screen"] = pg.evaluate(PROJECT_WARNING)
    pg.screenshot(path=f"{OUT}/s29-peringatan-proyek-f2.png", full_page=False)

    # …dan formulir PO, sebelum satu baris item pun diketik.
    pg.goto(BASE + "#/r/procurement/purchase-orders")
    pg.wait_for_timeout(2500)
    click(pg, ".page-head button:has-text('Tambah')")
    pg.wait_for_timeout(1500)
    pg.evaluate("""() => {
      const box = [...document.querySelectorAll('.field')].find(f => /^Proyek/.test((f.querySelector('label')||{}).innerText||''));
      const input = box.querySelector('input, select');
      return input ? input.className : null;
    }""")
    # Combobox proyek: ketik kodenya lalu pilih usulan pertama.
    pg.fill(".modal .field:has(label:text-is('Proyek')) input", "PRJ-2026-001")
    pg.wait_for_timeout(900)
    pg.keyboard.press("ArrowDown")
    pg.keyboard.press("Enter")
    pg.wait_for_timeout(2500)
    out["po_form"] = pg.evaluate(FORM_NOTE)
    pg.screenshot(path=f"{OUT}/s29-formulir-po-peringatan-f2.png", full_page=False)
    pg.keyboard.press("Escape")
    # Escape pada formulir yang sudah punya isian membuka dialog "Tutup tanpa
    # menyimpan?", dan dialog itu BERTAHAN melewati navigasi berikutnya: bukti
    # putaran lalu memuat "s29-riwayat-revisi-f2.png" yang seluruh isinya adalah
    # dialog itu, bukan riwayat revisi yang namanya ia bawa. Dibuang di sini,
    # jadi tangkapan layar sesudahnya memotret layarnya sendiri.
    pg.wait_for_timeout(400)
    if pg.locator(".modal button:has-text('Buang isian')").count():
        click(pg, ".modal button:has-text('Buang isian')")
        pg.wait_for_timeout(600)

    # (7) RIWAYAT REVISI di layar RAP.
    pg.goto(BASE + f"#/d/estimation/cost-budgets/{rev_id}")
    pg.wait_for_timeout(3000)
    out["revision_chain"] = pg.evaluate(REVISION_CHAIN)
    pg.screenshot(path=f"{OUT}/s29-riwayat-revisi-f2.png", full_page=False)

    # (8) REGISTRI AMBANG — layar KEDUA yang menyebut keadaan proyek yang sama.
    #     Sesudah f5d691b registri mengirim batas SISI TERBURUK, dan sebuah sisi
    #     yang dianggarkan Rp 0 kehilangan batasnya (limit <= 0 dibaca "batas
    #     belum disetel"): layar proyek berkata "Melampaui batas" sementara
    #     baris registri untuk proyek yang SAMA berbunyi "Batas belum disetel"
    #     dan — karena urutannya menurut persen yang tidak ada — jatuh ke DASAR
    #     daftar. Dua layar, satu proyek, dua keadaan (verifikasi putaran 2).
    pg.goto(BASE + "#/ambang")
    pg.wait_for_timeout(3000)
    out["ambang"] = pg.evaluate(AMBANG_ROW, "PRJ-2026-001")
    pg.screenshot(path=f"{OUT}/s29-ambang-registri-f2.png", full_page=False)

    row = out["portfolio"] or {}
    cells = row.get("cells") or []
    monthly = out["monthly_with_baseline"]
    ruled = out["monthly_without_baseline"]
    note_texts = [h["text"] for h in (out["po_form"].get("help") or []) if not h["hidden"]]

    out["checks"] = {
        # 1 — layar dan gerbang
        "the_gate_accepts_exactly_the_remaining_the_screen_prints": out["gate"]["at_limit_status"] == 200,
        "one_cent_more_is_refused_on_the_budget_key": (
            out["gate"]["over_limit_status"] == 422 and out["gate"]["over_limit_key"] == ["budget"]),
        "the_refusal_names_the_same_remaining": (
            "31.123.865.391" in (out["gate"]["over_limit_message"] or "")),
        # Kolom "Sisa" membawa TOTAL-nya, dan di bawahnya kedua sisi yang
        # benar-benar dihakimi gerbang — dalam RUPIAH PENUH, angka PO yang
        # diterima di atas apa adanya (format ringkas membulatkan KE ATAS, dan
        # sebuah plafon yang dibulatkan ke atas adalah janji yang ditolak).
        "the_portfolio_row_prints_the_remaining_the_gate_enforces": any(
            "PO Rp 31.123.865.391" in c for c in cells),
        "the_portfolio_row_names_the_governing_rap": any("RAP/2026/0001" in c for c in cells),
        # Proyek TANPA RAP disetujui tidak boleh punya satu pun angka anggaran:
        # RAP, Sisa dan Terpakai harus digaris, tidak pernah "Rp 0".
        "a_project_without_a_rap_is_ruled_never_rp_0": (
            out["portfolio_without_rap"].get("found") is True
            and [out["portfolio_without_rap"]["cells"][i] for i in (2, 5, 6)] == ["—", "—", "Tanpa RAP"]),
        # …dan TIDAK SATU SEL PUN di seluruh tabel berbunyi "Rp 0". Harness ini
        # sudah mengumpulkan zero_cells sejak putaran pertama tanpa ada satu
        # syarat pun yang membacanya — dan yang diukurnya waktu itu ['Rp 0']:
        # kolom Realisasi PRJ-2026-002, proyek tanpa satu baris biaya pun
        # (pola "no_row_ships_a_fabricated_zero" milik S28).
        "no_row_ships_a_fabricated_zero": (
            (out["portfolio"] or {}).get("zero_cells") == []
            and (out["portfolio_without_rap"] or {}).get("zero_cells") == []),
        # 2 — anggaran bulanan berlabel turunan
        "the_monthly_budget_is_labelled_derived": (
            "TURUNAN" in (monthly.get("derivation") or "")
            and "RAP/2026/0001" in (monthly.get("derivation") or "")
            and "BSL/2026/VIII/0001" in (monthly.get("derivation") or "")),
        "the_monthly_table_has_a_derived_budget_column": any(
            "turunan" in h.lower() for h in (monthly.get("headers") or [])),
        # Lembar tercetak menyebut proyeknya — diuji di media cetak sungguhan,
        # dengan saringan proyek yang memang tersembunyi di sana.
        "the_printed_monthly_sheet_names_its_project": (
            out["monthly_print"]["names_the_project"] is True
            and out["monthly_print"]["filters_hidden"] is True),
        "months_without_realisation_are_ruled_not_zero": (
            "—" in (monthly.get("actual_cells") or []) and monthly.get("zero_budget_cells") == []),
        # 3 — tanpa baseline
        "a_project_without_a_baseline_says_so": (
            "belum punya baseline yang disetujui" in (ruled.get("derivation") or "")),
        # …dan tidak menumbuhkan satu angka pun dari mana-mana: pada data demo
        # PRJ-2026-002 tidak punya baseline MAUPUN satu baris biaya, jadi yang
        # benar adalah nol bulan dan nol rupiah — bukan dua belas baris "Rp 0".
        "and_invents_no_monthly_number_at_all": (
            ruled.get("months") == 0
            and ruled.get("rupiah_on_screen") is False
            and all(not (c or "").startswith("Rp") for c in (ruled.get("budget_cells") or []))),
        "the_ruled_screen_warns_instead_of_informing": "warn" in (ruled.get("alert_tone") or ""),
        # 4 — peringatan 90 % di tempat uangnya dibelanjakan
        "the_revision_moves_the_governing_budget": (
            out["budget_after_revision"]["rap_revision"] == 1
            and float(out["budget_after_revision"]["budget"]) < 42173913043.47),
        "a_real_commitment_crosses_the_ninety_percent_threshold": (
            out["budget_after_commitment"]["state"] == "mendekati"
            and float(out["budget_after_commitment"]["pct"]) >= 90),
        "the_over_budget_po_needed_an_explicit_acknowledgement": (
            out["threshold_fixture"]["submit"] == 200 and out["threshold_fixture"]["approve"] == 200),
        "the_project_screen_carries_the_budget_tile": (
            out["project_screen"]["budget_tile"] is not None),
        # Pita peringatan menyala menurut SISI TERBURUK, bukan menurut total:
        # pada keadaan yang dibuat harness ini totalnya 93 % (mendekati)
        # sementara sisi PO sudah lewat 100 %, dan pita kuning di atas proyek
        # yang setiap PO-nya ditolak adalah alarm yang berbohong tenang.
        "the_project_screen_raises_the_warning_strip": (
            out["project_screen"]["warning"] is not None
            and ("error" if out["budget_after_commitment"]["worst_state"] == "lampau" else "warn")
            in (out["project_screen"]["warning_class"] or "")),
        # Pita menyebut sisi yang menghakimi, DENGAN kata yang benar untuk
        # keadaannya: sisi yang sudah lewat tidak menawarkan plafon apa pun
        # (verifikasi putaran 2 — "PO menyisakan Rp 0" menawarkan Rp 0 sebagai
        # DPP yang diterima pada sisi yang tidak menerima satu DPP pun).
        "and_it_names_the_side_the_gate_judges": (
            "non-subkon" in (out["project_screen"]["warning"] or "")
            and ("PO melampaui" if out["budget_after_commitment"]["worst_state"] == "lampau"
                 else "PO menyisakan") in (out["project_screen"]["warning"] or "")),
        "and_an_exhausted_side_offers_no_ceiling_at_all": (
            out["budget_after_commitment"]["worst_state"] != "lampau"
            or ("menyisakan Rp 0" not in (out["project_screen"]["warning"] or "")
                and "sudah melampaui" in (out["project_screen"]["warning"] or ""))),
        "the_po_form_warns_before_a_single_line_is_typed": any(
            "terpakai" in t for t in note_texts),
        "the_po_form_note_is_coloured_at_the_threshold": any(
            h.get("color") for h in (out["po_form"].get("help") or []) if not h["hidden"]),
        # 5 — riwayat revisi
        "the_revision_history_shows_both_revisions": len((out["revision_chain"].get("rows") or [])) == 2,
        "and_the_difference_between_them": any(
            (r[7] or "").startswith("Rp -") for r in (out["revision_chain"].get("rows") or []) if len(r) > 7),
        "revision_zero_has_no_difference_to_show": any(
            r[0] == "0" and r[7] == "—" for r in (out["revision_chain"].get("rows") or []) if len(r) > 7),
        # 6 — registri Ambang: layar kedua, proyek yang sama, SATU keadaan
        "the_threshold_registry_says_what_the_project_screen_says": (
            out["ambang"].get("found") is True
            and {"lampau": "Melampaui batas", "mendekati": "Mendekati batas", "aman": "Aman"}.get(
                out["budget_after_commitment"]["worst_state"], "?") in " ".join(out["ambang"]["cells"])),
        # …dan baris yang mendekati/melewati batasnya berdiri di ATAS daftar,
        # bukan di dasarnya di bawah proyek yang tidak punya keadaan sama sekali.
        "and_the_row_closest_to_its_limit_stands_at_the_top": (
            out["ambang"].get("found") is True and out["ambang"]["index"] == 0),
    }
    out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S29_anggaran_vs_realisasi_mobile")
def s29m(browser):
    """Portofolio anggaran (9 kolom) di 390 px: tabel lebar menggulir di dalam
    .table-wrap, halamannya tidak menggulir mendatar, dan kalimat penurunan
    anggaran bulanan tetap terbaca utuh."""
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        login(pg, "admin@nusantara.test")
        pg.goto(BASE + "#/anggaran")
        pg.wait_for_timeout(3500)
        out = pg.evaluate("""() => {
          const table = [...document.querySelectorAll('table.data')][0];
          const wrap = table ? table.closest('.table-wrap') : null;
          return {
            rows: table ? table.querySelectorAll('tbody tr').length : 0,
            wrap_scrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
          };
        }""")
        pg.screenshot(path=f"{OUT}/s29-portofolio-ponsel-f2.png", full_page=False)
        out["checks"] = {
            "the_portfolio_renders_on_a_phone": out["rows"] > 0,
            "the_wide_table_scrolls_inside_its_own_box": out["wrap_scrolls"] is True,
            "the_page_never_scrolls_sideways": out["page_scrolls_sideways"] is False,
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()



# ------------------------------------------------------- S30 (Fase 2 / F-3)
#
# PIPELINE CRM — lima kalimat yang hanya bisa dijawab peramban sungguhan:
#
#   1. PROSPEK TANPA PEMILIK BERBUNYI SAMA DI MANA-MANA. "Belum ditugaskan" di
#      kartu papan, di baris daftar, dan di layar dokumennya — bukan sel kosong,
#      yang terbaca "belum dimuat" dan bukan "belum ada yang bertanggung jawab".
#   2. SERETAN MUNDUR MENUNTUT ALASAN, dan kalimat yang diminta adalah kalimat
#      SERVER (nama prospek, tahap asal dan tujuan). Membatalkannya
#      MENGEMBALIKAN kartunya — papan yang meninggalkan kartu di kolom yang
#      bukan statusnya berbohong tentang satu-satunya hal yang dijualnya.
#   3. SERETAN KE "MENANG" DITOLAK dengan kalimat yang menyebut jalan yang
#      benar (Tandai Menang pada penawarannya), bukan kalimat generik papan.
#   4. TANGGAL TINDAK LANJUT = AKTIVITAS TERBUKA PALING AWAL. Diketik di dua
#      tempat berbeda (dua aktivitas), dibaca di dua layar (kartu Aktivitas dan
#      panel Informasi), dan ketiganya harus menyebut tanggal yang sama.
#   5. KARTU AKTIVITAS KOSONG MENGATAKAN DIRINYA KOSONG — tidak ada "0
#      aktivitas" di layar mana pun.
#
# Fixture dibuat lewat API (bukan lewat seed), lalu dibaca lewat peramban.

S30_BOARD = """() => ({
  h1: (document.querySelector('.page-head h1') || {}).innerText || null,
  lanes: [...document.querySelectorAll('.board-cards')].map(l => ({
    status: l.dataset.status,
    cards: [...l.querySelectorAll('.board-card')].map(c => c.innerText.replace(/\\s+/g, ' ').trim()),
  })),
})"""

S30_CARD = """(title) => {
  const card = [...document.querySelectorAll('.card')].find(c => new RegExp(title).test((c.querySelector('h2') || {}).innerText || ''));
  if (!card) return { found: false, cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText) };
  return { found: true, text: card.innerText.replace(/\\s+/g, ' ').trim(),
           rows: [...card.querySelectorAll('tbody tr')].map(r => [...r.children].map(td => td.innerText.replace(/\\s+/g, ' ').trim())) };
}"""

S30_KV = """(label) => {
  const dts = [...document.querySelectorAll('dl.kv dt')];
  const dt = dts.find(d => d.innerText.trim() === label);
  return dt ? (dt.nextElementSibling || {}).innerText.trim() : null;
}"""


def s30_lead(tok, name, status="contacted"):
    code, body = api("crm/leads", tok, "POST", {"name": name, "status": status})
    return code, (body.get("data") or {})


def s30_activity(tok, lead_id, subject, due=None):
    return api("crm/activities", tok, "POST", {
        "document_type": "lead", "document_id": lead_id,
        "type": "call", "subject": subject, "due_at": due})


@scenario("S30_pipeline_crm")
def s30(pg):
    out = {}
    admin = token_for("admin@nusantara.test")

    # (0) Fixture: satu prospek TANPA pemilik dengan dua aktivitas terbuka, dan
    #     satu prospek tanpa aktivitas sama sekali.
    status, lead = s30_lead(admin, "PT Cahaya Nusantara (fixture S30)")
    out["lead_created"] = {"status": status, "code": lead.get("code"), "owner": lead.get("owner_user_name")}
    lead_id = lead.get("id")

    jauh = (date.today() + timedelta(days=10)).isoformat()
    dekat = (date.today() + timedelta(days=3)).isoformat()
    out["activity_far"] = s30_activity(admin, lead_id, "Kirim proposal awal (S30)", jauh)[0]
    out["activity_near"] = s30_activity(admin, lead_id, "Telepon konfirmasi kebutuhan (S30)", dekat)[0]
    out["expected_follow_up"] = dekat

    status, kosong = s30_lead(admin, "PT Tanpa Aktivitas (fixture S30)")
    out["empty_lead"] = {"status": status, "code": kosong.get("code")}

    login(pg, "admin@nusantara.test")

    # (1) PAPAN: kartu tanpa pemilik menyebutnya.
    pg.goto(BASE + "#/b/crm/leads")
    pg.wait_for_timeout(3000)
    board = pg.evaluate(S30_BOARD)
    out["board"] = board
    kartu = [c for lane in board["lanes"] for c in lane["cards"] if lead.get("code") in c]
    out["unassigned_card"] = kartu[0] if kartu else None
    pg.screenshot(path=f"{OUT}/s30-papan-pipeline-f3.png", full_page=True)

    card_sel = f".board-card:has-text('{lead.get('code')}')"

    # (2) SERETAN MUNDUR: dialog alasan, lalu DIBATALKAN — kartunya kembali.
    pg.drag_and_drop(card_sel, ".board-cards[data-status='new']")
    # Dialognya menunggu satu perjalanan ke server (422 yang MEMINTA alasan),
    # jadi yang ditunggu adalah dialognya — bukan sebuah angka milidetik yang
    # kebetulan cukup di mesin ini dan tidak cukup di mesin berikutnya.
    pg.wait_for_selector(".modal textarea", timeout=15000)
    out["backward_dialog"] = pg.evaluate(r"""() => {
      const m = document.querySelector('.modal');
      return m ? { open: true, title: (m.querySelector('.modal-head, h2, header') || {}).innerText || null,
                   text: m.innerText.replace(/\s+/g, ' ').trim() } : { open: false };
    }""")
    pg.screenshot(path=f"{OUT}/s30-alasan-mundur-f3.png", full_page=False)
    click(pg, ".modal button:has-text('Batal')")
    pg.wait_for_timeout(900)
    out["after_cancel"] = pg.evaluate("""(code) => {
      const lane = [...document.querySelectorAll('.board-cards')].find(l => [...l.querySelectorAll('.board-card')].some(c => c.innerText.includes(code)));
      return lane ? lane.dataset.status : null;
    }""", lead.get("code"))

    # (3) SERETAN MUNDUR yang dijalani sampai selesai.
    pg.drag_and_drop(card_sel, ".board-cards[data-status='new']")
    pg.wait_for_selector(".modal textarea", timeout=15000)
    pg.fill(".modal textarea", "Kontak PIC berganti, kualifikasi diulang (uji S30)")
    click(pg, ".modal button:has-text('Pindahkan')")
    pg.wait_for_timeout(2500)
    out["after_backward"] = pg.evaluate(r"""(code) => {
      const lane = [...document.querySelectorAll('.board-cards')].find(l => [...l.querySelectorAll('.board-card')].some(c => c.innerText.includes(code)));
      return { lane: lane ? lane.dataset.status : null, toasts: [...document.querySelectorAll('.toast')].map(t => t.innerText.replace(/\s+/g, ' ').trim()) };
    }""", lead.get("code"))

    # (4) SERETAN KE MENANG: ditolak, kartunya kembali, kalimatnya menyebut jalannya.
    pg.drag_and_drop(card_sel, ".board-cards[data-status='won']")
    pg.wait_for_timeout(2500)
    out["drag_to_won"] = pg.evaluate(r"""(code) => {
      const lane = [...document.querySelectorAll('.board-cards')].find(l => [...l.querySelectorAll('.board-card')].some(c => c.innerText.includes(code)));
      return { lane: lane ? lane.dataset.status : null,
               toasts: [...document.querySelectorAll('.toast')].map(t => t.innerText.replace(/\s+/g, ' ').trim()) };
    }""", lead.get("code"))
    pg.screenshot(path=f"{OUT}/s30-tolak-menang-f3.png", full_page=False)

    # (5) LAYAR DOKUMEN: kartu Aktivitas, tanggal turunan, pemilik, riwayat tahap.
    pg.goto(BASE + f"#/d/crm/leads/{lead_id}")
    pg.wait_for_timeout(3000)
    out["activity_card"] = pg.evaluate(S30_CARD, "Aktivitas")
    out["history_card"] = pg.evaluate(S30_CARD, "Riwayat Tahap")
    out["kv_follow_up"] = pg.evaluate(S30_KV, "Tindak lanjut berikutnya")
    out["kv_owner"] = pg.evaluate(S30_KV, "Pemilik prospek")
    out["form_has_follow_up_input"] = pg.evaluate("""() => {
      return [...document.querySelectorAll('label')].some(l => /Follow-up berikutnya/i.test(l.innerText));
    }""")
    pg.screenshot(path=f"{OUT}/s30-prospek-aktivitas-f3.png", full_page=True)

    # (6) DAFTAR: sel pemilik pada baris yang sama.
    pg.goto(BASE + "#/r/crm/leads")
    pg.wait_for_timeout(2500)
    out["list_row"] = pg.evaluate(r"""(code) => {
      const head = [...document.querySelectorAll('table.data thead th')].map(th => th.innerText.trim());
      const row = [...document.querySelectorAll('table.data tbody tr')].find(r => r.innerText.includes(code));
      return row ? { head, cells: [...row.children].map(td => td.innerText.replace(/\s+/g, ' ').trim()) } : { head, cells: null };
    }""", lead.get("code"))

    # (7) KARTU AKTIVITAS KOSONG.
    pg.goto(BASE + f"#/d/crm/leads/{kosong.get('id')}")
    pg.wait_for_timeout(2500)
    out["empty_activity_card"] = pg.evaluate(S30_CARD, "Aktivitas")

    # (7b) MENAMBAH AKTIVITAS DARI KARTUNYA — satu-satunya jalan membuat
    #      aktivitas (layar daftar baca saja), jadi jalur inilah yang harus
    #      dibuktikan peramban, bukan POST yang dipakai fixture di atas.
    click(pg, ".card:has-text('Aktivitas') button:has-text('Tambah aktivitas')")
    pg.wait_for_selector(".modal input[name=subject], .modal .form-grid", timeout=10000)
    pg.fill(".modal .field:has-text('Kegiatan') input", "Telepon pertama dari kartu (S30)")
    pg.fill(".modal .field:has-text('Jatuh tempo') input", dekat)
    click(pg, ".modal button:has-text('Simpan')")
    pg.wait_for_timeout(2500)
    out["after_add_activity"] = pg.evaluate(S30_CARD, "Aktivitas")

    # (8) KARTU YANG SAMA DI LAYAR PENAWARAN — registri kartunya memuat tiga
    #     slug, dan dua di antaranya tidak pernah dibuka uji PHP di peramban.
    status, quotations = api("crm/quotations?per_page=1", admin)
    quotation = (quotations.get("data") or [{}])[0]
    out["quotation"] = {"status": status, "code": quotation.get("code")}
    if quotation.get("id"):
        out["quotation_activity"] = s30_activity(
            admin, 0, "x")[0] if False else api("crm/activities", admin, "POST", {
                "document_type": "quotation", "document_id": quotation["id"],
                "type": "meeting", "subject": "Klarifikasi teknis dengan pelanggan (S30)",
                "due_at": dekat})[0]
        pg.goto(BASE + f"#/d/crm/quotations/{quotation['id']}")
        pg.wait_for_timeout(2500)
        out["quotation_activity_card"] = pg.evaluate(S30_CARD, "Aktivitas")

    # Tanggal yang diharapkan, ditulis seperti layar menulisnya ("11 Sep 2026").
    bulan = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"]
    d = date.fromisoformat(dekat)
    tanggal_layar = f"{d.day} {bulan[d.month - 1]} {d.year}"
    out["expected_follow_up_text"] = tanggal_layar

    activity_text = (out["activity_card"].get("text") or "")
    kosong_text = (out["empty_activity_card"].get("text") or "")

    out["checks"] = {
        # 1 — pemilik kosong, tiga permukaan
        "the_board_card_names_the_missing_owner": bool(out["unassigned_card"]) and "Belum ditugaskan" in out["unassigned_card"],
        "the_document_screen_says_the_same": out["kv_owner"] == "Belum ditugaskan",
        "and_so_does_the_list_row": bool(out["list_row"].get("cells")) and any(
            c == "Belum ditugaskan" for c in out["list_row"]["cells"]),
        # 2 — mundur wajib beralasan, dan pembatalan mengembalikan kartunya
        "a_backward_drag_asks_for_a_reason": out["backward_dialog"].get("open") is True
            and "sebutkan alasannya" in (out["backward_dialog"].get("text") or ""),
        "the_reason_dialog_speaks_the_servers_own_sentence": lead.get("code") in (out["backward_dialog"].get("text") or ""),
        "cancelling_puts_the_card_back": out["after_cancel"] == "contacted",
        "answering_moves_the_card": out["after_backward"]["lane"] == "new",
        # 3 — menang hanya lewat penawaran
        "a_drag_to_won_is_refused": out["drag_to_won"]["lane"] == "new",
        "and_the_refusal_names_the_quotation_route": any(
            "Tandai Menang" in t for t in out["drag_to_won"]["toasts"]),
        "the_refusal_is_the_servers_sentence_not_the_generic_one": not any(
            "tidak ada aksi yang memindahkan" in t for t in out["drag_to_won"]["toasts"]),
        # 4 — tanggal tindak lanjut = aktivitas terbuka paling awal
        "the_activity_card_exists": out["activity_card"].get("found") is True,
        "it_lists_both_open_activities": "Telepon konfirmasi kebutuhan (S30)" in activity_text
            and "Kirim proposal awal (S30)" in activity_text,
        "it_names_where_the_follow_up_date_comes_from": "diturunkan dari aktivitas terbuka paling awal" in activity_text,
        "the_derived_date_is_the_earliest_open_activity": tanggal_layar in activity_text,
        "and_the_information_panel_shows_the_same_date": out["kv_follow_up"] == tanggal_layar,
        "the_typed_follow_up_field_is_gone_from_the_form": out["form_has_follow_up_input"] is False,
        # 5 — kartu kosong mengaku kosong
        "an_empty_activity_card_says_so": "Belum ada aktivitas dicatat" in kosong_text,
        # …dan mengisinya DARI KARTUNYA bekerja, lalu tanggal turunannya muncul
        "adding_an_activity_from_the_card_works": "Telepon pertama dari kartu (S30)" in (out["after_add_activity"].get("text") or ""),
        "and_the_derived_date_appears_at_once": "diturunkan dari aktivitas terbuka paling awal" in (out["after_add_activity"].get("text") or ""),
        # kartu yang sama di layar penawaran
        "the_same_card_serves_the_quotation_screen": out.get("quotation_activity_card", {}).get("found") is True
            and "Klarifikasi teknis dengan pelanggan (S30)" in (out.get("quotation_activity_card", {}).get("text") or ""),
        "and_never_prints_a_fabricated_zero": "0 aktivitas" not in kosong_text,
        # riwayat tahap menyimpan alasannya
        "the_backward_reason_is_stored_in_the_history": "Kontak PIC berganti" in (out["history_card"].get("text") or ""),
        "the_history_names_the_direction": "Mundur" in (out["history_card"].get("text") or ""),
    }
    out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
    out["ok"] = not out["failed_checks"]
    return out



# --------------------------------------- S30r (verifikasi/perbaikan F-3)
#
# ENAM KALIMAT YANG PUTARAN VERIFIKASI 8 SEP 2026 BUKTIKAN SALAH, dan yang
# hanya bisa dibuktikan benar lagi oleh peramban:
#
#   1. KARTU AKTIVITAS TIDAK BOLEH MEMAJANG JUMLAH YANG SALAH. Ia menggambar
#      paling banyak 100 baris; dulu ia memakai `api.get`, yang membuang
#      amplopnya, lalu memajang "100 terbuka." pada dokumen berisi 110 — tanpa
#      satu kalimat pun yang mengakui pemotongan, sementara kartu papan untuk
#      prospek yang sama menyebut angka sebenarnya.
#   2. LAYAR DETAIL AKTIVITAS MENYEBUT DOKUMEN INDUKNYA, dan menautkannya:
#      antrean kerja yang barisnya tidak bisa dibuka sampai ke pekerjaannya
#      adalah antrean buntu.
#   3. "DISELESAIKAN OLEH" SEKALI, bukan dua kali dengan id mentah di salah
#      satunya.
#   4. ANTREAN HARIAN BISA DITANYAKAN: saringan Keadaan ada, dan tautan
#      ?state=… yang dibawa pemberitahuan jatuh tempo benar-benar menyaring.
#   5. "BELUM DITUGASKAN" PUNYA KLIK, dan tautannya bisa dibagikan.
#   6. PAPAN BISA DIPAKAI TANPA TETIKUS DAN BISA DICETAK: kartunya tombol
#      (Spasi membukanya alih-alih menggulir halaman), dan pada A4 potret
#      keenam kolomnya utuh alih-alih tiga yang hilang diam-diam.

@scenario("S30_pipeline_crm_repair")
def s30r(pg):
    out = {}
    admin = token_for("admin@nusantara.test")

    # (0) Fixture. Dua aktivitas lewat API supaya turunannya dihitung layanan,
    #     sisanya disisipkan langsung ke sqlite: 108 POST akan menabrak plafon
    #     120 permintaan/menit, dan yang diuji di sini bukan endpoint-nya.
    status, lead = s30_lead(admin, "PT Seratus Sepuluh (fixture S30r)")
    lead_id = lead.get("id")
    dekat = (date.today() + timedelta(days=2)).isoformat()
    jauh = (date.today() + timedelta(days=40)).isoformat()
    out["fixture_lead"] = {"status": status, "code": lead.get("code")}
    out["activity_near"] = s30_activity(admin, lead_id, "Telepon paling awal (S30r)", dekat)[0]
    out["activity_far"] = s30_activity(admin, lead_id, "Telepon paling akhir (S30r)", jauh)[0]

    con = sqlite3.connect(DB)
    con.executemany(
        "INSERT INTO crm_activities (document_type, document_id, type, subject, due_at, created_at, updated_at)"
        " VALUES ('lead', ?, 'note', ?, ?, datetime('now'), datetime('now'))",
        [(lead_id, f"Sisipan S30r #{i}", (date.today() + timedelta(days=3 + (i % 30))).isoformat()) for i in range(108)])
    con.commit()
    out["activities_total"] = con.execute(
        "SELECT COUNT(*) FROM crm_activities WHERE document_type='lead' AND document_id=?", (lead_id,)).fetchone()[0]
    con.close()

    # Satu aktivitas SELESAI pada prospek lain — layar detailnya yang dibaca.
    status, induk = s30_lead(admin, "PT Induk Aktivitas (fixture S30r)", status="new")
    code, body = api("crm/activities", admin, "POST", {
        "document_type": "lead", "document_id": induk.get("id"),
        "type": "meeting", "subject": "Kunjungan pertama (S30r)", "due_at": date.today().isoformat()})
    activity_id = (body.get("data") or {}).get("id")
    out["mark_done"] = api(f"crm/activities/{activity_id}/done", admin, "POST")[0]

    # Satu yang SUNGGUH lewat tanggal, supaya "state=overdue" punya sesuatu
    # untuk dipulangkan — dan supaya yang SELESAI terbukti tidak ikut.
    kemarin = (date.today() - timedelta(days=6)).isoformat()
    out["activity_overdue"] = s30_activity(admin, induk.get("id"), "Telepon tagih dokumen (S30r-LEWAT)", kemarin)[0]

    login(pg, "admin@nusantara.test")

    # (1) KARTU AKTIVITAS pada dokumen yang lebih panjang dari kartunya.
    pg.goto(BASE + f"#/d/crm/leads/{lead_id}")
    pg.wait_for_timeout(3500)
    out["card"] = pg.evaluate("""() => {
      const card = [...document.querySelectorAll('.card')].find(c => (c.querySelector('h2')||{}).innerText === 'Aktivitas');
      if (!card) return null;
      const body = card.querySelector('.card-body');
      return { lines: [...body.children].slice(0, 3).map(n => n.innerText.replace(/\\s+/g,' ').trim()),
               drawn: body.querySelectorAll('.attachment').length };
    }""")

    # (2)+(3) LAYAR DETAIL AKTIVITAS: induknya disebut, ditautkan, dan
    #         penyelesainya disebut SEKALI.
    pg.goto(BASE + f"#/d/crm/activities/{activity_id}")
    pg.wait_for_timeout(2500)
    out["activity_detail"] = pg.evaluate("""() => {
      const dl = document.querySelector('dl.kv'); const kv = [];
      if (dl) { const dts=[...dl.querySelectorAll('dt')], dds=[...dl.querySelectorAll('dd')];
        dts.forEach((dt,i) => kv.push([dt.innerText.trim(), (dds[i]||{}).innerText.trim()])); }
      return { kv, parent_links: [...document.querySelectorAll('.main a[href^="#/d/crm/leads/"]')].map(a => a.getAttribute('href')) };
    }""")
    pg.screenshot(path=f"{OUT}/s30r-detail-aktivitas.png", full_page=False)

    # (4) ANTREAN HARIAN: saringan Keadaan, dan tautan tersaring.
    pg.goto(BASE + "#/r/crm/activities?state=overdue")
    pg.wait_for_timeout(2500)
    out["activity_list"] = pg.evaluate("""() => {
      const state = [...document.querySelectorAll('.filters select')].find(s => s.getAttribute('aria-label') === 'Keadaan');
      return { filters: [...document.querySelectorAll('.filters select, .filters input')].map(n => n.getAttribute('aria-label') || n.placeholder),
               state_value: state ? state.value : null,
               state_options: state ? [...state.options].map(o => o.text) : null,
               hash: location.hash,
               rows: [...document.querySelectorAll('tbody tr')].map(r => r.innerText.replace(/\\s+/g, ' ').trim()) };
    }""")

    # (5) "BELUM DITUGASKAN": kliknya ada, dan tautannya selamat.
    pg.goto(BASE + "#/r/crm/leads?unassigned=1")
    pg.wait_for_timeout(2500)
    out["leads_list"] = pg.evaluate("""() => ({
      filters: [...document.querySelectorAll('.filters select, .filters input')].map(n => n.getAttribute('aria-label') || n.placeholder),
      hash: location.hash,
      owners: [...document.querySelectorAll('tbody tr')].map(r => r.innerText.includes('Belum ditugaskan')),
    })""")

    # (6) PAPAN: kartunya tombol, dan kertasnya memuat semua kolom.
    pg.goto(BASE + "#/b/crm/leads")
    pg.wait_for_timeout(3000)
    out["board_card"] = pg.evaluate("""() => { const c = document.querySelector('.board-card');
      return c && { role: c.getAttribute('role'), aria: c.getAttribute('aria-label'), tabindex: c.getAttribute('tabindex') }; }""")
    pg.evaluate("() => { window.scrollTo(0, 0); document.querySelector('.board-card').focus(); }")
    pg.keyboard.press(" ")
    pg.wait_for_timeout(1500)
    out["space_key"] = pg.evaluate("() => ({ hash: location.hash, scrollY: window.scrollY })")

    pg.goto(BASE + "#/b/crm/leads")
    pg.wait_for_timeout(3000)
    pg.set_viewport_size({"width": 794, "height": 1123})   # A4 potret @96 dpi
    pg.emulate_media(media="print")
    pg.wait_for_timeout(800)
    out["print"] = pg.evaluate("""() => { const g = document.querySelector('.board-grid');
      const cw = document.documentElement.clientWidth;
      const lanes = [...document.querySelectorAll('.board-lane')];
      return { client_width: cw, scroll_width: g.scrollWidth, overflow_x: getComputedStyle(g).overflowX,
               whole_lanes: lanes.filter(l => l.getBoundingClientRect().right <= cw + 1).length, lanes: lanes.length }; }""")
    pg.screenshot(path=f"{OUT}/s30r-papan-cetak-a4.png", full_page=False)
    pg.emulate_media(media="screen")
    pg.set_viewport_size({"width": 1440, "height": 900})

    card = out["card"] or {}
    lines = " ".join(card.get("lines") or [])
    kv = dict((k, v) for k, v in (out["activity_detail"]["kv"] or []))
    finishers = [k for k, _ in (out["activity_detail"]["kv"] or []) if k == "Diselesaikan oleh"]

    out["checks"] = {
        "the_card_draws_at_most_a_hundred_rows": card.get("drawn") == 100,
        "and_says_the_real_total_not_the_drawn_one": f"{out['activities_total']} terbuka" in lines,
        "and_admits_what_it_cut": f"100 dari {out['activities_total']} digambar" in lines,
        "the_derived_date_still_names_the_earliest_open_activity": "Telepon paling awal (S30r)" in lines,
        "the_activity_screen_names_its_parent": kv.get("Dokumen", "").startswith(induk.get("code") or "@"),
        "and_links_to_it": out["activity_detail"]["parent_links"] == [f"#/d/crm/leads/{induk.get('id')}"],
        "the_finisher_is_named_once": len(finishers) == 1 and kv.get("Diselesaikan oleh") == "Administrator Sistem",
        "the_daily_queue_can_be_asked_for": "Keadaan" in (out["activity_list"]["filters"] or []),
        "and_the_notification_link_survives": out["activity_list"]["state_value"] == "overdue"
            and out["activity_list"]["hash"].endswith("state=overdue"),
        "and_it_shows_the_late_work_without_the_finished_work":
            any("S30r-LEWAT" in row for row in out["activity_list"]["rows"])
            and not any("Kunjungan pertama (S30r)" in row for row in out["activity_list"]["rows"]),
        "unassigned_has_a_control": "Belum ditugaskan" in (out["leads_list"]["filters"] or []),
        "and_its_link_really_filters": out["leads_list"]["hash"].endswith("unassigned=1")
            and all(out["leads_list"]["owners"]) and len(out["leads_list"]["owners"]) > 0,
        "a_board_card_announces_itself_as_a_button": (out["board_card"] or {}).get("role") == "button",
        "space_opens_the_card_instead_of_scrolling": out["space_key"]["hash"].startswith("#/d/crm/leads/")
            and out["space_key"]["scrollY"] == 0,
        "every_lane_fits_on_paper": out["print"]["whole_lanes"] == out["print"]["lanes"] == 6,
        "and_the_board_stops_scrolling_sideways_on_paper": out["print"]["overflow_x"] == "visible",
    }
    out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S30_pipeline_crm_mobile")
def s30m(browser):
    """Papan enam kolom di 390 px: kolomnya menggulir DI DALAM .board-grid, dan
    halamannya tidak menggulir mendatar. Enam kolom adalah papan terlebar di
    aplikasi ini (papan PR dan NCR punya empat), jadi kalau ada papan yang
    mendorong halaman melebar, papan inilah."""
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    try:
        login(pg, "admin@nusantara.test")
        pg.goto(BASE + "#/b/crm/leads")
        pg.wait_for_timeout(3500)
        out = pg.evaluate("""() => {
          const grid = document.querySelector('.board-grid');
          return {
            lanes: grid ? grid.querySelectorAll('.board-lane').length : 0,
            cards: document.querySelectorAll('.board-card').length,
            grid_scrolls: grid ? grid.scrollWidth > grid.clientWidth + 1 : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
            owner_line: [...document.querySelectorAll('.board-card')].some(c => /Belum ditugaskan/.test(c.innerText)),
          };
        }""")
        pg.screenshot(path=f"{OUT}/s30-papan-ponsel-f3.png", full_page=False)
        out["checks"] = {
            "the_board_renders_on_a_phone": out["lanes"] == 6 and out["cards"] > 0,
            "the_lanes_scroll_inside_their_own_box": out["grid_scrolls"] is True,
            "the_page_never_scrolls_sideways": out["page_scrolls_sideways"] is False,
            "an_unowned_card_still_names_it": out["owner_line"] is True,
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()


# --------------------------------------------------------------------- F-4

# Titik proyek demo PRJ-2026-002 (Instalasi ELV & Data Center Bank Artha
# Nusantara). Dibaca dari prj_projects, bukan dikarang: skenario yang memakai
# koordinat sendiri mengukur jarak ke tempat yang tidak ada proyeknya.
SITE_2 = {"latitude": -6.21462, "longitude": 106.82066}


def _absensi_read(pg):
    """Apa yang layar Absensi Saya KATAKAN — bukan apa yang disimpannya."""
    return pg.evaluate("""() => {
      const days = [...document.querySelectorAll('.absensi-day')].map(card => ({
        head: card.querySelector('h2').innerText.trim(),
        sides: [...card.querySelectorAll('.absensi-side')].map(side => ({
          label: side.querySelector('strong').innerText.trim(),
          badge: (side.querySelector('.badge') || {}).innerText,
          lines: [...side.querySelectorAll('.cell-sub')].map(n => n.innerText.trim()),
        })),
      }));
      return {
        linked: !document.querySelector('.alert.info') ||
          !/belum ditautkan/i.test(document.querySelector('.alert.info').innerText),
        info: document.querySelector('.alert.info') ? document.querySelector('.alert.info').innerText.trim() : null,
        buttons: [...document.querySelectorAll('.absensi-actions button')].map(b => b.innerText.trim()),
        geofence_line: [...document.querySelectorAll('.card-body > .cell-sub')]
          .map(n => n.innerText.trim()).filter(t => /Radius lokasi/.test(t))[0] || null,
        queue: [...document.querySelectorAll('.upload-item')].map(r => ({
          state: r.dataset.state, text: r.querySelector('.cell-sub').innerText.trim(),
          buttons: [...r.querySelectorAll('button')].map(b => b.innerText.trim()),
        })),
        days,
      };
    }""")


def _punch(pg, label):
    """Menekan satu tombol absen dan menunggu ANTREANNYA KOSONG.

    Bukan menunggu ".toast": toast hidup 5,2 detik, jadi toast absen sebelumnya
    masih di layar saat tombol berikutnya ditekan — dan pengukuran yang berhenti
    di situ membaca kalimat absen yang SALAH (terukur 8 Sep 2026: "Absensi
    terkirim." milik absen masuk dibaca sebagai jawaban absen pulang). Antrean
    kosong adalah tanda yang tidak bisa keliru: butirnya sudah dibuang
    forget().
    """
    before = set(toasts(pg))
    click(pg, f"button:has-text('{label}')")
    pg.wait_for_function("() => document.querySelectorAll('.upload-item').length === 0", timeout=30000)
    pg.wait_for_timeout(600)
    fresh = [t for t in toasts(pg) if t not in before]
    return fresh or toasts(pg)


def _reset_absensi_today():
    """Hapus baris absensi HARI INI milik karyawan yang dipakai S31.

    Tanpa ini skenario tidak idempoten: jalankan dua kali pada server yang sama
    dan absen masuk kedua menjawab "sudah tercatat … dan tetap dipakai" —
    perilaku yang BENAR, tetapi bukan yang sedang diukur, jadi syaratnya merah
    karena alasan yang tidak ada hubungannya dengan kodenya. Bukti yang hanya
    bisa direproduksi di atas basis data perawan bukan bukti.
    """
    con = sqlite3.connect(DB)
    try:
        row = con.execute("select employee_id from users where email = ?",
                          ("teknisi@nusantara.test",)).fetchone()
        if not row or row[0] is None:
            return None
        employee_id = row[0]
        ids = [r[0] for r in con.execute(
            "select id from hr_attendances where employee_id = ? and date(date) = date('now','localtime')",
            (employee_id,))]
        for attendance_id in ids:
            con.execute("delete from hr_attendance_corrections where attendance_id = ?", (attendance_id,))
            con.execute("delete from core_attachments where attachable_type like '%Attendance' and attachable_id = ?",
                        (attendance_id,))
        con.execute("delete from hr_attendances where employee_id = ? and date(date) = date('now','localtime')",
                    (employee_id,))
        con.commit()
        return employee_id
    finally:
        con.close()


def _selfie_count(employee_id):
    con = sqlite3.connect(DB)
    try:
        return con.execute(
            "select count(*) from core_attachments a "
            "join hr_attendances t on t.id = a.attachable_id "
            "where a.attachable_type like '%Attendance' and t.employee_id = ? "
            "and date(t.date) = date('now','localtime')",
            (employee_id,)).fetchone()[0]
    finally:
        con.close()


@scenario("S31_absensi_gps")
def s31(browser):
    """F-4 — absen masuk/pulang dari ponsel: di dalam radius, di luar radius, dan
    TANPA posisi sama sekali. Yang diuji bukan kolomnya melainkan kalimatnya:
    "Di lokasi" tidak boleh muncul untuk absen yang posisinya tidak pernah
    terukur, dan jarak yang tidak diketahui harus bergaris, bukan 0 m.

    teknisi@nusantara.test dipilih dengan sengaja: ia punya users.employee_id
    (kartu karyawan 7) dan TIDAK punya satu pun izin hr.*. Kalau absen menuntut
    izin HR, layar ini tidak dipakai siapa pun yang benar-benar berdiri di
    lapangan."""
    out = {"employee_id": _reset_absensi_today()}

    # (1) DI DALAM radius: konteks berdiri di titik proyek PRJ-2026-002.
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True,
                              geolocation={**SITE_2, "accuracy": 12}, permissions=["geolocation"])
    pg = ctx.new_page()
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e).split("\n")[0][:160]))
    try:
        login(pg, "teknisi@nusantara.test")
        pg.goto(BASE + "#/absensi-saya")
        pg.wait_for_selector(".absensi-actions button", timeout=20000)

        # select_option(label=…) menuntut teks PERSIS; regex tidak diterima.
        pg.select_option(".card select", index=pg.evaluate(
            "() => [...document.querySelector('.card select').options].findIndex(o => o.text.includes('PRJ-2026-002'))"))
        pg.wait_for_timeout(300)
        out["inside_toast"] = _punch(pg, "Absen masuk tanpa foto")
        pg.wait_for_timeout(1200)
        out["inside"] = _absensi_read(pg)
        pg.screenshot(path=f"{OUT}/s31-absensi-di-lokasi.png", full_page=False)

        out["page_scrolls_sideways"] = pg.evaluate(
            "() => document.documentElement.scrollWidth > window.innerWidth + 1")
        # Empat tombol, bukan delapan: dua load() beruntun yang keduanya
        # menempel adalah cacat yang pernah ada di sini (8 Sep 2026). TINGGI
        # ikut diukur — lebar adalah dimensi yang CSS-nya sudah benar, dan
        # syarat yang hanya melihat lebar lolos pada tombol setinggi 1 px.
        out["buttons_box"] = pg.evaluate(
            "() => [...document.querySelectorAll('.absensi-actions button')]"
            ".map(b => ({ w: Math.round(b.getBoundingClientRect().width),"
            "             h: Math.round(b.getBoundingClientRect().height) }))")
        out["button_widths"] = [b["w"] for b in out["buttons_box"]]
        out["limit_announced_before_the_photo"] = pg.evaluate(
            "() => /maksimal 5 MB/.test(document.querySelector('#view').innerText)")

        # (1b) SELFIE: satu absen DENGAN foto, supaya jalur lampiran benar-benar
        #      dijalankan. Tanpa ini AttachableDocuments/AttachmentService —
        #      butir keenam spesifikasi paket — tidak tersentuh harness sama
        #      sekali, dan syarat "ada kartu Lampiran" di S31s hijau di atas
        #      basis data tanpa satu pun lampiran.
        before = _selfie_count(out["employee_id"]) if out["employee_id"] else 0
        # nth(0), bukan teksnya: label tombol pertama berganti menjadi
        # "Absen masuk (sudah tercatat)" begitu hari itu punya absen masuk,
        # dan selektor berbasis teks lalu menunggu tombol yang tidak akan
        # pernah muncul.
        CLICKS[0] += 1
        with pg.expect_file_chooser() as chooser:
            pg.locator(".absensi-actions button").nth(0).click()
        chooser.value.set_files({"name": "selfie-s31.jpg", "mimeType": "image/jpeg",
                                 "buffer": padded_jpeg(120 * 1024)})
        pg.wait_for_function("() => document.querySelectorAll('.upload-item').length === 0", timeout=30000)
        pg.wait_for_timeout(800)
        out["selfie"] = {"before": before,
                         "after": _selfie_count(out["employee_id"]) if out["employee_id"] else 0}
    finally:
        out["console_errors"] = errors
        ctx.close()

    # (2) DI LUAR radius: ~8 km ke selatan, proyek yang sama. KONTEKS BARU, bukan
    #     set_geolocation di konteks lama: devicePosition() menerima fix
    #     ber-umur sampai 60 detik (maximumAge), jadi konteks yang baru saja
    #     absen di titik proyek akan memakai ulang fix itu dan mengukur 0 m.
    ctxo = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True,
                               geolocation={"latitude": SITE_2["latitude"] - 0.072,
                                            "longitude": SITE_2["longitude"], "accuracy": 15},
                               permissions=["geolocation"])
    pgo = ctxo.new_page()
    try:
        login(pgo, "teknisi@nusantara.test")
        pgo.goto(BASE + "#/absensi-saya")
        pgo.wait_for_selector(".absensi-actions button", timeout=20000)
        pgo.select_option(".card select", index=pgo.evaluate(
            "() => [...document.querySelector('.card select').options].findIndex(o => o.text.includes('PRJ-2026-002'))"))
        pgo.wait_for_timeout(300)
        out["outside_toast"] = _punch(pgo, "Absen pulang tanpa foto")
        pgo.wait_for_timeout(1500)
        out["outside"] = _absensi_read(pgo)
        pgo.screenshot(path=f"{OUT}/s31-absensi-di-luar.png", full_page=False)
    finally:
        ctxo.close()

    # (3) IZIN LOKASI DITOLAK: konteks tanpa permissions=["geolocation"].
    #     Absensinya HARUS tetap tersimpan, dan jaraknya HARUS bergaris.
    ctx2 = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg2 = ctx2.new_page()
    try:
        login(pg2, "teknisi@nusantara.test")
        pg2.goto(BASE + "#/absensi-saya")
        pg2.wait_for_selector(".absensi-actions button", timeout=20000)
        # Absen pulang: hari ini absen masuknya sudah ada (bagian 1) dan yang
        # kedua ditolak dengan sengaja — di sini yang diuji adalah TANPA posisi.
        out["denied_toast"] = _punch(pg2, "Absen pulang tanpa foto")
        pg2.wait_for_timeout(1200)
        out["denied"] = _absensi_read(pg2)
        pg2.screenshot(path=f"{OUT}/s31-absensi-tanpa-lokasi.png", full_page=False)
    finally:
        ctx2.close()

    # (4) LURING: butirnya bertahan, barisnya menawarkan "Kirim ulang", dan
    #     tidak ada satu pun kalimat yang mengaku terkirim.
    ctx3 = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True,
                               geolocation={**SITE_2, "accuracy": 12}, permissions=["geolocation"])
    pg3 = ctx3.new_page()
    try:
        login(pg3, "teknisi@nusantara.test")
        pg3.goto(BASE + "#/absensi-saya")
        pg3.wait_for_selector(".absensi-actions button", timeout=20000)
        ctx3.set_offline(True)
        click(pg3, "button:has-text('Absen pulang tanpa foto')")
        # Luring: antreannya TIDAK akan kosong — yang ditunggu justru barisnya
        # berhenti di keadaan 'failed' dengan tombol "Kirim ulang".
        pg3.wait_for_function(
            "() => [...document.querySelectorAll('.upload-item')].some(r => r.dataset.state === 'failed')",
            timeout=30000)
        pg3.wait_for_timeout(600)
        out["offline"] = _absensi_read(pg3)
        out["offline_ribbon"] = pg3.evaluate(
            "() => { const r = document.querySelector('.offline-ribbon'); return r && !r.hidden ? r.innerText.trim() : null; }")
        pg3.screenshot(path=f"{OUT}/s31-absensi-luring.png", full_page=False)

        # Muat ulang HALAMAN saat masih luring: butir yang hanya hidup di memori
        # akan lenyap di sini, dan orangnya tidak akan pernah tahu.
        ctx3.set_offline(False)
        pg3.reload()
        pg3.wait_for_selector(".absensi-actions button", timeout=20000)
        pg3.wait_for_timeout(800)
        out["after_reload"] = _absensi_read(pg3)
        if pg3.locator("button:has-text('Kirim ulang')").count():
            click(pg3, "button:has-text('Kirim ulang')")
            pg3.wait_for_selector(".toast", timeout=20000)
            pg3.wait_for_timeout(1000)
            out["retry_toast"] = toasts(pg3)
        out["after_retry"] = _absensi_read(pg3)
    finally:
        ctx3.close()

    # (5) AKUN TANPA KARTU KARYAWAN: gudang (users.employee_id NULL).
    ctx4 = browser.new_context(viewport={"width": 1440, "height": 900})
    pg4 = ctx4.new_page()
    try:
        login(pg4, "warehouse@nusantara.test")
        pg4.goto(BASE + "#/absensi-saya")
        pg4.wait_for_timeout(2500)
        out["unlinked"] = _absensi_read(pg4)
        out["unlinked_menu"] = pg4.evaluate(
            "() => [...document.querySelectorAll(\"nav.nav a[href='#/absensi-saya']\")].length")
    finally:
        ctx4.close()

    def sides(block, day=0):
        return {s["label"]: s for s in block["days"][day]["sides"]} if block["days"] else {}

    inside = sides(out["inside"])
    outside = sides(out["outside"])
    denied = sides(out["denied"])

    out["checks"] = {
        "a_technician_with_no_hr_permission_reaches_the_screen":
            out["inside"]["linked"] is True and len(out["inside"]["buttons"]) == 4,
        "the_screen_says_the_radius_before_the_button_is_pressed":
            bool(out["inside"]["geofence_line"]) and "TETAP tersimpan" in out["inside"]["geofence_line"],
        "standing_on_site_reads_as_in_place":
            inside.get("Absen masuk", {}).get("badge") == "Di lokasi",
        "and_the_toast_repeats_the_servers_own_sentence":
            any("Di lokasi proyek" in t for t in out["inside_toast"]),
        "eight_kilometres_away_is_flagged_not_refused":
            outside.get("Absen pulang", {}).get("badge") == "Di luar lokasi"
            and any("DI LUAR" in t for t in out["outside_toast"]),
        "and_it_was_still_recorded":
            any("Tercatat" in line or "Jam server" in line for line in outside.get("Absen pulang", {}).get("lines", [])),
        "a_refused_location_still_records_the_attendance":
            denied.get("Absen pulang", {}).get("badge") == "Lokasi tidak terukur",
        "and_never_claims_zero_metres":
            any("Jarak ke titik proyek: —" in line for line in denied.get("Absen pulang", {}).get("lines", []))
            and not any("0 m" in line for line in denied.get("Absen pulang", {}).get("lines", [])),
        "offline_the_row_stays_and_offers_a_retry":
            any(r["state"] == "failed" and "Kirim ulang" in r["buttons"] for r in out["offline"]["queue"]),
        "and_the_buttons_do_not_vanish_with_the_list":
            len(out["offline"]["buttons"]) == 4,
        "and_the_ribbon_tells_the_truth_about_it":
            bool(out["offline_ribbon"]) and "Kirim ulang" in (out["offline_ribbon"] or ""),
        "the_queued_punch_survives_a_page_reload":
            any(r["state"] == "failed" for r in out["after_reload"]["queue"]),
        "and_sending_it_again_empties_the_queue":
            out["after_retry"]["queue"] == [],
        "an_account_with_no_employee_card_is_told_why":
            out["unlinked"]["linked"] is False and "belum ditautkan" in (out["unlinked"]["info"] or ""),
        "and_the_menu_entry_is_still_there_for_it":
            out["unlinked_menu"] == 1,
        "the_phone_page_never_scrolls_sideways":
            out["page_scrolls_sideways"] is False,
        "the_action_card_is_drawn_once_not_twice":
            len(out["button_widths"]) == 4,
        "the_buttons_are_full_width_on_a_phone":
            bool(out["button_widths"]) and min(out["button_widths"]) > 280,
        "and_tall_enough_for_a_thumb":
            bool(out["buttons_box"]) and min(b["h"] for b in out["buttons_box"]) >= 44,
        "the_photo_limit_is_announced_before_the_photo_is_taken":
            out["limit_announced_before_the_photo"] is True,
        "a_selfie_really_reaches_the_attachment_machinery":
            out["selfie"]["after"] == out["selfie"]["before"] + 1,
        "the_screen_raises_no_console_error":
            out["console_errors"] == [],
    }
    out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S31_absensi_gps_supervisor")
def s31s(pg):
    """Sisi pengawas: panel Rincian di layar Absensi Harian menunjukkan apa yang
    tercatat dari ponsel, dan koreksinya MENUNTUT alasan. Alasan wajib bukan
    gaya rumah — sejak absensi bisa diisi orangnya sendiri, menyimpan di panel
    ini menimpa catatan seseorang tentang dirinya."""
    login(pg, "hr@nusantara.test")
    pg.goto(BASE + "#/absensi")
    pg.wait_for_selector("table.data", timeout=20000)
    # Lembar terbuka pada TANGGAL HARI INI dan proyek terakhir yang dipilih;
    # baris yang S31 tulis ada di hari ini, jadi tidak ada yang perlu diubah —
    # tetapi tunggu sampai kolom "Tersimpan" benar-benar terisi sebelum
    # menghitung tombol Rincian.
    pg.wait_for_timeout(2000)

    out = {"detail_buttons": pg.locator("button:has-text('Rincian')").count()}

    if not out["detail_buttons"]:
        out["SKIPPED"] = ("Tidak ada baris absensi tersimpan pada tanggal hari ini di salinan DB ini, "
                          "jadi tidak ada tombol Rincian untuk dibuka. Jalankan S31 lebih dulu pada "
                          "server yang sama.")
        return out

    click(pg, "button:has-text('Rincian')")
    pg.wait_for_selector(".modal", timeout=15000)
    pg.wait_for_timeout(1500)

    out["panel"] = pg.evaluate("""() => {
      const m = document.querySelector('.modal');
      return {
        cards: [...m.querySelectorAll('.card-head h2')].map(h => h.innerText.trim()),
        sides: [...m.querySelectorAll('.absensi-side')].map(s => s.innerText.replace(/\s+/g, ' ').trim()),
        fields: [...m.querySelectorAll('.field label, .field .label')].map(l => l.innerText.trim()),
        has_reason: !!m.querySelector('textarea'),
        attachments: m.querySelectorAll('.attachment-row, .attachment-name').length,
      };
    }""")
    pg.screenshot(path=f"{OUT}/s31-rincian-pengawas.png", full_page=False)

    # Menyimpan TANPA alasan: server menolak, dan panelnya tetap terbuka.
    click(pg, ".modal button:has-text('Simpan koreksi')")
    pg.wait_for_timeout(2500)
    out["no_reason"] = {"toasts": toasts(pg), "modal_open": pg.locator(".modal").count() > 0}

    out["checks"] = {
        "the_supervisor_panel_shows_both_sides": len(out["panel"]["sides"]) == 2,
        "and_offers_a_correction_form_with_a_reason_box": out["panel"]["has_reason"] is True,
        # Judul kartunya SELALU ada untuk pemegang hr.view, jadi "ada kartu
        # Lampiran" hijau juga di atas basis data tanpa satu pun lampiran
        # (terukur 8 Sep 2026). Yang diperiksa: fotonya benar-benar terdaftar.
        "and_a_selfie_card_with_the_selfie_in_it":
            "Lampiran" in out["panel"]["cards"] and out["panel"]["attachments"] >= 1,
        "and_the_trail": "Jejak koreksi" in out["panel"]["cards"],
        "saving_without_a_reason_is_refused": any("Alasan" in t for t in out["no_reason"]["toasts"]),
        "and_the_panel_stays_open_so_the_typing_is_not_lost": out["no_reason"]["modal_open"] is True,
    }
    out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
    out["ok"] = not out["failed_checks"]
    return out




# --------------------------------------------------------------------- F-6
#
# Dua skenario, dan keduanya mengukur hal yang TIDAK BISA dilihat suite PHP:
#
#   S32  ambang yang menang tertulis di layar bersama angka yang kalah, dan
#        menekan "Buat PR draf" DUA KALI hanya menghasilkan satu PR — dibuktikan
#        di peramban, bukan di service;
#   S33  label F/LBL benar-benar TERGAMBAR oleh Chromium (SVG yang cacat tidak
#        menggambar apa pun sementara HTML-nya tetap 200), dan layar pindai
#        mengucapkan kalimat yang BERBEDA untuk keempat keadaan kameranya.
#
# Fixture-nya ditanam lewat API dan DIKEMBALIKAN sesudahnya: keduanya berjalan
# di atas SALINAN basis data demo, dan skenario yang meninggalkan aturan reorder
# atau barcode ganda akan mengubah angka skenario orang lain pada putaran
# berikutnya.

F6_WAREHOUSE = "WH-PRJ-2026-001"   # gudang site PRJ-2026-001, canon CONVENTIONS §8
F6_ITEM = "ITM-0001"               # Semen Portland 50kg, saldo 350 di gudang itu
F6_POINT = 400                     # di ATAS saldo → barisnya muncul karena ATURAN
F6_ORDER_QTY = 500


def _f6_ids(tok):
    """id gudang dan item canon. Tidak dikarang: dibaca dari daftar hidup."""
    _, wh = api(f"inventory/warehouses?q={F6_WAREHOUSE}", tok)
    _, it = api(f"inventory/items?q={F6_ITEM}", tok)
    w = next((r for r in wh.get("data", []) if r["code"] == F6_WAREHOUSE), None)
    i = next((r for r in it.get("data", []) if r["code"] == F6_ITEM), None)
    return (w or {}).get("id"), (i or {}).get("id")


def _f6_plant_rule(tok, warehouse_id, item_id, point=F6_POINT, order_qty=F6_ORDER_QTY):
    """Aturan reorder untuk pasangan itu, dibuat atau disetel ulang.

    POST kedua atas pasangan yang sama ditolak 422 dengan kalimatnya sendiri
    (itu justru aturannya), jadi putaran kedua harus MENYUNTING yang ada —
    kalau tidak skenario ini jatuh pada run kedua di mesin yang sama."""
    _, existing = api(f"inventory/reorder-rules?warehouse_id={warehouse_id}&item_id={item_id}", tok)
    row = next(iter(existing.get("data", [])), None)
    body = {"warehouse_id": warehouse_id, "item_id": item_id,
            "reorder_point": point, "reorder_qty": order_qty, "is_active": True,
            "notes": "Ditanam harness S32."}
    if row:
        api(f"inventory/reorder-rules/{row['id']}", tok, "PUT", body)
        return row["id"], False
    s, d = api("inventory/reorder-rules", tok, "POST", body)
    return d.get("data", {}).get("id"), True


def _f6_pick_target(tok):
    """Pasangan gudang × item yang BISA diusulkan, DIPILIH DARI DATA.

    Versi pertama skenario ini memaku pasangan canon (WH-PRJ-2026-001 ×
    ITM-0001) dan jatuh: pada basis data demo item itu SUDAH menjadi baris PR
    disetujui PR/2026/II/0001, jadi barisnya dilewati sejak awal dan PR yang
    menutupinya bukan PR yang dibuat skenario ini. Kegagalan itu adalah
    fiturnya bekerja, bukan fiturnya rusak — tetapi skenario yang menganggapnya
    kegagalan tidak bisa dipercaya.

    Jadi targetnya dicari: untuk tiap saldo bukan-nol, aturan ditanam dengan
    titik DI ATAS saldonya lalu usulan dibaca; pasangan pertama yang muncul
    TIDAK dilewati adalah yang dipakai. Aturan yang tidak terpakai dibuang lagi
    di tempat, jadi loop ini tidak meninggalkan apa pun."""
    _, balances = api("inventory/stock/balances?per_page=200&nonzero=1", tok)

    for row in balances.get("data", [])[:12]:
        item, wh = row.get("item") or {}, row.get("warehouse") or {}
        qty = float(row.get("qty") or 0)
        if qty <= 0 or not item.get("id") or not wh.get("id"):
            continue

        point = int(qty) + 10
        rule_id, created = _f6_plant_rule(tok, wh["id"], item["id"], point, point + 40)
        _, proposal = api(f"inventory/reorder/proposal?warehouse_id={wh['id']}", tok)
        planted = next((r for r in proposal.get("data", {}).get("rows", [])
                        if r["item_id"] == item["id"] and r["warehouse_id"] == wh["id"]), None)

        if planted and not planted["skipped"]:
            return {"warehouse": wh, "item": item, "qty": qty, "point": point,
                    "order_qty": point + 40, "rule_id": rule_id, "created": created,
                    "min_stock": float(item.get("min_stock") or 0)}

        _f6_drop_rule(tok, rule_id, created)

    return None


def _f6_drop_rule(tok, rule_id, created):
    if created and rule_id:
        api(f"inventory/reorder-rules/{rule_id}", tok, "DELETE")


def _f6_drop_requisitions(tok, codes):
    """PR draf yang dibuat skenario ini dibuang lagi.

    Kalau tidak, putaran berikutnya menemukan itemnya sudah ada di PR terbuka
    dan tidak akan pernah bisa mengukur pembuatan PR-nya lagi — idempotensi
    yang justru diuji skenario ini akan mengunci skenarionya sendiri."""
    for code in codes:
        _, listing = api(f"procurement/purchase-requisitions?q={code}", tok)
        for row in listing.get("data", []):
            if row.get("code") == code:
                api(f"procurement/purchase-requisitions/{row['id']}", tok, "DELETE")


F6_ROWS = """() => [...document.querySelectorAll('table.data tbody tr')].map(tr => ({
  text: tr.innerText.replace(/\\s+/g, ' ').trim(),
  cells: [...tr.querySelectorAll('td')].map(td => td.innerText.replace(/\\s+/g, ' ').trim()),
}))"""


@scenario("S32_reorder_usulan_pr")
def s32(pg):
    """Ambang yang MENANG di layar, dan usulan PR yang idempoten.

    Dua hal yang tidak bisa dibuktikan suite PHP: (1) baris "perlu dipesan
    ulang" benar-benar mencetak ambang aturan BESERTA stok minimum item yang
    digantikannya — sebuah baris yang hanya menulis 410 tidak memberi tahu
    siapa pun bahwa angka perusahaannya 200; (2) menekan tombolnya dua kali
    menghasilkan satu PR, dilihat dari layar."""
    tok = token_for("admin@nusantara.test")
    target = _f6_pick_target(tok)
    out = {}
    if target is None:
        out["SKIPPED"] = ("Tidak ada satu pun pasangan gudang × item pada salinan DB ini yang bisa "
                          "diusulkan: semuanya sudah menjadi baris PR terbuka.")
        return out

    item, wh = target["item"], target["warehouse"]
    out["target"] = {"item": item["code"], "warehouse": wh["code"], "qty": target["qty"],
                     "point": target["point"], "min_stock": target["min_stock"],
                     "order_qty": target["order_qty"]}
    made = []
    errors = []
    pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
    pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))

    try:
        login(pg, "admin@nusantara.test")

        # ---------------------------------------------- daftar aturan reorder
        pg.goto(BASE + "#/r/inventory/reorder-rules")
        pg.wait_for_selector("table.data", timeout=20000)
        pg.wait_for_timeout(1200)
        assert_screen(pg, "#/r/inventory/reorder-rules")
        out["rules_screen"] = {
            # innerText, jadi kepala kolom datang dalam huruf besar (text-transform
            # CSS). Dibandingkan tanpa memedulikan besar-kecil huruf: yang diuji
            # adalah ADANYA kolom itu, bukan cara CSS menulisnya.
            "headers": pg.evaluate("() => [...document.querySelectorAll('table.data thead th')].map(t => t.innerText.trim())"),
            "planted_row": pg.evaluate(
                "(code) => { const tr = [...document.querySelectorAll('table.data tbody tr')]"
                ".find(r => r.innerText.includes(code)); return tr ? tr.innerText.replace(/\\s+/g,' ').trim() : null; }",
                item["code"]),
        }
        pg.screenshot(path=f"{OUT}/s32-aturan-reorder.png", full_page=False)

        # -------------------------------------- tab "Perlu dipesan ulang"
        pg.goto(BASE + "#/stock")
        pg.wait_for_selector(".tabs button", timeout=20000)
        click(pg, ".tabs button:has-text('Perlu dipesan ulang')")
        pg.wait_for_selector("table.data tbody tr", timeout=20000)
        pg.wait_for_timeout(800)
        out["stock_low"] = {
            "headers": pg.evaluate("() => [...document.querySelectorAll('table.data thead th')].map(t => t.innerText.trim())"),
            "rows": pg.evaluate(F6_ROWS),
            "priority_sentence": pg.evaluate(
                "() => { const n = [...document.querySelectorAll('.card-body .cell-sub')]"
                ".find(e => /Ambang tiap baris/.test(e.innerText)); return n ? n.innerText.replace(/\\s+/g,' ').trim() : null; }"),
        }
        pg.screenshot(path=f"{OUT}/s32-stok-perlu-dipesan.png", full_page=False)

        # Item yang sama bisa punya baris di DUA gudang; barisnya dikenali dari
        # kode item DAN nama gudangnya. Versi pertama skenario ini mencocokkan
        # kode item saja dan mengukur baris gudang yang lain.
        def mine(rows):
            return next((r for r in rows if item["code"] in r["text"] and wh["name"] in r["text"]), None)

        planted = mine(out["stock_low"]["rows"])
        out["planted_low_row"] = planted

        # ------------------------------------------------ usulan pesan ulang
        pg.goto(BASE + "#/usulan-pesan-ulang")
        pg.wait_for_selector("table.data tbody tr", timeout=20000)
        pg.wait_for_timeout(800)
        assert_screen(pg, "#/usulan-pesan-ulang", "Usulan Pesan Ulang")
        out["before"] = {
            "stats": pg.evaluate("() => [...document.querySelectorAll('.stat')].map(s => s.innerText.replace(/\\s+/g,' ').trim())"),
            "why_skipped_card": pg.evaluate(
                "() => { const c = [...document.querySelectorAll('.card')].find(x => /Yang dilewati/.test(x.innerText));"
                "return c ? c.innerText.replace(/\\s+/g,' ').trim() : null; }"),
            "rows": pg.evaluate(F6_ROWS),
            "create_button": pg.locator("button:has-text('Buat PR draf')").count(),
        }
        pg.screenshot(path=f"{OUT}/s32-usulan-sebelum.png", full_page=False)

        before_codes = {r["code"] for r in api("procurement/purchase-requisitions?per_page=200", tok)[1].get("data", [])}

        # ------------------------------------------------- tekan sekali…
        click(pg, "button:has-text('Buat PR draf')")
        pg.wait_for_timeout(4000)
        out["first_press"] = {"toasts": toasts(pg), "hash": pg.evaluate("() => location.hash")}

        after = api("procurement/purchase-requisitions?per_page=200", tok)[1].get("data", [])
        made = [r["code"] for r in after if r["code"] not in before_codes]
        out["created_codes"] = made
        out["created_statuses"] = sorted({r["status"] for r in after if r["code"] in made})

        # ------------------------------------------------- …lalu sekali lagi
        pg.goto(BASE + "#/usulan-pesan-ulang")
        pg.wait_for_selector("table.data tbody tr", timeout=20000)
        pg.wait_for_timeout(1000)
        out["after"] = {
            "stats": pg.evaluate("() => [...document.querySelectorAll('.stat')].map(s => s.innerText.replace(/\\s+/g,' ').trim())"),
            "rows": pg.evaluate(F6_ROWS),
            "create_button": pg.locator("button:has-text('Buat PR draf')").count(),
            "all_skipped_line": pg.evaluate(
                "() => { const n = [...document.querySelectorAll('.card-head .cell-sub')]"
                ".find(e => /sudah ada di PR atau PO terbuka/.test(e.innerText)); return n ? n.innerText.trim() : null; }"),
        }
        pg.screenshot(path=f"{OUT}/s32-usulan-sesudah.png", full_page=False)

        # Tombolnya sudah hilang dari layar — tetapi layar yang menyembunyikan
        # tombol bukan gerbang. Endpoint-nya dipanggil LANGSUNG, dan ia harus
        # menolak dengan daftar kosong, bukan membuat PR kedua.
        s2, again = api("inventory/reorder/requisitions", tok, "POST", {})
        out["second_press_direct"] = {"status": s2, "created": again.get("data", {}).get("created"),
                                      "message": again.get("data", {}).get("message")}

        final = api("procurement/purchase-requisitions?per_page=200", tok)[1].get("data", [])
        out["requisitions_after_second_press"] = len([r for r in final if r["code"] not in before_codes])

        skipped_row = mine(out["after"]["rows"])
        out["skipped_row"] = skipped_row
        out["console_errors"] = errors

        headers_lower = [h.lower() for h in out["rules_screen"]["headers"]]
        min_text = f"stok min. item {int(target['min_stock']) if target['min_stock'] == int(target['min_stock']) else target['min_stock']}"

        out["checks"] = {
            "the_rules_screen_prints_the_item_minimum_the_rule_replaces":
                "stok min. item" in headers_lower,
            "and_the_planted_rule_is_listed_with_both_numbers":
                bool(out["rules_screen"]["planted_row"])
                and str(target["point"]) in out["rules_screen"]["planted_row"],
            "the_stock_screen_states_the_priority_in_words":
                bool(out["stock_low"]["priority_sentence"])
                and "MENGGANTIKAN" in out["stock_low"]["priority_sentence"].upper(),
            "a_row_governed_by_a_rule_shows_the_winning_threshold":
                bool(planted) and str(target["point"]) in planted["cells"][3],
            "and_the_item_minimum_it_beat":
                bool(planted) and min_text in planted["cells"][3],
            "and_names_the_rule_as_its_source":
                bool(planted) and "Aturan reorder gudang ini" in planted["cells"][3],
            "and_the_order_quantity_comes_from_the_rule_not_the_shortage":
                bool(planted) and "jumlah pesan aturan" in planted["cells"][5],
            # "PR ATAU PO terbuka" sejak putaran perbaikan F-6: PO boleh dibuat
            # tanpa PR, dan penjaga yang hanya membaca baris PR mengusulkan lagi
            # barang yang uangnya sudah terikat.
            "the_proposal_screen_states_the_skip_rule_before_it_bites":
                bool(out["before"]["why_skipped_card"])
                and "PR ATAU PO terbuka" in out["before"]["why_skipped_card"],
            "pressing_the_button_once_creates_a_draft_requisition":
                len(made) >= 1 and out["created_statuses"] == ["draft"],
            "the_row_that_was_proposable_now_says_it_was_skipped":
                bool(skipped_row) and "Dilewati" in skipped_row["text"],
            "and_names_the_requisition_that_covers_it":
                bool(skipped_row) and any(code in skipped_row["text"] for code in made),
            "and_the_create_button_is_gone_because_nothing_is_left":
                out["after"]["create_button"] == 0 and bool(out["after"]["all_skipped_line"]),
            "and_the_endpoint_itself_refuses_a_second_run_not_just_the_button":
                out["second_press_direct"]["created"] == []
                and "sudah ada di PR atau PO terbuka" in (out["second_press_direct"]["message"] or ""),
            "so_a_second_run_raises_no_second_requisition":
                out["requisitions_after_second_press"] == len(made),
            "the_screens_raise_no_console_error":
                out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        _f6_drop_requisitions(tok, made)
        _f6_drop_rule(tok, target["rule_id"], target["created"])


@scenario("S32_reorder_usulan_pr_mobile")
def s32m(browser):
    """390 px. Enam kolom kekurangan stok adalah tabel terlebar yang F-6
    tambahkan; kalau ada yang mendorong halaman melebar, tabel inilah. Dan
    kalimat prioritasnya harus tetap terbaca — sebuah keterangan yang terpotong
    di ponsel adalah keterangan yang tidak ada."""
    tok = token_for("admin@nusantara.test")
    warehouse_id, item_id = _f6_ids(tok)
    if not warehouse_id or not item_id:
        return {"SKIPPED": f"Gudang {F6_WAREHOUSE} atau item {F6_ITEM} tidak ada di salinan DB ini."}

    rule_id, created = _f6_plant_rule(tok, warehouse_id, item_id)
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    errors = []
    pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
    pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))
    try:
        login(pg, "admin@nusantara.test")

        pg.goto(BASE + "#/usulan-pesan-ulang")
        pg.wait_for_selector("table.data tbody tr", timeout=20000)
        pg.wait_for_timeout(1500)
        out = pg.evaluate("""() => {
          const wrap = document.querySelector('.table-wrap');
          const why = [...document.querySelectorAll('.card')].find(c => /Yang dilewati/.test(c.innerText));
          return {
            rows: document.querySelectorAll('table.data tbody tr').length,
            wrap_scrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
            why_visible: !!(why && why.checkVisibility()),
            why_text: why ? why.innerText.replace(/\\s+/g, ' ').trim() : null,
            stats: [...document.querySelectorAll('.stat')].length,
          };
        }""")
        pg.screenshot(path=f"{OUT}/s32-usulan-ponsel.png", full_page=False)

        pg.goto(BASE + "#/stock")
        pg.wait_for_selector(".tabs button", timeout=20000)
        tap(pg, ".tabs button:has-text('Perlu dipesan ulang')")
        pg.wait_for_selector("table.data tbody tr", timeout=20000)
        pg.wait_for_timeout(1200)
        out["stock"] = pg.evaluate("""() => {
          const wrap = document.querySelector('.table-wrap');
          return {
            wrap_scrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
            source_labels: [...document.querySelectorAll('table.data tbody .cell-sub')].map(e => e.innerText.trim()),
          };
        }""")
        pg.screenshot(path=f"{OUT}/s32-stok-ponsel.png", full_page=False)
        out["console_errors"] = errors

        out["checks"] = {
            "the_proposal_table_renders_on_a_phone": out["rows"] > 0,
            "the_wide_table_scrolls_inside_its_own_box": out["wrap_scrolls"] is True,
            "the_page_never_scrolls_sideways": out["page_scrolls_sideways"] is False,
            "the_skip_rule_is_still_readable_on_a_phone":
                out["why_visible"] is True and "PR ATAU PO terbuka" in (out["why_text"] or ""),
            "the_stock_table_also_stays_inside_its_box":
                out["stock"]["wrap_scrolls"] is True and out["stock"]["page_scrolls_sideways"] is False,
            "and_every_row_still_names_where_its_threshold_came_from":
                any("Aturan reorder gudang ini" in s or "Stok minimum item" in s
                    for s in out["stock"]["source_labels"]),
            "the_screens_raise_no_console_error": out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()
        _f6_drop_rule(tok, rule_id, created)


# ------------------------------------------------------------- S33 (F-6)

F6_PROBE_BARCODE = "F6PROBE9001"

# BarcodeDetector PALSU. Dipasang lewat add_init_script sebelum satu baris pun
# skrip halaman berjalan, jadi layar melihatnya persis seperti ia melihat milik
# Chrome Android — dan jalur "didukung" bisa diukur di host yang perambannya
# tidak punya satu pun.
FAKE_DETECTOR = """
window.BarcodeDetector = class {
  static getSupportedFormats() { return Promise.resolve(['code_128']); }
  constructor() {}
  detect() { return Promise.resolve([{ rawValue: '%s', format: 'code_128' }]); }
};
"""

# …dan kebalikannya: peramban yang TIDAK punya BarcodeDetector, yaitu Safari di
# iPhone. Dihapus, bukan disembunyikan — layar memeriksa typeof.
NO_DETECTOR = "delete window.BarcodeDetector;"


def _f6_set_barcode(tok, item_id, value):
    """Ganti kolom barcode saja — dengan MEMBAWA SELURUH kartu itemnya.

    ItemUpdateRequest menuntut name/category_id/unit/item_type; PUT yang hanya
    membawa `barcode` ditolak 422 dan barcode-nya tidak pernah berubah, tanpa
    satu pun tanda di skenario. Versi pertama S33 melakukan itu dan mengukur
    "tidak ada barcode ganda" sebagai keberhasilan."""
    _, cur = api(f"inventory/items/{item_id}", tok)
    row = cur.get("data") or {}
    body = {
        "name": row.get("name"),
        "category_id": row.get("category_id") or (row.get("category") or {}).get("id"),
        "unit": row.get("unit"),
        "item_type": row.get("item_type"),
        "min_stock": row.get("min_stock"),
        "last_price": row.get("last_price"),
        "is_active": row.get("is_active"),
        "barcode": value,
    }
    return api(f"inventory/items/{item_id}", tok, "PUT", body)[0]


@scenario("S33_label_dan_pindai")
def s33(pg):
    """Label F/LBL yang benar-benar TERGAMBAR, dan pemindai yang mengucapkan
    kalimat berbeda untuk keadaan yang berbeda.

    SVG yang cacat tidak menggambar apa pun sementara lembarnya tetap 200 dan
    setiap uji PHP tetap hijau, jadi lembarnya disuntikkan ke dalam dokumen dan
    kotak batasnya DIUKUR."""
    tok = token_for("admin@nusantara.test")
    _, item_id = _f6_ids(tok)
    if not item_id:
        return {"SKIPPED": f"Item {F6_ITEM} tidak ada di salinan DB ini."}

    _, before = api(f"inventory/items/{item_id}", tok)
    original = (before.get("data") or {}).get("barcode")

    # Item KEDUA, supaya barcode ganda bisa diukur di layar.
    _, others = api("inventory/items?per_page=200", tok)
    second = next((r for r in others.get("data", []) if r["id"] != item_id), None)
    _, before2 = api(f"inventory/items/{second['id']}", tok) if second else (None, {})
    original2 = (before2.get("data") or {}).get("barcode") if second else None

    errors = []
    pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
    pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))

    try:
        login(pg, "admin@nusantara.test")

        # ------------------------------------------------- tombol di layar item
        pg.goto(BASE + f"#/d/inventory/items/{item_id}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(2000)
        # Tombol formulir rumah pada layar detail GENERIK duduk di dalam menu
        # "Cetak ▾" (printMenu, T2.6) — bukan sebagai tombol lepas. Versi
        # pertama skenario ini membaca tombol bilahnya saja dan menyimpulkan
        # tombolnya tidak ada.
        out = {"item_id": item_id, "action_bar": pg.evaluate(
            "() => [...document.querySelectorAll('.page-head .actions button')].map(b => b.innerText.trim()).filter(Boolean)")}
        click(pg, ".page-head .actions button.menu-trigger:has-text('Cetak')")
        pg.wait_for_timeout(600)
        out["print_menu"] = pg.evaluate(
            "() => [...document.querySelectorAll('.menu-item')].map(b => b.innerText.trim()).filter(Boolean)")
        pg.screenshot(path=f"{OUT}/s33-item-tombol-label.png", full_page=False)
        pg.keyboard.press("Escape")
        pg.wait_for_timeout(300)

        # ---- lembar labelnya, DIBUKA SEBAGAI HALAMAN lewat menu Cetak sungguhan
        #
        # BUKAN fetch + `host.innerHTML = doc.body.innerHTML`, yang dipakai versi
        # pertama skenario ini. Cara itu membuang <head><style> lembarnya, jadi
        # yang diukur adalah potongan HTML TANPA satu pun aturan tata letaknya:
        # `.stiker` terukur 819,95 mm alih-alih 62 mm, dan syarat "svg_width >
        # 100" tetap hijau untuk barcode 100 karakter yang sudah terbukti tidak
        # terbaca pada raster 600 dpi. Ia juga tidak pernah menjalankan
        # openPrintable()/printWhenLoaded(), sehingga lembar tanpa pembungkus
        # `.lembar` — yang membuat dialog cetak TIDAK PERNAH muncul — lolos
        # tanpa suara.
        #
        # Yang diukur sekarang hanya bisa dilihat di halaman sungguhan: lebar
        # `.stiker` dalam MILIMETER, lebar SVG SETELAH tata letak, lebar modul
        # cetak, tinggi batang, apakah <text>-nya keluar viewBox, dan apakah
        # window.print benar-benar terpanggil.
        pg.context.add_init_script(
            "window.__printCalls = 0; window.print = function () { window.__printCalls++; };")
        click(pg, ".page-head .actions button.menu-trigger:has-text('Cetak')")
        pg.wait_for_timeout(500)
        with pg.context.expect_page(timeout=25000) as popup_info:
            click(pg, ".menu-item:has-text('Label Barcode (12 stiker)')")
        sheet = popup_info.value
        sheet.wait_for_timeout(9000)
        out["sheet"] = sheet.evaluate("""() => {
          const probe = document.createElement('div');
          probe.style.cssText = 'position:absolute;width:10mm';
          document.body.appendChild(probe);
          const pxPerMm = probe.getBoundingClientRect().width / 10;
          probe.remove();

          const st = document.querySelector('.stiker');
          const svg = document.querySelector('.stiker svg');
          const vb = svg ? svg.getAttribute('viewBox').split(' ').map(Number) : null;
          const rects = svg ? [...svg.querySelectorAll('g rect')] : [];
          const painted = rects.filter(r => r.getBoundingClientRect().width > 0);
          const texts = svg ? [...svg.querySelectorAll('text')] : [];
          const svgMm = svg ? svg.getBoundingClientRect().width / pxPerMm : null;

          return {
            print_calls: window.__printCalls,
            has_lembar: !!document.querySelector('.lembar'),
            stickers: document.querySelectorAll('.stiker').length,
            form_code: /Form F\\/LBL/.test(document.body.innerText),
            note: (document.querySelector('.catatan') || {}).innerText || null,
            svg_present: !!svg,
            sticker_mm: st ? +(st.getBoundingClientRect().width / pxPerMm).toFixed(3) : null,
            svg_mm: svgMm === null ? null : +svgMm.toFixed(3),
            svg_attr_width: svg ? svg.getAttribute('width') : null,
            module_mm: svgMm === null ? null : +(svgMm / vb[2] * 2).toFixed(4),
            bar_height_mm: svgMm === null ? null
              : +(+rects[0].getAttribute('height') * svgMm / vb[2]).toFixed(3),
            bars: rects.length,
            bars_painted: painted.length,
            human_readable: texts.map(t => t.textContent).join(''),
            text_inside_viewbox: texts.every(t => t.getBBox().x >= -0.5
              && t.getBBox().x + t.getBBox().width <= vb[2] + 0.5),
            aria: svg ? svg.getAttribute('aria-label') : null,
            page_scrolls_sideways: document.body.scrollWidth > document.body.clientWidth,
          };
        }""")
        sheet.screenshot(path=f"{OUT}/s33-lembar-label.png", full_page=False)
        sheet.close()

        # -------------------------- lembar yang DITOLAK, dibuka SEBAGAI HALAMAN
        #
        # Cabang penolakan ("kode ini tidak muat pada lebar yang masih
        # terpindai") sampai putaran kedua F-6 hanya diuji sebagai KALIMAT:
        # assertStringContains "Barcode tidak dicetak" + kelas 'tanpa-barcode'.
        # Di balik keduanya, kodenya dicetak sebagai SATU baris monospace tanpa
        # aturan pemenggalan — 63 karakter = 120,43 mm dan 100 karakter =
        # 191,10 mm di dalam kotak 56,5 mm, menimpa dua stiker tetangganya dan
        # mendorong `.lembar` ke 322 mm di atas kertas selebar 194 mm. Lolos
        # 62 uji PHP hijau dan kelima skenario ini, karena S33 hanya pernah
        # membuka lembar untuk item ber-barcode PENDEK.
        #
        # Diukur pada media=print, karena inilah lembar yang dicetak.
        out["refused_writes"] = [_f6_set_barcode(tok, item_id, "A" * 100)]
        pg.goto(BASE + f"#/d/inventory/items/{item_id}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(1500)
        click(pg, ".page-head .actions button.menu-trigger:has-text('Cetak')")
        pg.wait_for_timeout(500)
        with pg.context.expect_page(timeout=25000) as refused_info:
            click(pg, ".menu-item:has-text('Label Barcode (12 stiker)')")
        refused = refused_info.value
        refused.wait_for_timeout(6000)
        refused.emulate_media(media="print")
        refused.wait_for_timeout(400)
        out["refused_sheet"] = refused.evaluate("""() => {
          const probe = document.createElement('div');
          probe.style.cssText = 'position:absolute;left:-9999px;width:10mm';
          document.body.appendChild(probe);
          const pxPerMm = probe.getBoundingClientRect().width / 10;
          probe.remove();

          const st = document.querySelector('.stiker');
          // Baris SATU stiker, bukan seluruh lembar: 12 stiker × 100 karakter
          // menyambung menjadi 1200 karakter yang "utuh" tanpa satu pun
          // stikernya utuh (versi pertama syarat ini melakukan itu).
          const lines = st ? [...st.querySelectorAll('.kode-tangan')] : [];
          const box = st ? st.getBoundingClientRect().width / pxPerMm : null;
          const lembar = document.querySelector('.lembar');

          return {
            svg_present: !!document.querySelector('.stiker svg'),
            note: (document.querySelector('.catatan') || {}).innerText || null,
            stickers: document.querySelectorAll('.stiker').length,
            hand_lines_per_sticker: st ? st.querySelectorAll('.kode-tangan').length : 0,
            // BERAPA BARIS CETAK yang sebenarnya dipakai tiap <div> baris.
            // PHP memutuskan penggalannya untuk font 9 pt; kalau lembarnya
            // mencetak font LAIN, jaring CSS-nya tetap menahan luapan — jadi
            // tidak ada satu pun syarat lain yang berubah — dan yang tercetak
            // menjadi dua baris rapuh per baris yang dihitung. Range
            // getClientRects() menghitung kotak baris yang BENAR-BENAR
            // digambar, jadi 9 pt yang dicetak 14 pt terlihat di sini.
            hand_line_boxes: lines.map(l => {
              const range = document.createRange();
              range.selectNodeContents(l);
              return range.getClientRects().length;
            }),
            sticker_mm: box === null ? null : +box.toFixed(2),
            // Kotak ISI stikernya: lebar stiker dikurangi padding kiri-kanan.
            sticker_inner_mm: st ? +((st.clientWidth
              - parseFloat(getComputedStyle(st).paddingLeft)
              - parseFloat(getComputedStyle(st).paddingRight)) / pxPerMm).toFixed(2) : null,
            // LEBAR TEKS-nya, bukan lebar KOTAKNYA — dan lebar teks SEBELUM
            // jaring CSS-nya ikut campur.
            //
            // Versi sebelumnya membaca `scrollWidth` <div> pembungkusnya.
            // Dengan `overflow-wrap: anywhere` terpasang teksnya tidak pernah
            // meluap, jadi scrollWidth == clientWidth == lebar kotak apa pun
            // isinya: syaratnya lulus dengan sisa 0,10 mm — persis
            // toleransinya sendiri — dan tidak bisa merah lagi.
            //
            // Range.getClientRects() atas isi simpulnya mengukur TEKS, tetapi
            // kotak-kotak barisnya juga dipotong jaring itu: sebuah baris yang
            // PHP hitung terlalu panjang dipatahkan peramban dan terukur
            // kembali ~selebar kotaknya. Diukur, dengan mutasi font 14 pt yang
            // penggalannya dihitung untuk 9 pt: bentuk Range memulangkan
            // 56,49 mm terhadap kotak 56,40 mm (merah hanya berkat margin yang
            // dinyatakan di bawah; dengan toleransi lama +0,1 mm ia HIJAU),
            // sementara lebar teks yang sebenarnya 83,24 mm. Maka yang diukur
            // di sini adalah lebar baris itu TANPA pematahan: salinan teksnya
            // di dalam probe
            // `white-space: pre` dengan font yang BENAR-BENAR dipakai
            // menggambarnya. Itulah satu-satunya angka yang menjawab
            // pertanyaannya — "apakah penggalan yang dihitung PHP muat pada
            // font yang dicetak lembarnya" — dan ia merah untuk 14 pt yang
            // penggalannya dihitung untuk 9 pt.
            widest_hand_line_mm: lines.length
              ? +(Math.max(...lines.map(l => {
                  const cs = getComputedStyle(l);
                  const probe = document.createElement('span');
                  probe.style.cssText = 'position:absolute;left:-9999px;top:0;'
                    + 'white-space:pre;overflow-wrap:normal;word-break:normal;';
                  probe.style.fontFamily = cs.fontFamily;
                  probe.style.fontSize = cs.fontSize;
                  probe.style.fontWeight = cs.fontWeight;
                  probe.style.fontStyle = cs.fontStyle;
                  probe.style.fontStretch = cs.fontStretch;
                  probe.style.letterSpacing = cs.letterSpacing;
                  probe.textContent = l.textContent;
                  document.body.appendChild(probe);
                  const w = probe.getBoundingClientRect().width;
                  probe.remove();
                  return w;
                })) / pxPerMm).toFixed(2) : null,
            // Kode yang tercetak harus tetap UTUH: yang diketik ulang orangnya
            // adalah kode ini, dan kode yang kehilangan ekornya adalah kode LAIN.
            hand_code: lines.map(l => l.textContent).join(''),
            lembar_scroll_mm: lembar ? +(lembar.scrollWidth / pxPerMm).toFixed(2) : null,
            lembar_client_mm: lembar ? +(lembar.clientWidth / pxPerMm).toFixed(2) : null,
            body_scroll: document.body.scrollWidth,
            body_client: document.body.clientWidth,
          };
        }""")
        # Sisa ruang yang benar-benar ada — angka, bukan hanya lulus/tidak.
        if (out["refused_sheet"]["sticker_inner_mm"] is not None
                and out["refused_sheet"]["widest_hand_line_mm"] is not None):
            out["refused_sheet"]["hand_line_margin_mm"] = round(
                out["refused_sheet"]["sticker_inner_mm"] - out["refused_sheet"]["widest_hand_line_mm"], 2)
        refused.screenshot(path=f"{OUT}/s33-lembar-ditolak.png", full_page=False)
        refused.close()

        # ------------------------------------------------- pindai: jalur ketik
        pg.goto(BASE + "#/pindai")
        pg.wait_for_selector("input[aria-label='Barcode atau kode item']", timeout=20000)
        pg.fill("input[aria-label='Barcode atau kode item']", F6_ITEM)
        # BUKAN "button:has-text('Cari')": tombol pencarian global
        # (.global-search) juga memuat kata itu dan Playwright memilih yang
        # pertama — klik mendarat di palet Ctrl+K, bukan di formulir ini.
        click(pg, "form button[type=submit]")
        pg.wait_for_timeout(2500)
        out["manual"] = pg.evaluate("""() => ({
          cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText.trim()),
          reason: [...document.querySelectorAll('.cell-sub')].map(e => e.innerText.trim()).filter(t => /Cocok pada/.test(t)),
          warehouses: [...document.querySelectorAll('table.data tbody tr')].map(r => r.innerText.replace(/\\s+/g,' ').trim()),
        })""")
        pg.screenshot(path=f"{OUT}/s33-pindai-ketik.png", full_page=False)

        # --------------------------------------- pindai: kode yang tidak ada
        pg.fill("input[aria-label='Barcode atau kode item']", "F6-TIDAK-ADA-KODE-INI")
        # BUKAN "button:has-text('Cari')": tombol pencarian global
        # (.global-search) juga memuat kata itu dan Playwright memilih yang
        # pertama — klik mendarat di palet Ctrl+K, bukan di formulir ini.
        click(pg, "form button[type=submit]")
        pg.wait_for_timeout(2500)
        out["not_found"] = pg.evaluate(
            "() => { const e = document.querySelector('.empty'); return e ? e.innerText.replace(/\\s+/g,' ').trim() : null; }")

        # --------------------------------------------- barcode ganda (jebakan E)
        out["probe_writes"] = [_f6_set_barcode(tok, item_id, F6_PROBE_BARCODE)]
        if second:
            out["probe_writes"].append(_f6_set_barcode(tok, second["id"], F6_PROBE_BARCODE))
        pg.fill("input[aria-label='Barcode atau kode item']", F6_PROBE_BARCODE)
        # BUKAN "button:has-text('Cari')": tombol pencarian global
        # (.global-search) juga memuat kata itu dan Playwright memilih yang
        # pertama — klik mendarat di palet Ctrl+K, bukan di formulir ini.
        click(pg, "form button[type=submit]")
        pg.wait_for_timeout(2500)
        out["duplicate"] = pg.evaluate("""() => ({
          warning: [...document.querySelectorAll('.card')].some(c => /dipakai lebih dari satu item/.test(c.innerText)),
          message: (([...document.querySelectorAll('.card')].find(c => /dipakai lebih dari satu item/.test(c.innerText)) || {}).innerText || '')
            .replace(/\\s+/g, ' ').trim(),
          item_cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText.trim())
            .filter(t => !/dipakai lebih dari satu/.test(t) && !/Ketik kodenya/.test(t) && !/kamera/i.test(t)),
        })""")
        pg.screenshot(path=f"{OUT}/s33-pindai-barcode-ganda.png", full_page=False)
        out["console_errors"] = errors

        out["checks"] = {
            "the_item_screen_offers_the_label_sheet":
                any("Label Barcode" in b for b in out["print_menu"]),
            # `.lembar` adalah kontrak print.js: tanpanya lembarnya tergambar
            # dan dialog cetak TIDAK PERNAH muncul, tanpa satu pun pesan.
            "the_print_button_really_opens_the_print_dialog":
                out["sheet"]["print_calls"] == 1 and out["sheet"]["has_lembar"] is True,
            "and_carries_its_form_code": out["sheet"]["form_code"] is True,
            "and_prints_exactly_the_stickers_the_button_asked_for": out["sheet"]["stickers"] == 12,
            # MILIMETER DI KERTAS, bukan piksel atribut SVG. Nama syarat lama
            # ("chromium_actually_paints_the_barcode") mengklaim jauh lebih
            # daripada `svg_width > 100` yang sebenarnya diperiksanya, dan tetap
            # hijau untuk barcode yang tidak terbaca pemindai mana pun.
            "the_printed_module_is_wide_enough_to_scan":
                (out["sheet"]["module_mm"] or 0) >= 0.25,
            "and_the_symbol_fits_its_sticker_box_so_css_never_shrinks_it":
                out["sheet"]["svg_mm"] is not None
                and out["sheet"]["svg_mm"] <= out["sheet"]["sticker_mm"] - 4.9
                and (out["sheet"]["svg_attr_width"] or "").endswith("mm"),
            "and_the_bars_are_at_least_15_percent_as_tall_as_the_symbol_is_wide":
                (out["sheet"]["bar_height_mm"] or 0) >= 0.15 * (out["sheet"]["svg_mm"] or 1) - 0.05,
            "every_bar_has_a_width": out["sheet"]["bars"] > 20
                and out["sheet"]["bars_painted"] == out["sheet"]["bars"],
            "and_the_human_readable_line_is_under_it_and_never_clipped":
                F6_ITEM in (out["sheet"]["human_readable"] or "")
                and out["sheet"]["text_inside_viewbox"] is True,
            "and_the_sheet_says_which_code_it_encoded":
                "kode item" in (out["sheet"]["note"] or "") or "barcode pemasok" in (out["sheet"]["note"] or ""),
            # LEMBAR YANG DITOLAK, DALAM MILIMETER — bukan sebagai kalimat.
            "the_refused_probe_really_reached_the_item_card":
                out["refused_writes"] == [200],
            "a_code_too_long_to_scan_prints_no_bars_and_says_so":
                out["refused_sheet"]["svg_present"] is False
                and "Barcode tidak dicetak" in (out["refused_sheet"]["note"] or ""),
            # LEBAR TEKS terhadap kotak isi stikernya, DENGAN MARGIN YANG
            # DINYATAKAN. Versi sebelumnya mengukur scrollWidth <div>
            # pembungkusnya dan lulus dengan sisa 0,10 mm — yaitu persis
            # toleransinya sendiri, karena dengan `overflow-wrap: anywhere`
            # scrollWidth == clientWidth == lebar kotak apa pun isinya.
            #
            # 1,0 mm, dan alasannya: PHP memenggal dengan perkiraan 0,62 em per
            # karakter monospace (28 karakter × 9 pt = 55,12 mm menurut
            # perkiraan itu), sementara lebar sesungguhnya bergantung font
            # sistem — diukur di Chromium headless 53,54 mm terhadap kotak
            # 56,40 mm, sisa 2,86 mm. Font yang lebih lebar daripada
            # perkiraannya membuat sisa itu menyusut; begitu ia habis, peramban
            # MEMATAHKAN barisnya (`hand_line_boxes` di bawah menangkap itu) dan
            # kertasnya berhenti sesuai dengan yang dihitung PHP. Margin ini
            # adalah peringatan yang datang LEBIH DULU.
            "and_its_handwritten_code_stays_inside_the_sticker_box_with_declared_room_to_spare":
                out["refused_sheet"]["widest_hand_line_mm"] is not None
                and out["refused_sheet"]["sticker_inner_mm"] is not None
                and out["refused_sheet"]["widest_hand_line_mm"]
                <= out["refused_sheet"]["sticker_inner_mm"] - 1.0,
            "and_the_sheet_never_grows_wider_than_the_page":
                out["refused_sheet"]["lembar_scroll_mm"] is not None
                and out["refused_sheet"]["lembar_scroll_mm"] <= out["refused_sheet"]["lembar_client_mm"] + 0.1
                and out["refused_sheet"]["body_scroll"] <= out["refused_sheet"]["body_client"],
            "and_the_code_a_person_retypes_is_still_whole":
                out["refused_sheet"]["hand_code"] == "A" * 100
                and out["refused_sheet"]["hand_lines_per_sticker"] == 4,
            # …dan penggalan yang DIHITUNG PHP adalah penggalan yang TERCETAK:
            # satu kotak baris per <div>, bukan dua karena fontnya ternyata
            # lebih besar daripada yang dipakai menghitungnya.
            "and_each_line_php_measured_is_one_printed_line":
                out["refused_sheet"]["hand_line_boxes"] == [1, 1, 1, 1],
            "typing_a_code_finds_the_item_and_its_stock_per_warehouse":
                len(out["manual"]["warehouses"]) > 0,
            "and_says_whether_it_matched_the_code_or_the_barcode":
                any("Cocok pada" in r for r in out["manual"]["reason"]),
            "an_unknown_code_is_told_where_to_look":
                "kolom Barcode" in (out["not_found"] or ""),
            "the_duplicate_probe_really_reached_both_item_cards":
                out["probe_writes"] == [200, 200],
            "a_barcode_on_two_items_is_never_resolved_silently":
                out["duplicate"]["warning"] is True and len(out["duplicate"]["item_cards"]) == 2,
            "and_the_warning_says_how_many_and_why_it_matters":
                "2 ITEM" in out["duplicate"]["message"] and "opname" in out["duplicate"]["message"],
            "the_screens_raise_no_console_error": out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        _f6_set_barcode(tok, item_id, original)
        if second:
            _f6_set_barcode(tok, second["id"], original2)


@scenario("S33_pindai_keadaan_kamera")
def s33k(browser):
    """EMPAT KEADAAN KAMERA, EMPAT KALIMAT — diukur, bukan dibaca dari kode.

    Ini yang paling mudah salah dan paling mahal salahnya: separuh ponsel
    lapangan adalah iPhone, dan Safari tidak punya BarcodeDetector. Keempat
    konteks di bawah memalsukan tepat satu hal masing-masing dan menuntut
    kalimat yang BERBEDA. Sebuah layar yang menuliskan satu kalimat untuk
    keempatnya akan mengirim orang gudang mencari setelan izin di ponsel yang
    memang tidak punya kamera."""
    out = {}

    def read(ctx_kwargs, init_script, click_start):
        ctx = browser.new_context(**ctx_kwargs)
        if init_script:
            ctx.add_init_script(init_script)
        pg = ctx.new_page()
        errs = []
        pg.on("pageerror", lambda e: errs.append(str(e)))
        try:
            login(pg, "warehouse@nusantara.test")
            pg.goto(BASE + "#/pindai")
            pg.wait_for_selector(".card", timeout=20000)
            pg.wait_for_timeout(1500)
            if click_start and pg.locator("button:has-text('Nyalakan kamera')").count():
                pg.click("button:has-text('Nyalakan kamera')")
                pg.wait_for_timeout(4000)
            return pg, ctx, errs, pg.evaluate("""() => ({
              cards: [...document.querySelectorAll('.card-head h2')].map(h => h.innerText.trim()),
              body: [...document.querySelectorAll('.card-body')].map(b => b.innerText.replace(/\\s+/g, ' ').trim()),
              manual_input: !!document.querySelector("input[aria-label='Barcode atau kode item']"),
            })""")
        finally:
            pass

    # 1. Peramban tanpa BarcodeDetector — Safari di iPhone.
    pg, ctx, errs, res = read({"viewport": {"width": 1440, "height": 900}}, NO_DETECTOR, False)
    pg.screenshot(path=f"{OUT}/s33-kamera-tanpa-detector.png", full_page=False)
    out["no_detector"] = res | {"pageerrors": errs}
    ctx.close()

    # 2. Bukan konteks aman (http://) — peramban tidak akan pernah meminta kamera.
    #
    # TANPA FAKE_DETECTOR, dan itu seluruh isinya. Versi pertama skenario ini
    # menyuntikkan BarcodeDetector palsu KE DALAM halaman tidak aman — kombinasi
    # yang tidak pernah diproduksi platform mana pun: BarcodeDetector
    # ber-[SecureContext], jadi pada asal yang tidak aman ia undefined, dan
    # navigator.mediaDevices ikut undefined. Halaman tidak aman yang JUJUR
    # adalah halaman tanpa keduanya, dan hanya uji seperti itu yang bisa
    # membedakan urutan pemeriksaan yang benar dari yang salah. Diukur pada
    # hostname yang dipetakan ke loopback (--host-resolver-rules): dengan
    # urutan lama, pemakai http di Chrome Android membaca "buka halaman ini
    # dengan Chrome di Android".
    pg, ctx, errs, res = read({"viewport": {"width": 1440, "height": 900}},
                              NO_DETECTOR +
                              "delete navigator.mediaDevices;"
                              "Object.defineProperty(window, 'isSecureContext', { value: false, configurable: true });",
                              False)
    pg.screenshot(path=f"{OUT}/s33-kamera-bukan-https.png", full_page=False)
    out["insecure"] = res | {"pageerrors": errs}
    ctx.close()

    # 3. Detektor ada, izin kamera DITOLAK.
    pg, ctx, errs, res = read({"viewport": {"width": 1440, "height": 900}},
                              (FAKE_DETECTOR % F6_ITEM) +
                              "navigator.mediaDevices.getUserMedia = () => Promise.reject("
                              "Object.assign(new Error('denied'), { name: 'NotAllowedError' }));",
                              True)
    pg.screenshot(path=f"{OUT}/s33-kamera-ditolak.png", full_page=False)
    out["denied"] = res | {"pageerrors": errs}
    ctx.close()

    # 4. Detektor ada, izin bukan soal — TIDAK ADA kamera.
    pg, ctx, errs, res = read({"viewport": {"width": 1440, "height": 900}},
                              (FAKE_DETECTOR % F6_ITEM) +
                              "navigator.mediaDevices.getUserMedia = () => Promise.reject("
                              "Object.assign(new Error('none'), { name: 'NotFoundError' }));",
                              True)
    pg.screenshot(path=f"{OUT}/s33-kamera-tidak-ada.png", full_page=False)
    out["no_camera"] = res | {"pageerrors": errs}
    ctx.close()

    # 5. Detektor ada, kamera ada, DAN ia membaca sesuatu.
    ctx = browser.new_context(viewport={"width": 1440, "height": 900})
    ctx.add_init_script((FAKE_DETECTOR % F6_ITEM) +
                        "navigator.mediaDevices.getUserMedia = () => Promise.resolve("
                        "Object.assign(document.createElement('canvas').captureStream(1), {}));")
    pg = ctx.new_page()
    try:
        login(pg, "warehouse@nusantara.test")
        pg.goto(BASE + "#/pindai")
        pg.wait_for_selector("button:has-text('Nyalakan kamera')", timeout=20000)
        pg.click("button:has-text('Nyalakan kamera')")
        pg.wait_for_timeout(5000)
        out["scanning"] = pg.evaluate("""() => ({
          status: [...document.querySelectorAll('.card-body .cell-sub')].map(e => e.innerText.trim()),
          input_value: (document.querySelector("input[aria-label='Barcode atau kode item']") || {}).value || null,
          result_cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText.trim()),
          video: !!document.querySelector('video'),
        })""")
        pg.screenshot(path=f"{OUT}/s33-kamera-terbaca.png", full_page=False)
    finally:
        ctx.close()

    # 6. SIKLUS HIDUP TREK KAMERA — kelas cacat yang tidak bisa dilihat satu pun
    #    asersi yang menghitung tombol atau membaca kalimat status.
    #
    #    Melumpuhkan stopCamera() sepenuhnya (pindai.js: baris stop() diganti
    #    `;`) meninggalkan S33, S33k dan S33m HIJAU semuanya: kamera menyala
    #    selamanya dan gerbang buktinya lolos dengan tiga skenario hijau. Yang
    #    menemukannya adalah orang gudang yang baterainya habis sebelum jam dua,
    #    dan di Android kamera yang tidak dilepas menahan aplikasi lain dari
    #    memakainya sampai tabnya ditutup.
    #
    #    `grep -c "Matikan" harness-playwright.py` pernah menjawab 0.
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    ctx.add_init_script((FAKE_DETECTOR % F6_ITEM) + """
      window.__streams = []; window.__gum = 0; window.__stops = 0;
      const realStop = MediaStreamTrack.prototype.stop;
      MediaStreamTrack.prototype.stop = function () { window.__stops++; return realStop.apply(this, arguments); };
      navigator.mediaDevices = navigator.mediaDevices || {};
      navigator.mediaDevices.getUserMedia = async () => {
        window.__gum++;
        const c = document.createElement('canvas'); c.width = 320; c.height = 240;
        c.getContext('2d').fillRect(0, 0, 10, 10);
        const s = c.captureStream(5); window.__streams.push(s); return s;
      };
    """)
    pg = ctx.new_page()
    life_errors = []
    pg.on("pageerror", lambda e: life_errors.append(str(e)))
    try:
        login(pg, "warehouse@nusantara.test")
        pg.goto(BASE + "#/pindai")
        pg.wait_for_selector("button:has-text('Nyalakan kamera')", timeout=20000)

        tracks = """() => ({ gum: window.__gum, stops: window.__stops,
          streams: window.__streams.map(s => ({ active: s.active, tracks: s.getTracks().map(t => t.readyState) })) })"""

        click(pg, "button:has-text('Nyalakan kamera')")
        pg.wait_for_timeout(2000)
        out["lifecycle_on"] = pg.evaluate(tracks)

        click(pg, "button:has-text('Matikan kamera')")
        # 7 detik: cukup lama untuk melihat sebuah akuisisi KEDUA yang dipicu
        # pendengar bertumpuk menyelesaikan janjinya dan meninggalkan trek
        # pertamanya hidup.
        pg.wait_for_timeout(7000)
        out["lifecycle_off"] = pg.evaluate(tracks)
        out["lifecycle_screen"] = pg.evaluate("""() => ({
          buttons: [...document.querySelectorAll('#view button')].map(b => b.innerText.trim()),
          status: [...document.querySelectorAll('#view .cell-sub')].map(e => e.innerText.trim()),
          heights: [...document.querySelectorAll('#view button')]
            .map(b => [b.innerText.trim(), Math.round(b.getBoundingClientRect().height)]),
        })""")
        pg.screenshot(path=f"{OUT}/s33-kamera-dimatikan.png", full_page=False)

        # …dan jalur PINDAH RUTE, yang pengawas video.isConnected pegang.
        click(pg, "button:has-text('Nyalakan kamera')")
        pg.wait_for_timeout(2000)
        pg.goto(BASE + "#/dashboard")
        pg.wait_for_timeout(2500)
        out["lifecycle_route"] = pg.evaluate(tracks)
        out["lifecycle_pageerrors"] = life_errors
    finally:
        ctx.close()

    def ended(block):
        """Setiap trek pada setiap stream yang pernah diminta halaman ini
        BERAKHIR — bukan "tombolnya berubah", bukan "kalimatnya berganti"."""
        return (block["streams"] != []
                and all(s["active"] is False and all(t == "ended" for t in s["tracks"])
                        for s in block["streams"]))

    def said(block, needle):
        """Kalimatnya boleh berada di JUDUL kartu atau di badannya — keduanya
        dibaca orang yang sama, dan uji yang hanya membaca badannya menyatakan
        sebuah kalimat hilang padahal ia tercetak sebagai judul."""
        haystack = block.get("body", []) + block.get("cards", [])
        return any(needle.lower() in b.lower() for b in haystack)

    out["checks"] = {
        "a_browser_without_barcodedetector_says_exactly_that":
            said(out["no_detector"], "tidak menyediakan BarcodeDetector")
            and said(out["no_detector"], "Safari di iPhone"),
        "and_it_does_not_pretend_there_is_a_camera_button":
            "Pindai dengan kamera" not in out["no_detector"]["cards"],
        "and_the_manual_field_is_still_there":
            out["no_detector"]["manual_input"] is True,
        "and_nothing_throws_where_BarcodeDetector_does_not_exist":
            out["no_detector"]["pageerrors"] == [],
        "an_insecure_page_is_told_it_is_the_protocol_not_the_permission":
            said(out["insecure"], "bukan HTTPS"),
        "a_denied_permission_is_told_where_to_grant_it":
            said(out["denied"], "Izin kamera ditolak") and said(out["denied"], "setelan situs"),
        "a_device_with_no_camera_is_told_it_is_not_about_permission":
            said(out["no_camera"], "tidak punya kamera") and said(out["no_camera"], "Bukan soal izin"),
        "the_four_sentences_are_four_different_sentences":
            len({said(out[k], "tidak menyediakan BarcodeDetector") for k in ["no_detector", "insecure", "denied", "no_camera"]}) == 2
            and not said(out["denied"], "tidak punya kamera")
            and not said(out["no_camera"], "Izin kamera ditolak"),
        "a_working_scanner_fills_the_field_and_looks_the_item_up":
            out["scanning"]["input_value"] == F6_ITEM and out["scanning"]["video"] is True,
        "and_the_lookup_really_happened":
            any("Semen" in c or F6_ITEM in c for c in out["scanning"]["result_cards"]),
        # --------------------------------------------------- siklus hidup trek
        "starting_the_camera_asks_for_it_exactly_once":
            out["lifecycle_on"]["gum"] == 1 and len(out["lifecycle_on"]["streams"]) == 1,
        "turning_it_off_really_ends_every_track_it_ever_held":
            ended(out["lifecycle_off"]) and out["lifecycle_off"]["gum"] == 1,
        "and_it_does_not_quietly_ask_for_the_camera_again_on_the_way_out":
            out["lifecycle_off"]["stops"] == 1,
        "and_the_screen_only_says_the_camera_is_off_once_it_really_is":
            any("Kamera belum dinyalakan" in t for t in out["lifecycle_screen"]["status"])
            and "Nyalakan kamera" in out["lifecycle_screen"]["buttons"],
        "leaving_the_route_ends_its_tracks_too":
            ended(out["lifecycle_route"]),
        # Standar target sentuh rumah ini 42–46 px, di layar yang komentarnya
        # sendiri menyebut orang bersarung tangan sebagai alasan.
        "and_every_camera_button_is_a_thumb_sized_target":
            all(h >= 44 for _, h in out["lifecycle_screen"]["heights"]),
        "and_nothing_throws_along_the_way": out["lifecycle_pageerrors"] == [],
    }
    out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
    out["ok"] = not out["failed_checks"]
    return out


@scenario("S33_label_dan_pindai_mobile")
def s33m(browser):
    """390 px, dengan BarcodeDetector DIHAPUS — yaitu iPhone lapangan.

    Yang diukur bukan tata letaknya saja: pada ponsel yang tidak bisa memindai,
    isian ketiknya harus cukup lebar untuk jempol dan kalimat penjelasnya harus
    terbaca tanpa menggulir mendatar."""
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    ctx.add_init_script(NO_DETECTOR)
    pg = ctx.new_page()
    errors = []
    pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
    pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))
    try:
        login(pg, "warehouse@nusantara.test")
        pg.goto(BASE + "#/pindai")
        pg.wait_for_selector("input[aria-label='Barcode atau kode item']", timeout=20000)
        pg.wait_for_timeout(1200)

        out = pg.evaluate("""() => {
          const input = document.querySelector("input[aria-label='Barcode atau kode item']");
          const box = input.getBoundingClientRect();
          const btn = document.querySelector('form button[type=submit]');
          const bbox = btn ? btn.getBoundingClientRect() : null;
          return {
            input_width: Math.round(box.width),
            input_height: Math.round(box.height),
            button_height: bbox ? Math.round(bbox.height) : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
            cards: [...document.querySelectorAll('.card-head h2')].map(h => h.innerText.trim()),
            notice: [...document.querySelectorAll('.card-body')].map(b => b.innerText.replace(/\\s+/g, ' ').trim()),
          };
        }""")
        pg.screenshot(path=f"{OUT}/s33-pindai-ponsel.png", full_page=False)

        tap(pg, "input[aria-label='Barcode atau kode item']")
        pg.fill("input[aria-label='Barcode atau kode item']", F6_ITEM)
        tap(pg, "form button[type=submit]")
        pg.wait_for_timeout(3000)
        out["result"] = pg.evaluate("""() => ({
          cards: [...document.querySelectorAll('.card h2')].map(h => h.innerText.trim()),
          rows: document.querySelectorAll('table.data tbody tr').length,
          page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
          // Tombol "Buka kartu item" adalah SATU-SATUNYA jalan keluar dari
          // kartu hasil, dan versi pertama layar ini menggambarnya 28 px —
          // pada layar yang komentarnya sendiri menyebut orang bersarung
          // tangan sebagai alasan. S33m dulu hanya mengukur isian dan tombol
          // Cari; tombol hasil tidak pernah diukur pada lebar mana pun.
          buttons: [...document.querySelectorAll('#view button')]
            .map(b => [b.innerText.trim(), Math.round(b.getBoundingClientRect().height)]),
        })""")
        pg.screenshot(path=f"{OUT}/s33-pindai-ponsel-hasil.png", full_page=False)
        out["console_errors"] = errors

        out["checks"] = {
            # 390 px dikurangi padding kartu dan tombol Cari: separuh lebar
            # layar adalah ambang yang berarti, bukan angka bulat yang enak.
            "the_manual_field_is_wide_enough_to_type_a_barcode_into": out["input_width"] > 195,
            # Standar target sentuh rumah ini 42–46 px (.btn.lg = 46, catatan
            # app.css). Versi pertama layar ini memakai tinggi baku 34 px.
            "and_tall_enough_for_a_thumb": out["input_height"] >= 44 and (out["button_height"] or 0) >= 44,
            "the_phone_is_told_why_there_is_no_camera_button":
                any("tidak menyediakan BarcodeDetector" in n for n in out["notice"]),
            "and_no_camera_card_is_drawn": "Pindai dengan kamera" not in out["cards"],
            "the_page_never_scrolls_sideways": out["page_scrolls_sideways"] is False,
            "a_typed_code_still_finds_the_item_and_its_stock":
                out["result"]["rows"] > 0,
            "and_the_result_does_not_widen_the_page":
                out["result"]["page_scrolls_sideways"] is False,
            "and_every_button_on_the_result_is_a_thumb_sized_target":
                out["result"]["buttons"] != []
                and all(h >= 44 for _, h in out["result"]["buttons"]),
            "the_screen_raises_no_console_error": out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()


# ------------------------------------------------------------- S34 (F-7)
#
# Servis alat per hour-meter. Yang tidak bisa dibuktikan suite PHP ada tiga:
#
#  (1) SATUANNYA sampai ke layar. Registri ambang sudah lama mendeklarasikan
#      'unit' per entri dan layarnya tidak pernah membacanya — entri berjam
#      pertama akan mencetak "Rp 3.375,50" untuk 3.375,5 JAM, dan sebuah uji
#      PHP atas array-nya tidak akan melihat apa pun.
#  (2) "TIDAK TERUKUR" DIGARIS, bukan digambar nol, dan menyebut SEBAB yang
#      mana dari tiga sebab yang ada.
#  (3) KEDUA PEMICU tampil berdampingan — jam dan tanggal — di kartu alat dan
#      di daftar perawatan.
#
# Fixture ditanam lewat API, bukan SQL, supaya skenario ini bisa dijalankan
# ulang di salinan DB demo mana pun. Log jam TIDAK dihapus di akhir: register
# pembacaan bersifat append-only (EquipmentLogController menolak PUT/DELETE
# dengan kalimatnya sendiri), dan menanam angka yang sama dua kali diterima
# penjaga monotonnya. Catatan perawatan yang ditanam dihapus.

F7_ASSETS = {"doosan": "AST-0007", "komatsu": "AST-0001", "truk": "AST-0002",
             "splicer": "AST-0005", "rak": "AST-0006", "total_station": "AST-0003",
             "scaffolding": "AST-0004"}


def _f7_lookup(tok):
    """Kode aset -> id, dan id aset -> mobilisasi aktif pertamanya."""
    _, assets = api("assets/assets?per_page=200", tok)
    by_code = {a["code"]: a["id"] for a in assets.get("data", [])}
    _, deps = api("assets/deployments?per_page=200", tok)
    dep_of = {}
    for d in deps.get("data", []):
        dep_of.setdefault(d["asset_id"], d["id"])
    return by_code, dep_of


def _f7_ensure_deployed(tok, asset_id):
    """Mobilisasi TANPA log — sebab kedua "belum terukur", ditanam idempoten.

    Aset yang sudah termobilisasi dibiarkan apa adanya: menjalankan skenario
    ini dua kali pada salinan DB yang sama harus mengukur keadaan yang sama,
    dan register pembacaan tidak bisa dibersihkan (append-only)."""
    s, d = api(f"assets/assets/{asset_id}", tok)
    if (d.get("data") or {}).get("status") != "available":
        return "sudah termobilisasi"
    _, projects = api("projects?per_page=5", tok)
    project = (projects.get("data") or [{}])[0].get("id")
    return api(f"assets/assets/{asset_id}/deploy", tok, "POST", {
        "project_id": project, "deployed_from": date.today().isoformat(),
    })[0]


def _f7_plant_maintenance(tok, asset_id, hours, due_date=None, on=None):
    """`on` menggeser TANGGAL SERVIS-nya ke masa lalu.

    Dibutuhkan untuk menanam next_due_date yang SUDAH LEWAT: pintu tulisnya
    menuntut after:maintenance_date, jadi jadwal berikut yang lewat hanya bisa
    lahir dari kartu servis yang tanggalnya lebih lampau lagi."""
    s, d = api("assets/maintenances", tok, "POST", {
        "asset_id": asset_id,
        "maintenance_date": (on or date.today()).isoformat(),
        "maintenance_type": "service_rutin",
        "cost": 0,
        "description": "Fixture S34 (F-7).",
        "next_due_date": due_date,
        "next_due_hour_meter": hours,
    })
    return (d.get("data") or {}).get("id") if s == 201 else None


def _f7_plant_log(tok, deployment_id, hour_meter=None, fuel=None):
    body = {"deployment_id": deployment_id, "log_date": date.today().isoformat(),
            "notes": "Fixture S34 (F-7)."}
    if hour_meter is not None:
        body["hour_meter"] = hour_meter
    if fuel is not None:
        body["fuel_liters"] = fuel
    return api("assets/equipment-logs", tok, "POST", body)[0]


F7_CARD = """(label) => {
  const card = [...document.querySelectorAll('.card')].find(c => {
    const h = c.querySelector('.card-head h2');
    return h && h.innerText.trim() === label;
  });
  if (!card) return null;
  const head = card.querySelector('.card-head');
  return {
    badge: head ? (head.innerText.replace(/\\s+/g, ' ').trim()) : null,
    headers: [...card.querySelectorAll('table.data thead th')].map(t => t.innerText.trim()),
    rows: [...card.querySelectorAll('table.data tbody tr')].map(tr => ({
      cells: [...tr.querySelectorAll('td')].map(td => td.innerText.replace(/\\s+/g, ' ').trim()),
    })),
    text: card.innerText.replace(/\\s+/g, ' ').trim(),
  };
}"""

F7_DUE = """() => {
  const card = [...document.querySelectorAll('.card')].find(c => {
    const h = c.querySelector('.card-head h2');
    return h && h.innerText.trim().startsWith('Servis berikutnya');
  });
  if (!card) return null;
  return {
    title: (card.querySelector('.card-head h2') || {}).innerText || null,
    badge: (card.querySelector('.card-head .badge') || {}).innerText || null,
    stats: [...card.querySelectorAll('.stat')].map(s => ({
      label: (s.querySelector('.label') || {}).innerText || null,
      value: (s.querySelector('.value') || {}).innerText || null,
      delta: (s.querySelector('.delta') || {}).innerText || null,
    })),
    help: (card.querySelector('p.help') || {}).innerText || null,
    alert: (card.querySelector('.alert') || {}).innerText || null,
    text: card.innerText.replace(/\\s+/g, ' ').trim(),
    page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
  };
}"""


@scenario("S34_servis_alat_per_jam")
def s34(pg):
    """Enam alat, lima keadaan, dan tiga kalimat berbeda untuk "belum terukur".

    Ambang jam DAN kartu alat diukur pada halaman yang sama-sama sungguhan,
    karena keduanya membaca satu service dan keduanya bisa kehilangan
    satuannya sendirian."""
    tok = token_for("admin@nusantara.test")
    by_code, dep_of = _f7_lookup(tok)
    missing = [c for c in F7_ASSETS.values() if c not in by_code]
    if missing:
        return {"SKIPPED": f"Aset {', '.join(missing)} tidak ada di salinan DB ini."}

    ids = {k: by_code[c] for k, c in F7_ASSETS.items()}
    planted = []
    out = {"assets": ids}
    errors = []

    try:
        # --------------------------------------------------------- fixture
        # Doosan sudah punya pembacaan 3.240 dan 3.375,5 di data demo.
        planted.append(_f7_plant_maintenance(tok, ids["doosan"], 3400, "2026-12-01"))   # MENDEKATI, sisa 24,5
        out["plant_splicer_log"] = _f7_plant_log(tok, dep_of.get(ids["splicer"]), hour_meter=1240)
        planted.append(_f7_plant_maintenance(tok, ids["splicer"], 1200))                # LAMPAU, sisa -40
        out["plant_truk_log"] = _f7_plant_log(tok, dep_of.get(ids["truk"]), hour_meter=8150)  # TANPA_BATAS
        # Tiga sebab "belum terukur" pada TIGA alat, supaya ketiga kalimatnya
        # bisa dibaca dalam satu jalan dan skenarionya tetap idempoten.
        out["plant_rak_deploy"] = _f7_ensure_deployed(tok, ids["rak"])                  # mobilisasi, nol log
        planted.append(_f7_plant_maintenance(tok, ids["rak"], 8760))
        out["plant_komatsu_fuel"] = _f7_plant_log(tok, dep_of.get(ids["komatsu"]), fuel=150)
        planted.append(_f7_plant_maintenance(tok, ids["komatsu"], 5000))                # log BBM tanpa jam
        # Belum pernah dimobilisasi — DAN pemicu TANGGALNYA sudah lewat 86
        # hari sementara sisi jamnya tidak menghakimi apa pun. Kartu alat
        # adalah tempat kedua vonis berdiri bersebelahan, jadi ia juga tempat
        # sebuah lencana bisa terbaca sebagai vonis atas keduanya, dan sebuah
        # tanggal masa lalu bisa disebut "jadwal berikutnya".
        out["ts_due_date"] = (date.today() - timedelta(days=86)).isoformat()
        planted.append(_f7_plant_maintenance(tok, ids["total_station"], 500,
                                             due_date=out["ts_due_date"],
                                             on=date.today() - timedelta(days=150)))
        out["planted"] = planted

        login(pg, "admin@nusantara.test")
        # PENDENGAR DIPASANG SESUDAH LOGIN, BUKAN SEBELUM. login() sendiri
        # mendokumentasikan throttle 429 gerbang masuk dan mencoba ulang sampai
        # enam kali; percobaan yang di-throttle mendarat di console_errors dan
        # dihakimi sebagai cacat produk. Terukur: pada salinan DB yang sama,
        # jalan PERTAMA pasangan S34/S34m hijau dan jalan KEDUA jatuh pada
        # the_screens_raise_no_console_error dengan satu-satunya galat
        # "429 (Too Many Requests)". Yang diuji syarat itu adalah layar yang
        # skenario ini buka, bukan gerbang masuknya.
        pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
        pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))

        # ------------------------------------------------------ layar ambang
        pg.goto(BASE + "#/ambang")
        pg.wait_for_selector("table.data", timeout=20000)
        pg.wait_for_timeout(1500)
        assert_screen(pg, "#/ambang", "Ambang")
        out["ambang"] = pg.evaluate(F7_CARD, "Servis alat menurut jam operasi")
        pg.screenshot(path=f"{OUT}/s34-ambang-jam.png", full_page=False)

        card = out["ambang"] or {"rows": [], "headers": [], "badge": "", "text": ""}
        # Sel pertama memuat KODE lalu nama alatnya, dan innerText sudah
        # dinormalkan menjadi satu baris — jadi kodenya adalah kata pertama.
        subjects = [r["cells"][0].split(" ")[0] for r in card["rows"] if r["cells"]]
        out["subjects"] = subjects
        row_of = {}
        for r in card["rows"]:
            if r["cells"]:
                row_of[r["cells"][0].split(" ")[0]] = r["cells"]
        out["row_of"] = row_of

        # ------------------------------------- kartu alat: kedua pemicu
        pg.goto(BASE + f"#/d/assets/assets/{ids['doosan']}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(2000)
        out["doosan"] = pg.evaluate(F7_DUE)
        pg.screenshot(path=f"{OUT}/s34-kartu-alat-mendekati.png", full_page=False)

        # ------------------- kartu alat: mobilisasi ADA, log belum ada
        pg.goto(BASE + f"#/d/assets/assets/{ids['rak']}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(2000)
        out["rak_tanpa_log"] = pg.evaluate(F7_DUE)
        pg.screenshot(path=f"{OUT}/s34-kartu-alat-tanpa-log.png", full_page=False)

        # ------------- kartu alat: LOGNYA ADA, angka jamnya tidak pernah diisi
        # (log BBM tanpa jam kerja — kejadian biasa di lapangan). Kalimatnya
        # harus BERBEDA dari yang di atas, atau ketiga sebab itu satu "—".
        pg.goto(BASE + f"#/d/assets/assets/{ids['komatsu']}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(2000)
        out["komatsu_log_tanpa_jam"] = pg.evaluate(F7_DUE)
        pg.screenshot(path=f"{OUT}/s34-kartu-alat-log-tanpa-jam.png", full_page=False)

        # ---------------------- kartu alat: belum pernah dimobilisasi
        pg.goto(BASE + f"#/d/assets/assets/{ids['total_station']}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(2000)
        out["total_station"] = pg.evaluate(F7_DUE)
        pg.screenshot(path=f"{OUT}/s34-kartu-alat-belum-dimobilisasi.png", full_page=False)

        # ------------- alat yang BUKAN alat berjam tidak punya kartu itu
        pg.goto(BASE + f"#/d/assets/assets/{ids['scaffolding']}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(1500)
        out["scaffolding"] = pg.evaluate(F7_DUE)

        # --------------------------------------- daftar perawatan
        pg.goto(BASE + "#/r/assets/maintenances")
        pg.wait_for_selector("table.data tbody tr", timeout=20000)
        pg.wait_for_timeout(1200)
        out["daftar"] = pg.evaluate("""() => ({
          headers: [...document.querySelectorAll('table.data thead th')].map(t => t.innerText.trim()),
          rows: [...document.querySelectorAll('table.data tbody tr')].map(
            tr => [...tr.querySelectorAll('td')].map(td => td.innerText.replace(/\\s+/g, ' ').trim())),
        })""")
        pg.screenshot(path=f"{OUT}/s34-daftar-perawatan.png", full_page=False)
        out["console_errors"] = errors

        headers = [h.lower() for h in card["headers"]]
        hour_cells = [c for cells in row_of.values() for c in cells[1:3]]
        # Label stat digambar huruf besar oleh CSS (text-transform), dan
        # innerText memulangkan yang TERGAMBAR: dicocokkan tanpa memedulikan
        # besar-kecil huruf, karena yang diuji adalah adanya stat itu.
        doosan_stats = {(s["label"] or "").upper(): s for s in (out["doosan"] or {}).get("stats", [])}
        ts_stats = {(s["label"] or "").upper(): s for s in (out["total_station"] or {}).get("stats", [])}
        daftar_headers = [h.lower() for h in out["daftar"]["headers"]]
        # BARIS kartu servis yang dijadwalkan dengan JAM SAJA (splicer, target
        # 1.200 jam tanpa tanggal), dicari lewat INDEKS KOLOMNYA sendiri —
        # bukan dengan menggabung seluruh sel satu baris menjadi satu string,
        # yang membuat sel tanggalnya tidak pernah terisolasi dan "bergaris"
        # tidak pernah teruji meski nama syaratnya menjanjikannya.
        kolom_tanggal = daftar_headers.index("jadwal berikut") if "jadwal berikut" in daftar_headers else -1
        kolom_jam = daftar_headers.index("jam berikut") if "jam berikut" in daftar_headers else -1
        baris_jam_saja = [r for r in out["daftar"]["rows"]
                          if kolom_jam >= 0 and kolom_tanggal >= 0 and len(r) > max(kolom_jam, kolom_tanggal)
                          and F7_ASSETS["splicer"] in " ".join(r) and "1.200 jam" in r[kolom_jam]]
        out["baris_jam_saja"] = baris_jam_saja

        out["checks"] = {
            # (1) SATUAN
            "the_hour_entry_reaches_the_screen_at_all": out["ambang"] is not None,
            "its_numbers_are_written_in_hours_never_in_rupiah":
                "Rp" not in card["text"] and any("jam" in c for c in hour_cells),
            "its_warning_is_stated_in_hours_before_the_target":
                "50 jam sebelum batas" in card["badge"] and "%" not in card["badge"],
            "and_its_third_column_is_the_remaining_hours_not_a_percentage":
                "sisa" in headers and "terpakai" not in headers,
            "the_row_still_below_its_target_says_how_many_hours_are_left":
                "24,5 jam lagi" in " ".join(row_of.get(F7_ASSETS["doosan"], [])),
            # …dan baris yang SUDAH lewat mengatakannya dengan kata, bukan
            # dengan tanda minus yang harus dibaca dua kali.
            "and_the_row_past_its_target_says_how_many_hours_it_is_past":
                "40 jam lewat" in " ".join(row_of.get(F7_ASSETS["splicer"], [])),
            # (2) TIDAK TERUKUR — DIGARIS, dan menyebut sebabnya
            "an_unmeasured_asset_is_ruled_never_drawn_as_zero_hours":
                row_of.get(F7_ASSETS["total_station"], [None, None])[1] == "—",
            "and_the_cell_that_would_hold_a_number_prints_the_rule_instead":
                "Belum ada yang diukur" in " ".join(row_of.get(F7_ASSETS["total_station"], [])),
            "the_worst_row_is_first": subjects[:2] == [F7_ASSETS["splicer"], F7_ASSETS["doosan"]],
            "an_asset_that_is_not_hour_metered_is_not_listed_at_all":
                F7_ASSETS["scaffolding"] not in subjects,
            # (3) KARTU ALAT — kedua pemicu, tiga kalimat
            "the_asset_card_shows_the_hour_state": (out["doosan"] or {}).get("badge") == "Mendekati batas",
            "with_the_reading_the_target_and_the_hours_left":
                doosan_stats.get("PEMBACAAN HOUR METER", {}).get("value") == "3.375,5 jam"
                and doosan_stats.get("JATUH TEMPO PADA", {}).get("value") == "3.400 jam"
                and doosan_stats.get("SISA JAM", {}).get("value") == "24,5 jam",
            "and_the_date_trigger_standing_beside_them":
                "Des 2026" in (doosan_stats.get("PEMICU TANGGAL", {}).get("value") or ""),
            "an_asset_with_a_deployment_but_no_log_says_exactly_that":
                "belum ada satu log BBM & jam alat pun"
                in ((out["rak_tanpa_log"] or {}).get("help") or ""),
            "an_asset_whose_logs_never_filled_the_meter_says_something_else":
                "tidak satu pun mengisi hour meter"
                in ((out["komatsu_log_tanpa_jam"] or {}).get("help") or ""),
            "an_asset_never_deployed_says_that_instead":
                "belum pernah dimobilisasi" in ((out["total_station"] or {}).get("help") or "")
                and ts_stats.get("PEMBACAAN HOUR METER", {}).get("value") == "—",
            # LENCANA KARTU INI MENGHAKIMI SATU DARI DUA PEMICU, DAN JUDULNYA
            # MENGATAKANNYA. Komatsu: sisi jamnya belum terukur sama sekali,
            # sisi tanggalnya lewat 86 hari — dan layar Tenggat meneriakkan
            # baris yang sama pada hari yang sama.
            "the_due_card_says_which_trigger_its_badge_judges":
                ((out["total_station"] or {}).get("title") or "").strip()
                == "Servis berikutnya menurut jam",
            # …dan tanggal yang sudah lewat dikatakan LEWAT, bukan disebut
            # "jadwal kalender berikutnya" — kalimat yang untuk tanggal 86
            # hari lalu tidak benar.
            "a_date_trigger_already_past_is_printed_as_past":
                "hari lalu" in (ts_stats.get("PEMICU TANGGAL", {}).get("delta") or "")
                and "jadwal kalender" not in ((out["total_station"] or {}).get("text") or ""),
            "a_non_hour_metered_asset_has_no_due_card_at_all": out["scaffolding"] is None,
            # daftar perawatan
            "the_maintenance_list_carries_both_triggers":
                "jadwal berikut" in daftar_headers and "jam berikut" in daftar_headers,
            # PASANGAN SELNYA. Literal "8.000 jam" yang dulu digantung di sini
            # tidak pernah ditanam fixture mana pun (yang ditanam 3.400 /
            # 1.200 / 8.760 / 5.000 / 500), jadi syarat ini bergantung pada
            # satu literal saja — dan literal itu pun hanya membuktikan angka
            # jamnya tercetak, hal yang sudah dibuktikan syarat lain.
            "and_an_hour_only_service_prints_its_hours_beside_a_ruled_date":
                len(baris_jam_saja) == 1 and baris_jam_saja[0][kolom_tanggal] == "—"
                and baris_jam_saja[0][kolom_jam] == "1.200 jam",
            "the_screens_raise_no_console_error": out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        for mid in planted:
            if mid:
                api(f"assets/maintenances/{mid}", tok, "DELETE")


@scenario("S34_servis_alat_per_jam_mobile")
def s34m(browser):
    """390 px. Tabel ambang punya enam kolom dan kartu "Servis berikutnya"
    punya empat stat; keduanya lahir di paket ini, jadi keduanya adalah
    kandidat pertama yang mendorong halaman melebar di ponsel — dan sebuah
    kalimat "belum terukur" yang terpotong adalah kalimat yang tidak ada."""
    tok = token_for("admin@nusantara.test")
    by_code, dep_of = _f7_lookup(tok)
    if F7_ASSETS["doosan"] not in by_code:
        return {"SKIPPED": f"Aset {F7_ASSETS['doosan']} tidak ada di salinan DB ini."}

    doosan = by_code[F7_ASSETS["doosan"]]
    planted = [_f7_plant_maintenance(tok, doosan, 3400, "2026-12-01")]
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    errors = []
    try:
        login(pg, "admin@nusantara.test")
        # Sesudah login, dan untuk alasan yang sama seperti S34 desktop: 429
        # dari gerbang masuk bukan galat konsol layar yang diuji.
        pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
        pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))

        pg.goto(BASE + "#/ambang")
        pg.wait_for_selector("table.data", timeout=20000)
        pg.wait_for_timeout(1500)
        out = pg.evaluate("""() => {
          const card = [...document.querySelectorAll('.card')].find(c => {
            const h = c.querySelector('.card-head h2');
            return h && h.innerText.trim() === 'Servis alat menurut jam operasi';
          });
          const wrap = card ? card.querySelector('.table-wrap') : null;
          return {
            card_found: !!card,
            wrap_scrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
            badge: card ? (card.querySelector('.card-head .badge') || {}).innerText : null,
            rows: card ? card.querySelectorAll('table.data tbody tr').length : 0,
          };
        }""")
        pg.screenshot(path=f"{OUT}/s34-ambang-jam-ponsel.png", full_page=False)

        pg.goto(BASE + f"#/d/assets/assets/{doosan}")
        pg.wait_for_selector(".page-head", timeout=20000)
        pg.wait_for_timeout(2000)
        out["kartu"] = pg.evaluate(F7_DUE)
        out["kartu_visible"] = pg.evaluate("""() => {
          const card = [...document.querySelectorAll('.card')].find(c => {
            const h = c.querySelector('.card-head h2');
            return h && h.innerText.trim().startsWith('Servis berikutnya');
          });
          if (!card) return null;
          const help = card.querySelector('p.help');
          return {
            help_visible: !!(help && help.checkVisibility()),
            help_clipped: help ? help.scrollHeight > help.clientHeight + 1 : null,
            stats: [...card.querySelectorAll('.stat')].map(s => Math.round(s.getBoundingClientRect().width)),
          };
        }""")
        pg.screenshot(path=f"{OUT}/s34-kartu-alat-ponsel.png", full_page=False)
        out["console_errors"] = errors

        stats = {(s["label"] or "").upper(): s for s in (out["kartu"] or {}).get("stats", [])}
        out["checks"] = {
            "the_hour_entry_renders_on_a_phone": out["card_found"] is True and out["rows"] > 0,
            "its_wide_table_scrolls_inside_its_own_box": out["wrap_scrolls"] is True,
            "the_threshold_page_never_scrolls_sideways": out["page_scrolls_sideways"] is False,
            "the_warning_badge_still_says_hours": "50 jam sebelum batas" in (out["badge"] or ""),
            "the_due_card_renders_on_a_phone": out["kartu"] is not None,
            "the_asset_page_never_scrolls_sideways": (out["kartu"] or {}).get("page_scrolls_sideways") is False,
            "both_triggers_are_still_side_by_side_on_a_phone":
                stats.get("SISA JAM", {}).get("value") == "24,5 jam"
                and "Des 2026" in (stats.get("PEMICU TANGGAL", {}).get("value") or ""),
            "and_the_sentence_under_them_is_not_clipped":
                (out["kartu_visible"] or {}).get("help_visible") is True
                and (out["kartu_visible"] or {}).get("help_clipped") is False,
            "the_screens_raise_no_console_error": out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()
        for mid in planted:
            if mid:
                api(f"assets/maintenances/{mid}", tok, "DELETE")


# ------------------------------------------------------- S35 (Fase 2 / F-8)
#
# Masa berlaku lampiran. Yang tidak bisa dibuktikan suite PHP, dan justru itu
# yang membuat atau menggagalkan paket ini:
#
#  1. KEADAAN NORMAL TIDAK BOLEH TERBACA SEBAGAI PERINGATAN. "Tanpa masa
#     berlaku" harus digambar sebagai keterangan biasa — bukan .badge, bukan
#     warna. Suite PHP hanya bisa memeriksa STRING keadaannya; apakah ia
#     berakhir sebagai lencana kuning di layar hanya bisa dilihat di peramban.
#  2. Kartu dan layar Tenggat harus menyebut berkas yang SAMA dengan status
#     yang SAMA pada hari yang sama.
#  3. Dialog "Masa berlaku" benar-benar menulis, dan tombol Kosongkan benar-
#     benar mengembalikan barisnya ke keadaan normal.
F8_DOC = ("finance/ap-bills", "Tagihan vendor")

# PDF sungguhan sekecil mungkin: AttachmentService MENGENDUS isinya, jadi
# byte-nya harus benar-benar PDF atau unggahannya ditolak 422.
F8_PDF = base64.b64encode(b"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n").decode()

F8_ROWS = """() => {
  const card = [...document.querySelectorAll('.card')].find(c => {
    const h = c.querySelector('.card-head h2');
    return h && h.innerText.trim() === 'Lampiran';
  });
  if (!card) return null;
  return [...card.querySelectorAll('.attachment')].map((row) => {
    const v = row.querySelector('.attachment-validity');
    const badge = v ? v.querySelector('.badge') : null;
    return {
      name: (row.querySelector('.attachment-name') || {}).innerText || null,
      validity_text: v ? v.innerText.trim() : null,
      // KELAS lencananya, bukan hanya teksnya: 'tanpa masa berlaku' yang
      // digambar kuning tetap mencetak kalimat yang benar.
      badge_class: badge ? badge.className : null,
      badge_color: badge ? getComputedStyle(badge).color : null,
      buttons: [...row.querySelectorAll('.row-actions .btn')].map(b => b.innerText.trim()),
    };
  });
}"""


def _f8_bill(tok):
    """Tagihan vendor pertama di salinan DB ini, atau None."""
    s, d = api("finance/ap-bills?per_page=1", tok)
    rows = (d.get("data") or []) if s == 200 else []
    return rows[0] if rows else None


def _f8_today(tok):
    """HARI INI MENURUT SERVER, bukan menurut proses harness.

    Aplikasinya berjalan di Asia/Jakarta dan mesin ini di UTC, jadi selama tujuh
    jam setiap hari `date.today()` di sini adalah SEHARI LEBIH AWAL daripada
    tanggal yang dipakai pengawas tenggat dan kartu lampiran. Diukur 9 Sep 2026
    pukul 17.4x UTC: fixture "berlaku s/d hari ini" ditanam 2026-09-09 dan
    dibaca server sebagai KEDALUWARSA 1 hari, karena bagi server hari itu sudah
    2026-09-10. Anchor tanggalnya karena itu diambil dari server sendiri —
    meta.today milik GET core/deadlines, jam yang sama dengan scan()."""
    s, d = api("core/deadlines", tok)
    if s != 200 or not (d.get("meta") or {}).get("today"):
        raise AssertionError(f"tidak bisa membaca meta.today dari core/deadlines: {s}")
    return date.fromisoformat(d["meta"]["today"])


def _f8_attach(tok, doc_id, filename, valid_until):
    body = {"document_type": F8_DOC[0], "document_id": doc_id,
            "filename": filename, "content": F8_PDF}
    if valid_until is not None:
        body["valid_until"] = valid_until
    s, d = api("core/attachments", tok, "POST", body)
    if s != 201:
        raise AssertionError(f"unggah {filename} gagal: {s} {json.dumps(d)[:200]}")
    return d["data"]["id"]


def _f8_plant(tok, doc_id):
    """Empat keadaan yang bisa dilihat sekaligus dalam satu kartu, dihitung dari
    hari SERVER supaya skenarionya tidak basi pada tanggal berapa pun ia
    dijalankan — dan tidak meleset satu hari karena zona waktunya."""
    today = _f8_today(tok)
    return [
        _f8_attach(tok, doc_id, "foto-lapangan.pdf", None),
        _f8_attach(tok, doc_id, "polis-car.pdf", str(today + timedelta(days=12))),
        _f8_attach(tok, doc_id, "sertifikat-kalibrasi.pdf", str(today - timedelta(days=3))),
        # Hari terakhirnya: MASIH berlaku (kuning "hari ini"), bukan merah.
        _f8_attach(tok, doc_id, "izin-kerja.pdf", str(today)),
    ]


@scenario("S35_kedaluwarsa_lampiran")
def s35(pg):
    """Empat keadaan dalam satu kartu, dua pintu tulis, dan satu layar Tenggat
    yang harus setuju dengan kartunya."""
    tok = token_for("admin@nusantara.test")
    bill = _f8_bill(tok)
    if bill is None:
        return {"SKIPPED": "Tidak ada tagihan vendor di salinan DB ini."}

    today = _f8_today(tok)
    planted = _f8_plant(tok, bill["id"])
    out = {"document": bill["code"], "planted": len(planted), "server_today": str(today)}
    errors = []
    pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
    pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))

    try:
        login(pg, "admin@nusantara.test")

        # ------------------------------------------------- kartu lampiran
        pg.goto(BASE + f"#/d/{F8_DOC[0]}/{bill['id']}")
        pg.wait_for_selector(".attachment", timeout=20000)
        pg.wait_for_timeout(1200)
        assert_screen(pg, f"#/d/{F8_DOC[0]}/{bill['id']}")
        rows = pg.evaluate(F8_ROWS)
        out["rows"] = rows
        pg.screenshot(path=f"{OUT}/s35-kartu-lampiran.png", full_page=False)

        by_name = {r["name"]: r for r in (rows or [])}
        normal = by_name.get("foto-lapangan.pdf") or {}
        menipis = by_name.get("polis-car.pdf") or {}
        lewat = by_name.get("sertifikat-kalibrasi.pdf") or {}
        hari_ini = by_name.get("izin-kerja.pdf") or {}

        # ------------------------------------------------- dialog masa berlaku
        # Baris "tanpa masa berlaku" diberi tanggal lewat dialognya, lalu
        # dikosongkan lagi — dua arah, karena tanggal salah ketik harus bisa
        # dicabut dan bukan hanya diganti tanggal salah yang lain.
        target = str(today + timedelta(days=5))
        row_sel = ".attachment:has(.attachment-name:text-is('foto-lapangan.pdf'))"
        click(pg, f"{row_sel} .btn:has-text('Masa berlaku')")
        pg.wait_for_selector("#overlay .modal input[type=date]", timeout=10000)
        pg.fill("#overlay .modal input[type=date]", target)
        out["dialog_title"] = pg.evaluate(
            "() => { const h = document.querySelector('#overlay .modal h2, #overlay .modal .modal-title');"
            " return h ? h.innerText.trim() : null; }")
        click(pg, "#overlay .modal .btn:has-text('Simpan')")
        pg.wait_for_timeout(1500)
        out["toast_simpan"] = toasts(pg)
        out["after_set"] = pg.evaluate(F8_ROWS)

        click(pg, f"{row_sel} .btn:has-text('Masa berlaku')")
        pg.wait_for_selector("#overlay .modal input[type=date]", timeout=10000)
        click(pg, "#overlay .modal .btn:has-text('Kosongkan')")
        pg.wait_for_timeout(1500)
        out["toast_kosongkan"] = toasts(pg)
        out["after_clear"] = pg.evaluate(F8_ROWS)
        pg.screenshot(path=f"{OUT}/s35-dialog-sesudah.png", full_page=False)

        # ------------------------------------------------------- layar Tenggat
        pg.goto(BASE + "#/tenggat")
        pg.wait_for_selector(".card", timeout=20000)
        pg.wait_for_timeout(1500)
        assert_screen(pg, "#/tenggat", "Tenggat")
        out["tenggat"] = pg.evaluate("""() => {
          const cards = [...document.querySelectorAll('.card')].filter(c => {
            const h = c.querySelector('.card-head h2');
            return h && /^Lampiran /.test(h.innerText.trim());
          });
          return cards.map((c) => ({
            title: c.querySelector('.card-head h2').innerText.trim(),
            badge: (c.querySelector('.card-head .badge') || {}).innerText || null,
            rows: [...c.querySelectorAll('table.data tbody tr')].map(
              (tr) => [...tr.querySelectorAll('td')].map(td => td.innerText.trim())),
          }));
        }""")
        pg.screenshot(path=f"{OUT}/s35-tenggat-lampiran.png", full_page=False)

        tenggat_names = [cell for card in (out["tenggat"] or []) for row in card["rows"] for cell in row]
        out["console_errors"] = errors

        out["checks"] = {
            # 1. Keadaan normal BUKAN peringatan.
            "the_no_expiry_state_is_written_as_plain_text":
                normal.get("validity_text") == "Tanpa masa berlaku" and normal.get("badge_class") is None,
            # 2. Menipis kuning, kedaluwarsa merah — kelasnya, bukan hanya kalimatnya.
            "an_expiring_file_gets_the_amber_badge":
                (menipis.get("badge_class") or "").find("amber") != -1
                and "12 hari lagi" in (menipis.get("validity_text") or ""),
            "an_expired_file_gets_the_red_badge":
                (lewat.get("badge_class") or "").find("red") != -1
                and "3 hari lalu" in (lewat.get("validity_text") or "")
                and (lewat.get("validity_text") or "").startswith("Kedaluwarsa"),
            # 3. Hari terakhirnya MASIH berlaku: kuning "hari ini", bukan merah.
            "the_last_valid_day_reads_hari_ini_and_stays_amber":
                (hari_ini.get("badge_class") or "").find("amber") != -1
                and "hari ini" in (hari_ini.get("validity_text") or "")
                and "Kedaluwarsa" not in (hari_ini.get("validity_text") or ""),
            # 4. Dialognya menulis, dan Kosongkan mengembalikan keadaan normal.
            "the_dialog_writes_the_date":
                "5 hari lagi" in (([r for r in (out["after_set"] or [])
                                    if r["name"] == "foto-lapangan.pdf"] or [{}])[0].get("validity_text") or ""),
            "clearing_returns_it_to_the_normal_state":
                (([r for r in (out["after_clear"] or [])
                   if r["name"] == "foto-lapangan.pdf"] or [{}])[0].get("validity_text")) == "Tanpa masa berlaku",
            # 5. Kartu dan Tenggat menyebut berkas yang SAMA.
            "tenggat_names_the_same_two_files_the_card_flagged":
                any("polis-car.pdf" in n for n in tenggat_names)
                and any("sertifikat-kalibrasi.pdf" in n for n in tenggat_names),
            # …dan TIDAK menyebut yang tanpa masa berlaku.
            "tenggat_never_names_the_file_without_an_expiry":
                not any("foto-lapangan.pdf" in n for n in tenggat_names),
            "the_screens_raise_no_console_error": out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        for aid in planted:
            api(f"core/attachments/{aid}", tok, "DELETE")


@scenario("S35_kedaluwarsa_lampiran_ponsel")
def s35m(browser):
    """390 px. Kartu lampiran tumbuh satu baris keterangan DAN satu tombol per
    lampiran di paket ini; keduanya adalah kandidat pertama yang mendorong
    layar detail melebar di ponsel. Dan dialog tanggalnya harus muat: sebuah
    tombol Simpan yang berada di luar layar adalah dialog yang tidak bisa
    dipakai."""
    tok = token_for("admin@nusantara.test")
    bill = _f8_bill(tok)
    if bill is None:
        return {"SKIPPED": "Tidak ada tagihan vendor di salinan DB ini."}

    planted = _f8_plant(tok, bill["id"])
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    pg = ctx.new_page()
    errors = []
    try:
        login(pg, "admin@nusantara.test")
        pg.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type == "error" else None)
        pg.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))

        pg.goto(BASE + f"#/d/{F8_DOC[0]}/{bill['id']}")
        pg.wait_for_selector(".attachment", timeout=20000)
        pg.wait_for_timeout(1500)
        out = {"document": bill["code"], "rows": pg.evaluate(F8_ROWS)}
        out["page"] = pg.evaluate("""() => {
          const card = [...document.querySelectorAll('.card')].find(c => {
            const h = c.querySelector('.card-head h2');
            return h && h.innerText.trim() === 'Lampiran';
          });
          const v = card ? card.querySelector('.attachment-validity') : null;
          return {
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
            card_found: !!card,
            // Baris keterangannya tidak boleh terpotong: keterangan yang
            // terpotong adalah keterangan yang tidak ada.
            validity_clipped: v ? v.scrollWidth > v.clientWidth + 1 : null,
            add_row_wraps: (() => {
              const r = card ? card.querySelector('.attachment-add-row') : null;
              if (!r) return null;
              const kids = [...r.children].map(k => Math.round(k.getBoundingClientRect().top));
              return new Set(kids).size > 1;
            })(),
          };
        }""")
        pg.screenshot(path=f"{OUT}/s35-kartu-lampiran-ponsel.png", full_page=False)

        row_sel = ".attachment:has(.attachment-name:text-is('polis-car.pdf'))"
        click(pg, f"{row_sel} .btn:has-text('Masa berlaku')")
        pg.wait_for_selector("#overlay .modal input[type=date]", timeout=10000)
        pg.wait_for_timeout(600)
        out["dialog"] = pg.evaluate("""() => {
          const m = document.querySelector('#overlay .modal');
          if (!m) return null;
          const box = m.getBoundingClientRect();
          const save = [...m.querySelectorAll('.btn')].find(b => b.innerText.trim() === 'Simpan');
          const sb = save ? save.getBoundingClientRect() : null;
          return {
            width: Math.round(box.width),
            fits_viewport: box.left >= -1 && box.right <= window.innerWidth + 1,
            save_visible: !!(save && save.checkVisibility()),
            save_inside: sb ? (sb.right <= window.innerWidth + 1 && sb.bottom <= window.innerHeight + 1) : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
          };
        }""")
        pg.screenshot(path=f"{OUT}/s35-dialog-ponsel.png", full_page=False)
        click(pg, "#overlay .modal .btn:has-text('Batal')")
        pg.wait_for_timeout(500)

        pg.goto(BASE + "#/tenggat")
        pg.wait_for_selector(".card", timeout=20000)
        pg.wait_for_timeout(1500)
        out["tenggat"] = pg.evaluate("""() => {
          const card = [...document.querySelectorAll('.card')].find(c => {
            const h = c.querySelector('.card-head h2');
            return h && /^Lampiran /.test(h.innerText.trim());
          });
          const wrap = card ? card.querySelector('.table-wrap') : null;
          return {
            card_found: !!card,
            title: card ? card.querySelector('.card-head h2').innerText.trim() : null,
            wrap_scrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : null,
            page_scrolls_sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
          };
        }""")
        pg.screenshot(path=f"{OUT}/s35-tenggat-lampiran-ponsel.png", full_page=False)
        out["console_errors"] = errors

        by_name = {r["name"]: r for r in (out["rows"] or [])}
        out["checks"] = {
            "the_card_renders_on_a_phone": out["page"].get("card_found") is True and len(out["rows"] or []) == 4,
            "the_detail_page_never_scrolls_sideways": out["page"].get("page_scrolls_sideways") is False,
            "the_validity_line_is_not_clipped": out["page"].get("validity_clipped") is False,
            "the_no_expiry_state_is_still_plain_text_on_a_phone":
                (by_name.get("foto-lapangan.pdf") or {}).get("badge_class") is None,
            "the_expiry_dialog_fits_the_phone":
                (out["dialog"] or {}).get("fits_viewport") is True
                and (out["dialog"] or {}).get("save_visible") is True
                and (out["dialog"] or {}).get("save_inside") is True,
            "the_dialog_never_widens_the_page": (out["dialog"] or {}).get("page_scrolls_sideways") is False,
            "the_tenggat_group_renders_on_a_phone": out["tenggat"].get("card_found") is True,
            "the_tenggat_page_never_scrolls_sideways": out["tenggat"].get("page_scrolls_sideways") is False,
            "the_screens_raise_no_console_error": out["console_errors"] == [],
        }
        out["failed_checks"] = [k for k, v in out["checks"].items() if not v]
        out["ok"] = not out["failed_checks"]
        return out
    finally:
        ctx.close()
        for aid in planted:
            api(f"core/attachments/{aid}", tok, "DELETE")


with sync_playwright() as p:
    b = p.chromium.launch(headless=True)
    def fresh():
        ctx = b.new_context(viewport={"width": 1440, "height": 900}); return ctx.new_page()
    import sys
    want = set(sys.argv[1:])
    prev = {}
    try: prev = json.load(open(f"{OUT}/results.json"))
    except Exception: pass
    R.update(prev)
    RUNS = [("S10",s10,None),("S1",s1,None),("S2",s2,None),("S3",s3,None),("S4",s4,None),("S5",s5,None),("S6",s6,"b"),("S7",s7,None),("S8",s8,None),("S9",s9,None),("S11",s11,None),("S12",s12,None),("S13",s13,None),("S14",s14,None),("S15",s15,"b"),("S16",s16,None),("S17",s17,None),("S18",s18,None),("S19",s19,"b"),("S20",s20,None),("S20m",s20m,"b"),("S21",s21,None),("S21m",s21m,"b"),("S22",s22,None),("S22m",s22m,"b"),("S22r",s22r,None),("S23",s23,None),("S23s",s23s,None),("S23f",s23f,None),("S23m",s23m,"b"),("S20e",s20e,None),("S20em",s20em,"b"),("S24",s24,None),("S25",s25,None),("S26",s26,None),("S26m",s26m,"b"),("S26f",s26f,None),("S26t",s26t,"b"),("S26d",s26d,None),("S26p",s26p,None),("S27",s27,None),("S27m",s27m,"b"),("S27u",s27u,None),("S27k",s27k,"b"),("S27p",s27p,None),("S28",s28,None),("S28m",s28m,"b"),("S29",s29,None),("S29m",s29m,"b"),("S30",s30,None),("S30m",s30m,"b"),("S30r",s30r,None),("S31",s31,"b"),("S31s",s31s,None),("S32",s32,None),("S32m",s32m,"b"),("S33",s33,None),("S33k",s33k,"b"),("S33m",s33m,"b"),("S34",s34,None),("S34m",s34m,"b"),("S35",s35,None),("S35m",s35m,"b")]

    # NAMA YANG TIDAK DIKENAL MENJATUHKAN RUN, dan nama PANJANG diterima.
    #
    # Sampai 9 Sep 2026 barisnya hanya `if want and name not in want: continue`,
    # dan daftar ini memakai nama PENDEK ("S34") sementara laporan dan
    # results-*.json memakai nama PANJANG ("S34_servis_alat_per_jam"). Memanggil
    # harness dengan nama yang tertulis di buktinya sendiri mencocokkan NOL
    # entri: seluruh loop dilewati, "saved results.json" tercetak, status keluar
    # 0, dan pembacanya menyimpulkan skenarionya hijau. Itu terjadi empat kali
    # di dalam putaran verifikasi F-7 — termasuk sekali yang menyimpulkan sebuah
    # mutasi "lolos hijau" padahal tidak satu pun skenario dijalankan.
    alias = {}
    for short, fn, _arg in RUNS:
        alias[short] = short
        long_name = getattr(fn, "scenario_name", None)
        if long_name:
            alias[long_name] = short

    unknown = sorted(n for n in want if n not in alias)
    if unknown:
        print("NAMA SKENARIO TIDAK DIKENAL: " + ", ".join(unknown))
        print("Yang dikenal: " + ", ".join(sorted(alias)))
        b.close()
        sys.exit(2)

    selected = {alias[n] for n in want}
    ran = 0
    for name, fn, arg in RUNS:
        if want and name not in selected: continue
        fn(b if arg == "b" else fresh())
        ran += 1
    b.close()

    # Diminta sesuatu dan tidak satu pun jalan: itu bukan kesuksesan.
    if want and ran == 0:
        print("TIDAK ADA SKENARIO YANG DIJALANKAN untuk: " + ", ".join(sorted(want)))
        sys.exit(2)

import os; os.makedirs(OUT, exist_ok=True)  # verifikasi B4 fase 3: OUT yang belum ada menjatuhkan run di akhir, hasil hilang
json.dump(R, open(f"{OUT}/results.json", "w"), ensure_ascii=False, indent=1)
print("saved results.json")

# Status keluar yang bisa dipercaya: yang membaca hanya baris terakhir tetap tahu.
if FAILED:
    print("GAGAL: " + ", ".join(FAILED))
    sys.exit(1)
