import json, os, re, time, sqlite3, struct, base64, traceback, urllib.request
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

def login(page, email):
    page.goto(BASE)
    page.wait_for_selector("input[type=email]", timeout=15000)
    page.fill("input[type=email]", email)
    page.fill("input[type=password]", "password")
    # iam/auth/login dibatasi throttle per IP; S5 memasukkan 11 peran dalam ~30 s dan satu di antaranya
    # kena 429 (diamati 4 Sep 2026: teknisi/sales/finance bergantian, POST-nya tidak pernah sampai ke
    # log php -S, toast-nya sudah lenyap saat 15 s habis). Ditunggu sesuai Retry-After lalu diklik
    # lagi — yang dilakukan orang juga; tidak masuk hitungan klik skenario.
    for _ in range(3):
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
            print(f"[{name}] {R[name].get('ERROR', 'ok')} {R[name]['_ms']}ms clicks={CLICKS[0]}")
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
    vendor = next(v for v in d["data"] if v.get("vendor_type") in (None, "supplier"))
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
    ctx.close()
    return {"taps_to_lapangan": CLICKS[0], "ms": int((time.time()-t0)*1000), **drawer, "lapangan": lap}

@scenario("S7_status_colors")
def s7(pg):
    login(pg, "admin@nusantara.test")
    out = {}
    for key, route in [("ncr","#/r/quality/ncr"), ("k3","#/r/projects/safety-incidents"), ("defects","#/defects"), ("tickets","#/r/servicedesk/tickets")]:
        pg.goto(BASE + route); pg.wait_for_timeout(1800)
        out[key] = pg.evaluate("() => [...new Set([...document.querySelectorAll('table.data .badge')].map(b => b.innerText.trim()+' → '+[...b.classList].filter(c=>['green','red','amber','blue','primary'].includes(c)).join('/')))]")
        # T2.8 — lencana di kepala halaman detail juga diukur: di sanalah statusTone
        # melukis 'open' (detail.js), sedangkan daftar NCR/K3/defect semula menulis
        # statusnya sebagai teks polos tanpa lencana (diukur 4 Sep 2026: ncr → []).
        if pg.locator("tr.clickable").count():
            click(pg, "tr.clickable >> nth=0"); pg.wait_for_selector(".page-head h1", timeout=15000); pg.wait_for_timeout(1200)
            out[key + "_detail"] = pg.evaluate("() => { const b=document.querySelector('.page-head .badge'); return { h1: (document.querySelector('.page-head h1')||{}).innerText, badge: b ? b.innerText.trim()+' → '+[...b.classList].filter(c=>['green','red','amber','blue','primary'].includes(c)).join('/') : null } }")
    return out

@scenario("S8_styles")
def s8(pg):
    login(pg, "admin@nusantara.test")
    pg.goto(BASE + "#/r/procurement/purchase-orders"); pg.wait_for_timeout(1800)
    return pg.evaluate("""() => { const cs=(s)=>getComputedStyle(document.querySelector(s)); const th=cs('table.data th'); const sm=document.querySelector('.btn.sm');
        const root=getComputedStyle(document.documentElement);
        const lum=(hex)=>{const c=hex.match(/\\w\\w/g).map(x=>parseInt(x,16)/255).map(v=>v<=.03928?v/12.92:((v+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2]};
        const cr=(a,b)=>{const l1=lum(a),l2=lum(b);return +(((Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05)).toFixed(2))};
        const v=(n)=>root.getPropertyValue(n).trim();
        return { th_font: th.fontSize, th_color: th.color, muted_token: v('--muted'), bg: v('--bg'), surface2: v('--surface-2'),
                 contrast_muted_on_bg: cr(v('--muted'), v('--bg')), contrast_muted_on_surface2: cr(v('--muted'), v('--surface-2')),
                 contrast_success_badge: cr(v('--success'), v('--success-soft')),
                 btn_sm_height: sm ? sm.getBoundingClientRect().height : null,
                 smallest_font_px: Math.min(...[...document.querySelectorAll('body *')].map(e=>parseFloat(getComputedStyle(e).fontSize)).filter(Boolean)),
                 page_head_buttons: [...document.querySelectorAll('.page-head .actions button')].map(b=>b.innerText.trim()||b.title) } }""")

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
    vendor = next(v for v in d["data"] if v.get("vendor_type") in (None, "supplier"))
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

@scenario("S16_ap_bill_payment_button")
def s16(pg):
    """T3.1 — "Buat pembayaran" pada tagihan vendor yang disetujui dan masih bersisa (BIL/2026/VII/0002
    di produksi: 69 hari lewat jatuh tempo tanpa PAY, 4 Sep 2026). Dibuka sebagai finance (fin.create),
    tombolnya diklik: yang harus muncul formulir Pembayaran (bukan POST) dengan arah keluar dan jumlah =
    sisa tagihan; tersimpan tidak diuji di sini — itu formulir pembayaran biasa."""
    tok = token_for("finance@nusantara.test")
    s, d = api("finance/ap-bills?status=approved&per_page=50", tok)
    bill = next(b for b in d["data"] if float(b.get("outstanding") or 0) > 0)
    login(pg, "finance@nusantara.test")
    pg.goto(BASE + f"#/d/finance/ap-bills/{bill['id']}")
    pg.wait_for_selector(f".page-head h1:has-text('{bill['code']}')", timeout=15000); pg.wait_for_timeout(800)
    out = {"bill": bill["code"], "outstanding": bill["outstanding"], **read_action_bar(pg, "s16-ap-bill-bar")}
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
    for name, fn, arg in [("S10",s10,None),("S1",s1,None),("S2",s2,None),("S3",s3,None),("S4",s4,None),("S5",s5,None),("S6",s6,"b"),("S7",s7,None),("S8",s8,None),("S9",s9,None),("S11",s11,None),("S12",s12,None),("S13",s13,None),("S14",s14,None),("S15",s15,"b"),("S16",s16,None)]:
        if want and name not in want: continue
        fn(b if arg == "b" else fresh())
    b.close()

json.dump(R, open(f"{OUT}/results.json", "w"), ensure_ascii=False, indent=1)
print("saved results.json")
