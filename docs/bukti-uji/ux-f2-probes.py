#!/usr/bin/env python3
"""Probe Beranda, Pekerjaan Saya dan beranda modul untuk UX-F2 — angka yang harness tidak catat,
sebelum dan sesudah.

Pakai (server lokal di atas SALINAN DB yang sudah di-seed; onboarding diputuskan di sini, dan dua PR uji
ditanam lewat API: satu diajukan, satu diajukan lalu ditolak direktur):
  python3 ux-f2-probes.py <db.sqlite> <keluaran.json> [base-url]

Per peran × lebar (1440 pointer halus / 390 is_mobile):
  * PENDARATAN — setiap permintaan /api/ sesudah login (nama, jumlah, ms per permintaan);
  * BERANDA (#/home) — h1, judul bagian (.home-section-title), ubin (.home-tile), kartu tindakan, tinggi
    dokumen, y ubin pertama, elemen bergaya sebaris ([style]) di #view, tombol di kepala;
  * #/pekerjaan — h1, tab, baris (hari ini: halaman "tidak ditemukan");
  * #/m/prc dan #/m/hr — eyebrow, stepper (jumlah langkah, yang bernomor), .module-kpi, .module-card,
    .module-recent chip, bagian "Perlu perhatian", [style];
  * 390 saja — isi bilah bawah (.bn-label) dan baris tetap sidebar (.nav-pinned .lbl);
  * di dalam halaman dengan token sesi — core/my-work, core/deadlines, core/modules: status, ms, ukuran.
"""
import json
import sqlite3
import sys
import time
import urllib.request

from playwright.sync_api import sync_playwright

DB, OUT = sys.argv[1], sys.argv[2]
BASE = sys.argv[3] if len(sys.argv) > 3 else "http://127.0.0.1:8000/app/"
API = BASE.split("/app/")[0] + "/api/"

ROLES = [
    ("direktur", "direktur@nusantara.test"),
    ("procurement", "procurement@nusantara.test"),
    ("warehouse", "warehouse@nusantara.test"),
    ("teknisi", "teknisi@nusantara.test"),
    ("hr", "hr@nusantara.test"),
]
VIEWS = {"1440": dict(viewport={"width": 1440, "height": 900}),
         "390": dict(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)}


def api(path, token, method="GET", body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(API + path, method=method, headers={
        "Accept": "application/json", "Content-Type": "application/json", "X-Api-Token": token}, data=data)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "{}")


def token_for(email):
    for _ in range(6):
        s, d = api("iam/auth/login", "", "POST", {"email": email, "password": "password"})
        if s == 200:
            return d["data"]["token"]
        time.sleep(21)
    raise SystemExit("login gagal " + email)


def plant_fixture():
    """Dua PR milik procurement: PR-UJI-F2 menunggu, satu lagi ditolak direktur dengan catatan."""
    tp, td = token_for("procurement@nusantara.test"), token_for("direktur@nusantara.test")
    made = {}
    for tag in ("menunggu", "ditolak"):
        s, d = api("procurement/purchase-requisitions", tp, "POST", {
            "needed_date": "2026-12-01", "purpose": f"UJI-F2 {tag}",
            "items": [{"description": "Semen uji F2", "qty": 5, "unit": "sak", "estimated_price": 70000}]})
        if s not in (200, 201):
            made[tag] = {"status": s, "error": str(d)[:200]}
            continue
        rid = d["data"]["id"]
        s2, _ = api(f"procurement/purchase-requisitions/{rid}/submit", tp, "POST", {})
        made[tag] = {"id": rid, "code": d["data"].get("code"), "submit": s2}
        if tag == "ditolak":
            s3, _ = api(f"procurement/purchase-requisitions/{rid}/reject", td, "POST", {"note": "Spesifikasi kurang jelas, perbaiki."})
            made[tag]["reject"] = s3
    return made


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
    pg.wait_for_selector("nav.nav, #view", timeout=20000)


CENSUS = """() => {
  const q = (s, r) => (r || document).querySelector(s);
  const qa = (s, r) => [...(r || document).querySelectorAll(s)];
  const txt = (n) => n ? (n.textContent || '').replace(/\\s+/g, ' ').trim() : null;
  const v = q('#view');
  const first = q('.home-tile', v);
  return {
    hash: location.hash,
    h1: txt(q('#view h1')),
    sections: qa('.home-section-title, .module-section, .module-block-title, .module-recent-title', v).map(txt),
    tiles: qa('.home-tile', v).length,
    chips: qa('.home-chip', v).length,
    action_panels: qa('.action-panel, .home-card', v).length,
    quick: qa('.home-quick-tile', v).length,
    stats: qa('.home-stat, .kv-tile', v).length,
    head_buttons: qa('.page-head .actions .btn, .page-head .actions a', v).map(txt),
    tabs: qa('.tabs button', v).map(txt),
    rows: qa('.work-row, table.data tbody tr', v).length,
    empty: q('.empty', v) ? { kind: (q('.empty .illus', v) || {}).dataset ? q('.empty .illus', v).dataset.kind : null, text: txt(q('.empty', v)).slice(0, 120) } : null,
    eyebrow: txt(q('.module-head .eyebrow', v)),
    stepper: { steps: qa('.stepper .step', v).length, counted: qa('.stepper .step-count', v).map(txt), linked: qa('.stepper a.step-link', v).length },
    module_kpis: qa('.module-kpi', v).length,
    module_cards: qa('.module-card', v).length,
    attn_rows: qa('.attn-row', v).length,
    inline_styled: qa('[style]', v).length,
    doc_h: Math.round(Math.max(document.documentElement.scrollHeight, (q('.main') || {}).scrollHeight || 0)),
    first_tile_y: first ? Math.round(first.getBoundingClientRect().top + scrollY) : null,
    h_scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  };
}"""

NAVBAR = """() => ({
  bottom_bar: [...document.querySelectorAll('.bottom-nav .bn-item')].map((n) => (n.textContent || '').replace(/\\s+/g, ' ').trim()),
  pinned: [...document.querySelectorAll('nav.nav .nav-pinned a')].map((n) => ((n.querySelector('.lbl') || n).textContent || '').replace(/\\s+/g, ' ').trim()),
})"""

API_IN_PAGE = """async (path) => {
  const t0 = performance.now();
  const r = await fetch('/api/' + path, { headers: { Accept: 'application/json', 'X-Api-Token': localStorage.getItem('nusantara_erp_token') } });
  let body = null; try { body = await r.json(); } catch (e) { body = null; }
  const ms = Math.round(performance.now() - t0);
  const data = body && body.data !== undefined ? body.data : null;
  return { status: r.status, ms, rows: Array.isArray(data) ? data.length : null, meta: (body || {}).meta || null,
    sample: Array.isArray(data) ? data.slice(0, 3).map((x) => x.bucket || x.tier || x.prefix || null) : null };
}"""

con = sqlite3.connect(DB)
con.execute("UPDATE users SET onboarding_status='skipped', onboarding_seen_at=datetime('now') WHERE onboarding_status IS NULL")
con.commit()
con.close()

result = {"base": BASE, "fixture": plant_fixture(), "cells": {}}
with sync_playwright() as p:
    browser = p.chromium.launch()
    for vname, opts in VIEWS.items():
        for role, email in ROLES:
            ctx = browser.new_context(**opts)
            pg = ctx.new_page()
            errs, reqs = [], []
            pg.on("console", lambda m: errs.append(m.text[:200]) if m.type == "error" else None)
            pg.on("pageerror", lambda e: errs.append(("pageerror " + str(e))[:200]))
            t0 = [0.0]
            pg.on("request", lambda r: reqs.append([r.url, time.time()]) if "/api/" in r.url else None)
            done = {}
            pg.on("response", lambda r: done.__setitem__(r.url, time.time()) if "/api/" in r.url else None)
            login(pg, email)
            pg.wait_for_timeout(2500)
            after = []
            seen_login = False
            for url, t in reqs:
                if "iam/auth/login" in url:
                    seen_login = True
                    after = []
                    continue
                if seen_login:
                    after.append({"api": url.split("/api/")[1].split("?")[0], "ms": round((done.get(url, t) - t) * 1000)})
            cell = {"landing_hash": pg.evaluate("() => location.hash"), "landing_api_calls": len(after), "landing_api": after}
            cell["home"] = pg.evaluate(CENSUS)
            cell["navbar"] = pg.evaluate(NAVBAR)
            for name, target in (("pekerjaan", "#/pekerjaan"), ("module_prc", "#/m/prc"), ("module_hr", "#/m/hr")):
                pg.goto(BASE + target)
                pg.wait_for_timeout(1800)
                cell[name] = pg.evaluate(CENSUS)
            if vname == "1440":
                cell["api_timing"] = {k: pg.evaluate(API_IN_PAGE, k) for k in ("core/my-work", "core/deadlines", "core/modules", "core/inbox")}
            cell["console_errors"] = list(errs)
            result["cells"][f"{role}|{vname}"] = cell
            h = cell["home"]
            print(vname, role, "landing", cell["landing_hash"], "calls", cell["landing_api_calls"], "tiles", h["tiles"],
                  "bar", cell["navbar"]["bottom_bar"], "pekerjaan_h1", cell["pekerjaan"]["h1"], "prc_stepper", cell["module_prc"]["stepper"]["steps"],
                  "errs", len(errs), flush=True)
            ctx.close()
    browser.close()

json.dump(result, open(OUT, "w"), ensure_ascii=False, indent=1)
print("ditulis", OUT, len(result["cells"]), "sel")
