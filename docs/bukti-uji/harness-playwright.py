import json, os, re, time, sqlite3, struct, base64, traceback, urllib.request
from datetime import date
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
            state = R[name].get("ERROR") or (f"SKIPPED: {R[name]['SKIPPED']}" if "SKIPPED" in R[name] else "ok")
            print(f"[{name}] {state} {R[name]['_ms']}ms clicks={CLICKS[0]}")
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

    PIL opsional: harness ini harus tetap jalan di mesin yang tidak memasangnya,
    dan sebuah angka yang TIDAK bisa diukur dilaporkan sebagai null bersebab —
    bukan dihilangkan diam-diam.
    """
    try:
        from PIL import Image, ImageChops
    except ImportError:
        return {"available": False, "reason": "Pillow tidak terpasang"}

    if not (os.path.exists(before) and os.path.exists(after)):
        return {"available": False, "reason": "tangkapan layar pembanding tidak ada"}

    a = Image.open(before).convert("RGB")
    b = Image.open(after).convert("RGB")
    w, h = min(a.width, b.width), min(a.height, b.height)
    diff = ImageChops.difference(a.crop((0, 0, w, h)), b.crop((0, 0, w, h)))
    # Toleransi 16/255 per kanal: anti-alias sub-piksel bukan perubahan.
    changed = sum(1 for r, g, bl in diff.getdata() if r > 16 or g > 16 or bl > 16)
    frame = max(a.width, b.width) * max(a.height, b.height)
    outside = frame - (w * h)
    return {
        "available": True,
        "before": [a.width, a.height], "after": [b.width, b.height],
        "changed_pct": round(changed / (w * h) * 100, 2),
        "outside_intersection_pct": round(outside / frame * 100, 2),
    }


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
        "evm_title_names_three_numbers": all(
            ("rencana" in t and "fisik" in t and "biaya" in t) for t in evm["titles"]) and bool(evm["titles"]),
        # Sumbu EVM boleh melewati 100 % — di sini datanya berhenti di 100, jadi
        # yang dibuktikan adalah bahwa ia TIDAK jatuh di bawahnya.
        "evm_axis_reaches_100": "100%" in evm["ticks"],
        # Tren harga: sumbu TIDAK mulai dari nol.
        "trend_axis_not_zero_based": trend["ticks"] and not trend["ticks"][0].strip().endswith(" 0"),
        "trend_five_gridlines": len([t for t in trend["ticks"] if t.startswith("Rp")]) == 5,
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
        pg.wait_for_timeout(1800)
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

DASH_CARDS = """() => [...document.querySelectorAll('.dash-grid .card.widget')].map((c) => ({
    id: c.dataset.widget,
    size: c.dataset.size,
    span: getComputedStyle(c).gridColumnEnd,
    title: (c.querySelector('.card-head h2') || {}).innerText || null,
    body: ((c.querySelector('.widget-body') || {}).innerText || '').trim(),
}))"""


# Permintaan yang dibayar SETIAP layar, bukan oleh susunan dasbor: sesi, izin,
# lencana lonceng, status penjadwal, dan pemuatan preferensi di boot.
SHELL_REQUESTS = ("iam/auth", "core/notifications/unread-count", "core/health", "core/me/preferences")


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
            }
            # Batch 4 membatasi WIDGET, bukan permintaan: satu widget boleh
            # mengirim lebih dari satu (hanya `ncr`, yang menjumlah dua status
            # karena endpoint daftar menerima satu status per permintaan dan
            # angkanya harus sama dengan ubin launcher). Batas atas yang benar
            # karena itu 4 + jumlah permintaan EKSTRA milik widget semacam itu
            # yang ada di susunan ini — bukan 4 mentah, dan bukan "berapa pun".
            extra = sum(1 for c in cards if c["id"] in MULTI_REQUEST_WIDGETS)
            out["roles"][email.split("@")[0]]["concurrent_budget"] = 4 + extra
            out["cards_total"] += len(cards)
            if not cards:
                out["roles_without_cards"].append(email)
        finally:
            ctx.close()

    out["max_concurrent_any_role"] = max(r["max_concurrent"] for r in out["roles"].values())
    out["over_budget"] = [
        name for name, r in out["roles"].items() if r["max_concurrent"] > r["concurrent_budget"]
    ]
    out["ok"] = (
        not out["roles_without_cards"]
        and not out["over_budget"]
        and not any(r["empty_bodies"] for r in out["roles"].values())
    )
    return out


@scenario("S23_setup_drawer")
def s23s(pg):
    """Laci: tambah, hapus, ubah ukuran, urutkan — lalu MUAT ULANG dan baca lagi."""
    login(pg, "direktur@nusantara.test")
    pg.wait_for_timeout(2500)
    before = [c["id"] for c in pg.evaluate(DASH_CARDS)]

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
    order_in_drawer = pg.evaluate(
        "() => [...document.querySelectorAll('.dash-setup-list .dash-setup-row')].map((r) => r.dataset.id)")

    click(pg, ".modal-foot button:has-text('Simpan')")
    pg.wait_for_timeout(1200)
    after_save = [c["id"] for c in pg.evaluate(DASH_CARDS)]

    # Muat ulang penuh: yang diuji adalah baris preferensi di SERVER, bukan
    # keadaan yang kebetulan masih ada di memori halaman ini.
    pg.reload()
    pg.wait_for_selector("nav.nav", timeout=15000)
    pg.wait_for_timeout(3000)
    reloaded = pg.evaluate(DASH_CARDS)
    reloaded_ids = [c["id"] for c in reloaded]

    stored = pg.evaluate("""async () => {
        const r = await fetch('/api/core/me/preferences', { headers: {
            'X-Api-Token': localStorage.getItem('nusantara_erp_token'), Accept: 'application/json' } });
        const j = await r.json();
        return (j.data || []).filter((x) => x.key === 'dashboard.layout').map((x) => x.value)[0] || null;
    }""")

    click(pg, ".page-head .actions button:has-text('Atur dasbor')")
    pg.wait_for_selector(".modal .dash-setup-list", timeout=10000)
    click(pg, ".modal-foot button:has-text('Kembalikan ke bawaan')")
    pg.wait_for_timeout(200)
    restored = pg.evaluate(
        "() => [...document.querySelectorAll('.dash-setup-list .dash-setup-row')].map((r) => r.dataset.id)")
    click(pg, ".modal-foot button:has-text('Batal')")

    return {
        "before": before,
        "added_label": added,
        "removed": removed,
        "resized": resized, "resized_from": now, "resized_to": target,
        "moved_up": second,
        "order_in_drawer": order_in_drawer,
        "after_save": after_save,
        "after_reload": reloaded_ids,
        "stored_preference": stored,
        "restore_default_matches_before": restored == before,
        "sizes_after_reload": {c["id"]: c["size"] for c in reloaded},
        "ok": (
            removed not in reloaded_ids
            and reloaded_ids == order_in_drawer
            and reloaded_ids == after_save
            and isinstance(stored, list) and len(stored) == len(reloaded_ids)
            and restored == before
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

    return {
        "healthy_money_card": healthy.get("ringkasan-uang", "")[:120],
        "broken_money_card": money[:160],
        "card_still_drawn": "ringkasan-uang" in broken,
        "says_failed": "Gagal dimuat" in money,
        "writes_em_dash": money.count("—") >= 3,
        # Yang paling penting: TIDAK ada angka rupiah yang dikarang.
        "no_zero_rupiah": "Rp 0" not in money,
        "other_cards_still_loaded": [i for i in others if i in broken and len(broken[i]) > 3],
        "other_cards_lost": [i for i in others if i not in broken],
        "ok": (
            "ringkasan-uang" in broken
            and "Gagal dimuat" in money
            and "Rp 0" not in money
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
        return {
            "cards": [c["id"] for c in cards],
            "grid": geom,
            # Satu kolom: setiap kartu mulai di tepi kiri yang sama, dan halaman
            # tidak menggulung mendatar.
            "ok": geom["cols"] == 1 and len(geom["lefts"]) == 1 and not geom["page_scroll_x"] and bool(cards),
        }
    finally:
        ctx.close()


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
    for name, fn, arg in [("S10",s10,None),("S1",s1,None),("S2",s2,None),("S3",s3,None),("S4",s4,None),("S5",s5,None),("S6",s6,"b"),("S7",s7,None),("S8",s8,None),("S9",s9,None),("S11",s11,None),("S12",s12,None),("S13",s13,None),("S14",s14,None),("S15",s15,"b"),("S16",s16,None),("S17",s17,None),("S18",s18,None),("S19",s19,"b"),("S20",s20,None),("S20m",s20m,"b"),("S21",s21,None),("S21m",s21m,"b"),("S22",s22,None),("S22m",s22m,"b"),("S22r",s22r,None),("S23",s23,None),("S23s",s23s,None),("S23f",s23f,None),("S23m",s23m,"b"),("S20e",s20e,None),("S24",s24,None),("S25",s25,None)]:
        if want and name not in want: continue
        fn(b if arg == "b" else fresh())
    b.close()

import os; os.makedirs(OUT, exist_ok=True)  # verifikasi B4 fase 3: OUT yang belum ada menjatuhkan run di akhir, hasil hilang
json.dump(R, open(f"{OUT}/results.json", "w"), ensure_ascii=False, indent=1)
print("saved results.json")
