#!/usr/bin/env python3
"""Probe daftar generik untuk UX-E1 — angka yang harness tidak catat, sebelum dan sesudah.

Pakai (server lokal di atas salinan DB yang sudah di-seed; onboarding diputuskan di sini):
  python3 ux-e1-probes.py <db.sqlite> <keluaran.json> [base-url]

Yang diukur per rute × peran:
  * tinggi baris SATU-BARIS per jenis (teks / lencana / tombol) × kepadatan (padat/normal/lega)
    × pointer (halus 1440 px / kasar = is_mobile 1440 px). Baris yang memuat .cell-sub atau
    .cell-fold dilewati — keduanya dua baris dengan sengaja. "tombol" = baris yang punya
    pemicu aksi di sel aksinya (tombol ikon lama ATAU pemicu ⋯ baru);
  * tinggi baris kepala tabel;
  * perhentian Tab di #view, dari kotak Cari sampai tombol halaman berikutnya;
  * permintaan /api/ per muat daftar PO (polos dan ?status=draft);
  * tinggi bilah alat (dari puncak kartu daftar sampai .list-body) pada 1440 dan 390 px,
    dengan 0 dan 2 saringan;
  * jalan ke Ubah / Hapus / Cetak sebuah baris: 1 klik bila tombolnya langsung di baris,
    2 bila lewat menu ⋯ (dibaca dari DOM, tidak diklik).
Semua angka dibaca dari halaman yang benar-benar tergambar; tidak ada yang dikarang.
"""
import json
import sqlite3
import sys
import time

from playwright.sync_api import sync_playwright

DB, OUT = sys.argv[1], sys.argv[2]
BASE = sys.argv[3] if len(sys.argv) > 3 else "http://127.0.0.1:8000/app/"
ROUTES = ["procurement/purchase-orders", "inventory/item-categories", "finance/accounts", "crm/customers",
          "crm/quotations", "servicedesk/tickets", "projects/weekly-progress"]
ROLES = ["admin@nusantara.test", "direktur@nusantara.test"]

con = sqlite3.connect(DB)
con.execute("UPDATE users SET onboarding_status='skipped', onboarding_seen_at=datetime('now') WHERE onboarding_status IS NULL")
con.commit()
con.close()

ROWS = """() => {
  const out = { text: [], badge: [], button: [] };
  for (const tr of document.querySelectorAll('#view table.data tbody tr')) {
    if (tr.querySelector('.cell-sub, .cell-fold')) continue;
    const h = +tr.getBoundingClientRect().height.toFixed(2);
    const acts = tr.querySelector('.cell-actions');
    const kind = acts && acts.querySelector('button') ? 'button' : (tr.querySelector('.badge') ? 'badge' : 'text');
    out[kind].push(h);
  }
  const head = document.querySelector('#view table.data thead tr');
  const summary = (list) => list.length ? { n: list.length, min: Math.min(...list), max: Math.max(...list) } : null;
  return { text: summary(out.text), badge: summary(out.badge), button: summary(out.button),
           header: head ? +head.getBoundingClientRect().height.toFixed(2) : null };
}"""

TABSTOPS = """() => {
  const view = document.querySelector('#view');
  const focusable = [...view.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')]
    .filter((el) => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length > 0
      && getComputedStyle(el).visibility !== 'hidden' && !el.closest('[hidden], [inert]'));
  const search = focusable.findIndex((el) => el.getAttribute('aria-label') === 'Cari');
  const next = focusable.findIndex((el) => el.title === 'Berikutnya');
  return { total: focusable.length, search_to_next: search >= 0 && next >= 0 ? next - search : null };
}"""

TOOLBAR = """() => {
  const card = document.querySelector('#view .card');
  const body = document.querySelector('#view .list-body');
  return card && body ? +(body.getBoundingClientRect().top - card.getBoundingClientRect().top).toFixed(1) : null;
}"""

ACTIONS = """() => {
  const row = [...document.querySelectorAll('#view table.data tbody tr')].find((tr) => tr.querySelector('.cell-actions'));
  if (!row) return { reachable: 'tidak ada aksi baris' };
  const menu = row.querySelector('.overflow-menu');
  if (menu) return { via: 'menu', clicks: 2 };
  const titles = [...row.querySelectorAll('.cell-actions button')].map((b) => b.title || b.innerText.trim());
  return { via: 'tombol', clicks: 1, buttons: titles };
}"""


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
    pg.wait_for_selector("nav.nav", timeout=20000)
    pg.wait_for_timeout(1000)


def open_list(pg, route):
    pg.goto(BASE + f"#/r/{route}")
    pg.wait_for_selector("#view table.data tbody tr, #view .empty", timeout=20000)
    pg.wait_for_timeout(900)


result = {"base": BASE, "rows": {}, "tabstops": {}, "requests": {}, "toolbar": {}, "actions": {}}
with sync_playwright() as p:
    browser = p.chromium.launch()
    for pointer, opts in (("fine", dict(viewport={"width": 1440, "height": 900})),
                          ("coarse", dict(viewport={"width": 1440, "height": 900}, is_mobile=True, has_touch=True))):
        for email in ROLES:
            ctx = browser.new_context(**opts)
            pg = ctx.new_page()
            login(pg, email)
            role = email.split("@")[0]
            for route in ROUTES:
                open_list(pg, route)
                cell = {}
                for density in ("compact", "normal", "comfortable"):
                    pg.evaluate("(d) => { document.documentElement.dataset.density = d; }", density)
                    pg.wait_for_timeout(150)
                    cell[density] = pg.evaluate(ROWS)
                pg.evaluate("() => { document.documentElement.dataset.density = 'normal'; }")
                result["rows"][f"{route}|{role}|{pointer}"] = cell
                if pointer == "fine":
                    result["tabstops"][f"{route}|{role}"] = pg.evaluate(TABSTOPS)
                    result["actions"][f"{route}|{role}"] = pg.evaluate(ACTIONS)
            ctx.close()
    # Permintaan per muat dan tinggi bilah alat, sebagai admin.
    for label, opts in (("1440", dict(viewport={"width": 1440, "height": 900})),
                        ("390", dict(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True))):
        ctx = browser.new_context(**opts)
        pg = ctx.new_page()
        login(pg, "admin@nusantara.test")
        for name, query in (("polos", ""), ("dua saringan", "?status=draft&vendor_id=1")):
            seen = []
            handler = lambda r: seen.append(r.url.split("/api/")[-1]) if "/api/" in r.url else None
            pg.on("request", handler)
            pg.goto(BASE + "#/home")
            pg.wait_for_timeout(800)
            seen.clear()
            pg.goto(BASE + f"#/r/procurement/purchase-orders{query}")
            pg.wait_for_selector("#view table.data tbody tr, #view .empty", timeout=20000)
            pg.wait_for_timeout(1500)
            pg.remove_listener("request", handler)
            if label == "1440":
                result["requests"][name] = {"count": len(seen), "sample": seen}
            result["toolbar"][f"{label}|{name}"] = pg.evaluate(TOOLBAR)
        ctx.close()
    browser.close()

json.dump(result, open(OUT, "w"), ensure_ascii=False, indent=1)
print("ditulis", OUT)
