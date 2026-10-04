#!/usr/bin/env python3
"""Probe halaman dokumen generik (views/detail.js) untuk UX-E2 — angka yang harness tidak catat,
sebelum dan sesudah.

Pakai (server lokal di atas salinan DB yang sudah di-seed; onboarding diputuskan di sini):
  python3 ux-e2-probes.py <db.sqlite> <keluaran.json> [base-url]
  python3 ux-e2-probes.py --census <keluaran.json> [base-url]     # sensus label formulir vs LABELS

Yang diukur per rute × peran × lebar (1440 pointer halus / 390 is_mobile) × kepadatan:
  * KEPALA — tinggi .page-head dan .page-head .actions, justify-content bilah, teks h1, teks lencana,
    teks .doc-kind / .doc-party (null sebelum E2.4), teks .doc-meta-item;
  * PANEL — .page-head + .action-panel: tinggi, kelas, judul, teks, dan tautan/tombol di
    .action-panel-actions (kosong sebelum E2.5);
  * UBIN — pasangan [label, nilai] dari .stat-row .stat (sebelum E2.3) dan .kv-tiles .kv-tile (sesudah);
  * INFORMASI — dt kartu Informasi berurutan, jumlah .info-group, label kelompok, ringkasan dan dt tiap
    details.kv-more ("Bidang lainnya"), dan dt yang muncul dua kali;
  * BARIS — tinggi baris tabel di kartu .detail-grid (table.data) per kepadatan (kepadatan beku, CONVENTIONS §13);
  * sekali per rute × peran × lebar: perhentian Tab dari elemen pertama #view sampai dt Informasi
    pertama; klik ke Setujui (1 bila tombolnya tampak) dan Tolak (+1, modal); ms sampai .card h2
    pertama; peringatan konsol `titleize:` dan galat konsol; jumlah .stat-row di luar .card.
  * Matriks peran pada PO yang DISETUJUI (fixture): direktur/procurement/warehouse/finance pada 1440 —
    tautan lanjutan di panel (teks + href) dan teks panelnya; tampilan tautan di bawah media print.
Fixture: dua PO lewat API sebagai procurement (PO A diajukan; PO B diajukan lalu disetujui direktur),
dihapus lagi di akhir (yang disetujui mungkin ditolak server — dicatat, bukan digagalkan).
Semua angka dibaca dari halaman yang benar-benar tergambar; tidak ada yang dikarang.
"""
import json
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright

CENSUS = sys.argv[1] == "--census"
if CENSUS:
    OUT = sys.argv[2]
    BASE = sys.argv[3] if len(sys.argv) > 3 else "http://127.0.0.1:8000/app/"
    DB = None
else:
    DB, OUT = sys.argv[1], sys.argv[2]
    BASE = sys.argv[3] if len(sys.argv) > 3 else "http://127.0.0.1:8000/app/"
API = BASE.replace("/app/", "/api/")

ROUTES = [
    ("po1", "procurement/purchase-orders/1"),      # disetujui, detail.groups
    ("inv1", "finance/ar-invoices/1"),             # rata, 8 kunci summary
    ("qtn3", "crm/quotations/3"),
    ("wo1", "procurement/work-orders/1"),          # ppn_rate di summary
    ("ctr1", "crm/contracts/1"),                   # quotation_id + quotation_code dikirim keduanya
    ("lead1", "crm/leads/1"),
]
ROLES = ["admin@nusantara.test", "direktur@nusantara.test", "procurement@nusantara.test"]
MATRIX_ROLES = ["direktur@nusantara.test", "procurement@nusantara.test", "warehouse@nusantara.test", "finance@nusantara.test"]
VIEWS = {"1440": dict(viewport={"width": 1440, "height": 900}),
         "390": dict(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)}


def api(path, token, method="GET", body=None):
    """Satu panggilan API; 429 pada masuk diulang sesudah Retry-After (login dibatasi 10/menit)."""
    for _ in range(6):
        req = urllib.request.Request(API + path, method=method, headers={
            "Authorization": f"Bearer {token}", "Accept": "application/json", "Content-Type": "application/json"})
        data = json.dumps(body).encode() if body is not None else None
        try:
            with urllib.request.urlopen(req, data=data, timeout=30) as r:
                return r.status, json.loads(r.read().decode() or "null")
        except urllib.error.HTTPError as e:
            if e.code == 429:
                wait = int(e.headers.get("Retry-After", "20")) + 1
                print("429", path, "tunggu", wait, flush=True)
                time.sleep(wait)
                continue
            return e.code, json.loads(e.read().decode() or "{}")
    raise RuntimeError(f"429 terus pada {path}")


def token_for(email):
    s, d = api("iam/auth/login", "", "POST", {"email": email, "password": "password"})
    return d["data"]["token"]


def login(pg, email):
    pg.goto(BASE)
    pg.wait_for_selector("input[type=email]", timeout=15000)
    pg.fill("input[type=email]", email)
    pg.fill("input[type=password]", "password")
    for _ in range(6):
        with pg.expect_response(lambda r: "iam/auth/login" in r.url, timeout=15000) as info:
            pg.click("button[type=submit]")
        if info.value.status != 429:
            break
        time.sleep(int(info.value.headers.get("retry-after", "20")) + 1)
    pg.wait_for_selector("nav.nav, .launcher, #view", timeout=20000)
    pg.wait_for_timeout(1000)


HEAD = """() => {
  const head = document.querySelector('.page-head');
  const bar = document.querySelector('.page-head .actions');
  const t = (sel) => { const n = document.querySelector(sel); return n ? n.innerText.replace(/\\s+/g, ' ').trim() : null; };
  return {
    head_h: head ? +head.getBoundingClientRect().height.toFixed(1) : null,
    bar_h: bar ? +bar.getBoundingClientRect().height.toFixed(1) : null,
    bar_justify: bar ? getComputedStyle(bar).justifyContent : null,
    h1: t('.page-head h1'),
    h1_doc_code: !!document.querySelector('.page-head h1.doc-code'),
    badges: [...document.querySelectorAll('.page-head .badge')].map((b) => b.innerText.trim()),
    kind: t('.page-head .doc-kind'),
    party: t('.page-head .doc-party'),
    meta: [...document.querySelectorAll('.page-head .doc-meta-item')].map((n) => n.innerText.replace(/\\s+/g, ' ').trim()),
    zones: document.querySelectorAll('.page-head .actions .zone').length,
    bar_buttons: [...document.querySelectorAll('.page-head .actions button, .page-head .actions summary')]
      .filter((n) => n.getClientRects().length).map((n) => (n.innerText.trim() || n.title).slice(0, 24)),
  };
}"""

PANEL = """() => {
  const p = document.querySelector('.page-head + .action-panel');
  if (!p) return null;
  const t = (sel) => { const n = p.querySelector(sel); return n ? n.innerText.replace(/\\s+/g, ' ').trim() : null; };
  return {
    h: +p.getBoundingClientRect().height.toFixed(1),
    cls: p.className,
    title: t('.action-panel-title'),
    text: t('.action-panel-text'),
    actions: [...p.querySelectorAll('.action-panel-actions button, .action-panel-actions a')]
      .map((n) => ({ label: n.innerText.trim(), href: n.getAttribute('href'), tag: n.tagName.toLowerCase() })),
  };
}"""

TILES = """() => ({
  stat_row: [...document.querySelectorAll('.stat-row .stat')].map((s) => [
    ((s.querySelector('.label') || {}).innerText || '').trim(), ((s.querySelector('.value') || {}).innerText || '').trim()]),
  kv_tiles: [...document.querySelectorAll('.kv-tiles .kv-tile')].map((s) => [
    ((s.querySelector('.kv-tile-label') || {}).innerText || '').trim(), ((s.querySelector('.kv-tile-value') || {}).innerText || '').trim()]),
  stat_rows_outside_cards: [...document.querySelectorAll('#view .stat-row')].filter((n) => !n.closest('.card')).length,
})"""

INFO = """() => {
  const info = [...document.querySelectorAll('.card')]
    .find((c) => { const h = c.querySelector('h2'); return h && h.innerText.trim() === 'Informasi'; });
  if (!info) return null;
  // textContent: dt di dalam details.kv-more tertutup tidak tergambar, innerText-nya kosong.
  const dts = [...info.querySelectorAll('dt')].map((d) => d.textContent.trim());
  const seen = {}; const dupl = [];
  for (const d of dts) { seen[d] = (seen[d] || 0) + 1; if (seen[d] === 2) dupl.push(d); }
  return {
    dt: dts,
    groups: info.querySelectorAll('.info-group').length,
    group_labels: [...info.querySelectorAll('.info-group-label')].map((n) => n.innerText.trim()),
    lainnya: [...info.querySelectorAll('details.kv-more')].map((d) => ({
      summary: ((d.querySelector('summary') || {}).textContent || '').trim(),
      dt: [...d.querySelectorAll('dt')].map((x) => x.textContent.trim()),
    })),
    dupl,
    cards: [...document.querySelectorAll('#view .card h2')].map((h) => h.innerText.replace(/\\s*\\(\\d+\\)/, '').trim()),
  };
}"""

LINES = """() => {
  const rows = [...document.querySelectorAll('#view .detail-grid .card table.data tbody tr')].map((tr) => +tr.getBoundingClientRect().height.toFixed(2));
  return rows.length ? { n: rows.length, min: Math.min(...rows), max: Math.max(...rows) } : null;
}"""

TABSTOPS = """() => {
  const view = document.querySelector('#view');
  const focusable = [...view.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]')]
    .filter((el) => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length > 0
      && getComputedStyle(el).visibility !== 'hidden' && !el.closest('[hidden], [inert]'));
  const info = [...document.querySelectorAll('.card')]
    .find((c) => { const h = c.querySelector('h2'); return h && h.innerText.trim() === 'Informasi'; });
  const firstDt = info && info.querySelector('dt');
  let before = focusable.length;
  if (firstDt) before = focusable.filter((el) => el.compareDocumentPosition(firstDt) & Node.DOCUMENT_POSITION_FOLLOWING).length;
  return { total: focusable.length, to_first_dt: before };
}"""

CLICKS = """() => {
  const vis = (label) => [...document.querySelectorAll('#view button')].some((b) => b.innerText.trim() === label && b.getClientRects().length);
  return { approve: vis('Setujui') ? 1 : null, reject: vis('Tolak') ? 2 : null, submit: vis('Ajukan') ? 1 : null };
}"""


def census():
    """Sensus label: kunci formulir (schema.js) yang labelnya berbeda dari LABELS (detail.js), dan kunci
    formulir tanpa entri LABELS. schema.js diimpor di Chromium (tidak ada node di host ini)."""
    src = open("/root/construction-erp/public/app/js/views/detail.js", encoding="utf-8").read()
    block = re.search(r"^const LABELS = \{(.*?)^\};", src, re.S | re.M).group(1)
    block = re.sub(r"/\*.*?\*/", "", block, flags=re.S)
    block = re.sub(r"//.*", "", block)
    labels = dict(re.findall(r"\b([a-z][a-z0-9_]*):\s*'([^']*)'", block))
    JS = """async () => {
      const m = await import('/app/js/schema.js?census');
      const out = {};
      for (const [key, def] of Object.entries(m.RESOURCES)) {
        if (!def.form) continue;
        out[key] = { detail: !!def.detail, fields: (def.form.sections || []).flatMap((s) => s.fields || []).map((f) => [f.key, f.label || null]) };
      }
      return out;
    }"""
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_page()
        pg.goto(BASE, wait_until="domcontentloaded")
        forms = pg.evaluate(JS)
        b.close()
    differ, missing = [], []
    for res, form in sorted(forms.items()):
        for key, label in form["fields"]:
            row = {"resource": res, "key": key, "form": label, "has_detail": form["detail"]}
            if key in labels:
                if labels[key] != label:
                    differ.append({**row, "labels": labels[key]})
            else:
                missing.append(row)
    # Hanya halaman dokumen generik (def.detail) yang menggambar label ini; sisanya formulir master data.
    d_detail = [x for x in differ if x["has_detail"]]
    m_detail = [x for x in missing if x["has_detail"]]
    out = {"base": BASE, "labels_count": len(labels), "forms": len(forms),
           "forms_with_detail": sum(1 for f in forms.values() if f["detail"]),
           "differ_count": len(differ), "missing_count": len(missing),
           "differ_count_with_detail": len(d_detail), "missing_count_with_detail": len(m_detail),
           "differ": differ, "missing_from_labels": missing}
    json.dump(out, open(OUT, "w"), ensure_ascii=False, indent=1)
    print("ditulis", OUT, "beda", len(differ), f"({len(d_detail)} berdetail)", "tanpa LABELS", len(missing), f"({len(m_detail)} berdetail)")


if CENSUS:
    census()
    sys.exit(0)

con = sqlite3.connect(DB)
con.execute("UPDATE users SET onboarding_status='skipped', onboarding_seen_at=datetime('now') WHERE onboarding_status IS NULL")
con.commit()
con.close()

# Fixture: PO A (diajukan) dan PO B (disetujui), sebagai procurement; keputusan oleh direktur.
tok_p = token_for("procurement@nusantara.test")
s, d = api("procurement/vendors?status=active&per_page=20", tok_p)
vendor = next((v for v in d["data"] if v.get("vendor_type") in (None, "supplier")), None)
if vendor is None:
    raise AssertionError("tidak ada vendor bertipe supplier/kosong — fixture PO tidak bisa dibuat")
fixtures = {}
for name, desc in (("A", "UX-E2 probe — PO diajukan"), ("B", "UX-E2 probe — PO disetujui")):
    s, d = api("procurement/purchase-orders", tok_p, "POST", {
        "vendor_id": vendor["id"], "order_date": "2026-10-01", "expected_date": "2026-10-15",
        "pr_bypass_reason": desc, "items": [{"description": desc, "qty": 2, "unit": "unit", "unit_price": 1500000}]})
    if s not in (200, 201):
        raise AssertionError(f"fixture PO {name} gagal: {s} {d}")
    fixtures[name] = {"id": d["data"]["id"], "code": d["data"]["code"]}
    fixtures[name]["submit_http"] = api(f"procurement/purchase-orders/{fixtures[name]['id']}/submit", tok_p, "POST", {})[0]
tok_d = token_for("direktur@nusantara.test")
fixtures["B"]["approve_http"] = api(f"procurement/purchase-orders/{fixtures['B']['id']}/approve", tok_d, "POST", {})[0]
print("fixture", fixtures, flush=True)

ROUTES = ROUTES + [("poA", f"procurement/purchase-orders/{fixtures['A']['id']}"),
                   ("poB", f"procurement/purchase-orders/{fixtures['B']['id']}")]

result = {"base": BASE, "fixtures": fixtures, "pages": {}, "matrix": {}, "print": {}}


def open_detail(pg, route, warn, errs):
    warn.clear()
    errs.clear()
    t0 = time.time()
    pg.goto(BASE + f"#/d/{route}")
    # Layar yang ditolak izin (errorState) juga punya .page-head — dicatat, bukan digagalkan.
    pg.wait_for_selector("#view .card h2, #view .alert.error", timeout=20000)
    paint = int((time.time() - t0) * 1000)
    pg.wait_for_timeout(1200)
    return paint


try:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for vname, opts in VIEWS.items():
            for email in ROLES:
                ctx = browser.new_context(**opts)
                pg = ctx.new_page()
                warn, errs = [], []
                pg.on("console", lambda m: (warn.append(m.text[:200]) if m.type == "warning" and m.text.startswith("titleize:")
                                            else errs.append(m.text[:200]) if m.type == "error" else None))
                pg.on("pageerror", lambda e: errs.append(("pageerror " + str(e))[:200]))
                login(pg, email)
                role = email.split("@")[0]
                for name, route in ROUTES:
                    paint = open_detail(pg, route, warn, errs)
                    cell = {"route": route, "paint_ms": paint, "density": {}}
                    if not pg.query_selector("#view .card h2"):
                        cell["refused"] = (pg.inner_text("#view .alert.error") or "")[:160]
                        result["pages"][f"{name}|{role}|{vname}"] = cell
                        print(vname, role, name, "ditolak:", cell["refused"][:60], flush=True)
                        continue
                    for density in ("compact", "normal", "comfortable"):
                        pg.evaluate("(d) => { document.documentElement.dataset.density = d; }", density)
                        pg.wait_for_timeout(150)
                        cell["density"][density] = {"head": pg.evaluate(HEAD), "panel": pg.evaluate(PANEL),
                                                    "lines": pg.evaluate(LINES)}
                    pg.evaluate("() => { document.documentElement.dataset.density = 'normal'; }")
                    pg.wait_for_timeout(100)
                    cell["tiles"] = pg.evaluate(TILES)
                    cell["info"] = pg.evaluate(INFO)
                    cell["tabstops"] = pg.evaluate(TABSTOPS)
                    cell["clicks"] = pg.evaluate(CLICKS)
                    cell["titleize_warnings"] = list(warn)
                    cell["console_errors"] = list(errs)
                    result["pages"][f"{name}|{role}|{vname}"] = cell
                    print(vname, role, name, "dt", (cell["info"] or {}).get("dt", [])[:3], "warn", len(warn), flush=True)
                ctx.close()
        # Matriks peran pada PO B (disetujui): tautan lanjutan, 1440 normal; lalu media print.
        for email in MATRIX_ROLES:
            ctx = browser.new_context(**VIEWS["1440"])
            pg = ctx.new_page()
            warn, errs = [], []
            login(pg, email)
            role = email.split("@")[0]
            open_detail(pg, ROUTES[-1][1], warn, errs)
            panel = pg.evaluate(PANEL)
            entry = {"panel": panel, "head": pg.evaluate(HEAD)}
            link = next((a for a in (panel or {}).get("actions", []) if a["tag"] == "a"), None)
            if link and email == "direktur@nusantara.test":
                pg.click(".page-head + .action-panel .action-panel-actions a")
                pg.wait_for_timeout(1200)
                entry["after_click_hash"] = pg.evaluate("location.hash")
                open_detail(pg, ROUTES[-1][1], warn, errs)
            pg.emulate_media(media="print")
            pg.wait_for_timeout(200)
            entry["print"] = pg.evaluate("""() => {
              const p = document.querySelector('.page-head + .action-panel');
              const a = p && p.querySelector('.action-panel-actions a, .action-panel-actions button');
              return { panel_display: p ? getComputedStyle(p).display : null, action_display: a ? getComputedStyle(a).display : null,
                       actions_display: p && p.querySelector('.action-panel-actions') ? getComputedStyle(p.querySelector('.action-panel-actions')).display : null };
            }""")
            pg.emulate_media(media="screen")
            result["matrix"][role] = entry
            print("matrix", role, (panel or {}).get("actions"), entry["print"], flush=True)
            ctx.close()
        browser.close()
finally:
    for name in ("A", "B"):
        if name in fixtures:
            fixtures[name]["delete_http"] = api(f"procurement/purchase-orders/{fixtures[name]['id']}", tok_p, "DELETE")[0]
    json.dump(result, open(OUT, "w"), ensure_ascii=False, indent=1)
    print("ditulis", OUT, "hapus fixture", {k: v.get("delete_http") for k, v in fixtures.items()})
