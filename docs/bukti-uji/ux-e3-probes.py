#!/usr/bin/env python3
"""Probe formulir (views/form.js — halaman #/f/ dan dialog) untuk UX-E3 — angka yang harness tidak catat,
sebelum dan sesudah.

Pakai (server lokal di atas salinan DB yang sudah di-seed; onboarding diputuskan di sini):
  python3 ux-e3-probes.py <db.sqlite> <keluaran.json> [base-url]

Yang diukur per formulir × lebar (1440 pointer halus / 390 is_mobile) × kepadatan:
  * WADAH — .form-page (lebar, tinggi) atau .modal (lebar, tinggi, gulir .modal-body); kepala halaman
    (.page-head tinggi, .actions tinggi, teks tombol, apakah Simpan terlihat tanpa menggulir);
  * STRUKTUR — jumlah .form-section, h3 bagian, .field, label wajib, tinggi input pertama, tinggi input baris,
    table.lines (th, baris), .table-wrap (max-height, gulir), teks .lines-total, .error-summary, nav bagian
    (belum ada), kaki lengket (belum ada), elemen bergaya sebaris ([style]) di dalam formulir;
  * sekali per formulir × lebar: perhentian Tab dari awal #view sampai input pertama; elemen yang fokus
    sesudah dibuka; tinggi dokumen; galat konsol.
Formulir: PO baru (procurement), GRN baru (warehouse), Penawaran baru (sales), Jurnal baru (finance) sebagai
halaman #/f/; PO/1 ubah (#/f/…/1); Pelanggan (sales), Invoice AR (finance), Karyawan (hr) lewat Tambah di
daftarnya — dialog hari ini. Semua angka dibaca dari DOM yang benar-benar tergambar.
"""
import json
import sqlite3
import sys
import time

from playwright.sync_api import sync_playwright

CENSUS = sys.argv[1] == "--census"
if CENSUS:
    DB, OUT = None, sys.argv[2]
    BASE = sys.argv[3] if len(sys.argv) > 3 else "http://127.0.0.1:8000/app/"
else:
    DB, OUT = sys.argv[1], sys.argv[2]
    BASE = sys.argv[3] if len(sys.argv) > 3 else "http://127.0.0.1:8000/app/"

FORMS = [  # name, email, kind, target
    ("po_new", "procurement@nusantara.test", "page", "f/procurement/purchase-orders"),
    ("po_edit", "procurement@nusantara.test", "page", "f/procurement/purchase-orders/1"),
    ("grn_new", "warehouse@nusantara.test", "page", "f/inventory/goods-receipts"),
    ("qtn_new", "sales@nusantara.test", "page", "f/crm/quotations"),
    ("jrn_new", "finance@nusantara.test", "page", "f/finance/journals"),
    ("cust_new", "sales@nusantara.test", "list", "r/crm/customers"),
    ("inv_new", "finance@nusantara.test", "list", "r/finance/ar-invoices"),
    ("emp_new", "hr@nusantara.test", "list", "r/hr/employees"),
]
VIEWS = {"1440": dict(viewport={"width": 1440, "height": 900}),
         "390": dict(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)}


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


MEASURE = """() => {
  const q = (s, r) => (r || document).querySelector(s);
  const qa = (s, r) => [...(r || document).querySelectorAll(s)];
  const h = (n) => n ? Math.round(n.getBoundingClientRect().height * 10) / 10 : null;
  const w = (n) => n ? Math.round(n.getBoundingClientRect().width * 10) / 10 : null;
  const txt = (n) => n ? n.innerText.replace(/\\s+/g, ' ').trim() : null;
  const page = q('#view .form-page'); const modal = q('.overlay .modal');
  const root = page || modal;
  const head = q('#view .page-head');
  const onScreen = (n) => { if (!n) return null; const r = n.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; };
  // UX-E3.2: tombol halaman pindah ke kaki formulir (.form-foot); kepala dibaca dulu untuk kode lama.
  const btns = root ? qa(page ? '#view .form-page .form-foot button, #view .page-head .actions .btn, #view .page-head .actions button' : '.modal .modal-foot button', document) : [];
  const simpan = btns.find((b) => b.innerText.trim().startsWith('Simpan'));
  const firstInput = root && q('input:not([type=hidden]):not([type=checkbox]), select, textarea', root);
  const lineInput = root && q('table.lines tbody input, table.lines tbody select', root);
  const wrap = root && q('.table-wrap', root);
  return {
    container: page ? 'page' : modal ? 'modal' : null,
    page_w: w(page), page_h: h(page), modal_w: w(modal), modal_h: h(modal),
    modal_body_scroll: modal ? (q('.modal-body', modal).scrollHeight - q('.modal-body', modal).clientHeight) : null,
    head_h: page ? h(head) : null, actions_h: page ? h(q('.actions', head)) : null,
    buttons: btns.map((b) => b.innerText.trim()), simpan_on_screen: onScreen(simpan),
    sections: root ? qa('.form-section', root).length : null,
    section_titles: root ? qa('.form-section > h3', root).map(txt) : null,
    fields: root ? qa('.field', root).length : null,
    required: root ? qa('.field .req', root).length : null,
    input_h: h(firstInput), line_input_h: h(lineInput),
    lines_tables: root ? qa('table.lines', root).length : null,
    lines_th: root ? qa('table.lines thead th', root).length : null,
    lines_rows: root ? qa('table.lines tbody tr', root).length : null,
    wrap_max_h: wrap ? getComputedStyle(wrap).maxHeight : null,
    wrap_scroll: wrap ? wrap.scrollWidth - wrap.clientWidth : null,
    lines_total: root ? qa('.lines-total', root).map(txt) : null,
    lines_total_h: root ? h(q('.lines-total', root)) : null,
    primary_btn: (btns.find((b) => b.classList.contains('primary')) || {}).innerText || null,
    error_summary: qa('.error-summary').length, section_nav: qa('.form-nav, nav[aria-label="Bagian formulir"]').length,
    sticky_foot: qa('.form-foot').length,
    foot_position: q('.form-foot') ? getComputedStyle(q('.form-foot')).position : null, foot_h: h(q('.form-foot')),
    inline_styled: root ? qa('[style]', root).length : null,
    doc_h: Math.round(Math.max(document.documentElement.scrollHeight, (q('.main') || {}).scrollHeight || 0)),
    main_scroll: q('.main') ? q('.main').scrollHeight - q('.main').clientHeight : null,
    focused: document.activeElement ? (document.activeElement.tagName.toLowerCase() + (document.activeElement.className ? '.' + String(document.activeElement.className).split(' ')[0] : '')) : null,
  };
}"""

TABSTOPS = """() => {
  const view = document.querySelector('.overlay .modal') || document.querySelector('#view');
  const focusable = [...view.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]')]
    .filter((el) => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length > 0
      && getComputedStyle(el).visibility !== 'hidden' && !el.closest('[hidden], [inert]'));
  const first = view.querySelector('.form-page input:not([type=hidden]), .form-page select, .form-page textarea, .modal-body input:not([type=hidden]), .modal-body select, .modal-body textarea');
  let before = focusable.length;
  if (first) before = focusable.filter((el) => el.compareDocumentPosition(first) & Node.DOCUMENT_POSITION_FOLLOWING).length;
  return { total: focusable.length, to_first_input: before };
}"""

if CENSUS:
    import re, urllib.request
    src = urllib.request.urlopen(BASE + "js/views/form.js").read().decode()
    inline = len(re.findall(r"style:\s*\{", src))
    JS = """async () => {
      const m = await import('/app/js/schema.js?e3');
      const out = {};
      for (const [key, def] of Object.entries(m.RESOURCES)) {
        if (!def.form) continue;
        const sections = def.form.sections || [];
        const submit = (def.actions || []).find((a) => a.key === 'submit') || null;
        out[key] = { fields: sections.reduce((n, s) => n + (s.fields || []).length, 0), sections: sections.length,
          titled_sections: sections.filter((s) => s.title).length, advanced: sections.filter((s) => s.advanced).length,
          lines: (def.form.lines || []).length, layout: def.form.layout || null,
          submit: submit ? { perm: submit.perm || null, fields: (submit.fields || []).length, confirm: !!submit.confirm, inlineNote: !!submit.inlineNote, confirmResubmit: Array.isArray(submit.confirmResubmit) ? submit.confirmResubmit.length : (submit.confirmResubmit ? 1 : 0) } : null };
      }
      return out;
    }"""
    with sync_playwright() as p:
        b = p.chromium.launch(); pg = b.new_page()
        pg.goto(BASE, wait_until="domcontentloaded")
        forms = pg.evaluate(JS); b.close()
    tot = len(forms); lines = sum(1 for f in forms.values() if f["lines"]); long_ = sum(1 for f in forms.values() if not f["lines"] and f["fields"] > 8)
    one = sum(1 for f in forms.values() if f["sections"] == 1); subm = sum(1 for f in forms.values() if f["submit"]); sf = sum(1 for f in forms.values() if f["submit"] and f["submit"]["fields"])
    summary = {"forms": tot, "with_lines": lines, "long_no_lines": long_, "one_section": one, "with_submit": subm, "submit_with_fields": sf, "inline_style_objects_form_js": inline}
    json.dump({"summary": summary, "forms": forms}, open(OUT, "w"), ensure_ascii=False, indent=1)
    print("sensus", summary); sys.exit(0)

con = sqlite3.connect(DB)
con.execute("UPDATE users SET onboarding_status='skipped', onboarding_seen_at=datetime('now') WHERE onboarding_status IS NULL")
con.commit()
con.close()

result = {"base": BASE, "forms": {}}
with sync_playwright() as p:
    browser = p.chromium.launch()
    for vname, opts in VIEWS.items():
        by_email = {}
        for name, email, kind, target in FORMS:
            by_email.setdefault(email, []).append((name, kind, target))
        for email, forms in by_email.items():
            ctx = browser.new_context(**opts)
            pg = ctx.new_page()
            errs = []
            pg.on("console", lambda m: errs.append(m.text[:200]) if m.type == "error" else None)
            pg.on("pageerror", lambda e: errs.append(("pageerror " + str(e))[:200]))
            login(pg, email)
            for name, kind, target in forms:
                errs.clear()
                t0 = time.time()
                pg.goto(BASE + "#/home")
                pg.wait_for_timeout(300)
                pg.goto(BASE + "#/" + target)
                if kind == "page":
                    pg.wait_for_selector("#view .form-page, #view .alert.error", timeout=20000)
                else:
                    pg.wait_for_selector("#view .page-head .btn.primary, #view .page-head .actions button", timeout=20000)
                    pg.wait_for_timeout(600)
                    pg.click("#view .page-head .btn.primary")
                    pg.wait_for_selector(".overlay .modal, #view .form-page", timeout=15000)
                paint = int((time.time() - t0) * 1000)
                pg.wait_for_timeout(1200)
                cell = {"target": target, "paint_ms": paint, "density": {}}
                for density in ("compact", "normal", "comfortable"):
                    pg.evaluate("(d) => { document.documentElement.dataset.density = d; }", density)
                    pg.wait_for_timeout(250)
                    cell["density"][density] = pg.evaluate(MEASURE)
                pg.evaluate("() => { document.documentElement.dataset.density = 'normal'; }")
                pg.wait_for_timeout(200)
                cell["tabstops"] = pg.evaluate(TABSTOPS)
                # Simpan masih terlihat sesudah menggulir ke dasar formulir (ke .lines-total / kaki)?
                cell["simpan_on_screen_after_scroll"] = pg.evaluate("""() => {
                  const root = document.querySelector('#view .form-page') || document.querySelector('.overlay .modal-body');
                  if (!root) return null;
                  const end = root.querySelector('.lines-total') || root.lastElementChild;
                  end.scrollIntoView({ block: 'end' });
                  const btn = [...document.querySelectorAll('#view .page-head .actions button, #view .form-page button, .overlay .modal-foot button')].find((b) => b.innerText.trim().startsWith('Simpan'));
                  if (!btn) return null;
                  const r = btn.getBoundingClientRect();
                  return { on_screen: r.top >= 0 && r.bottom <= innerHeight, scroll_y: Math.round(scrollY) };
                }""")
                cell["console_errors"] = list(errs)
                result["forms"][f"{name}|{vname}"] = cell
                n = cell["density"]["normal"]
                print(vname, name, n["container"], "sections", n["sections"], "fields", n["fields"], "lines", n["lines_tables"], "buttons", n["buttons"], "simpan_on_screen", n["simpan_on_screen"], "tab", cell["tabstops"], "errs", len(errs), flush=True)
                # Tutup dialog / tinggalkan halaman bersih tanpa penjaga (formulir baru tidak kotor).
                if kind != "page":
                    pg.keyboard.press("Escape")
                    pg.wait_for_timeout(300)
                    if pg.locator(".modal button:has-text('Buang isian')").count():
                        pg.click(".modal button:has-text('Buang isian')")
            ctx.close()
    browser.close()

json.dump(result, open(OUT, "w"), ensure_ascii=False, indent=1)
print("ditulis", OUT, len(result["forms"]), "sel")
