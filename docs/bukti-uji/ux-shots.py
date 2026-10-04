#!/usr/bin/env python3
"""Tujuh layar baseline redesain UI/UX, terang dan gelap, 390 dan 1440 px (UX-0.3, UX-C, UX-D).

Pakai (server lokal di atas salinan DB yang sudah di-seed):
  python3 ux-shots.py <db.sqlite> <direktori-keluaran> [base-url] [satu-layar]

Berkas keluaran <nama>-<tema>-<lebar>.jpg; nama layar sama sejak UX-0 (termasuk "2-tugas-saya", kini
layar Persetujuan) supaya pasangan sebelum/sesudah tetap sejajar per nama berkas.

KOREKSI UX-D (3 Okt 2026): salinan UX-0 dari skrip ini memakai #/m/procurement untuk "beranda modul
Pengadaan". Awalan modulnya prc, jadi kedua set tangkapan sebelumnya (ux-baseline, ux-c) memotret halaman
"Modul procurement tidak dikenal" — tanpa satu pun page error, dan karena itu tidak tertangkap. Rute di
bawah sudah #/m/prc; layar 6 ketiga set dipotret ulang dari kode masing-masing paket.

KOREKSI UX-E1 (4 Okt 2026): salinan yang di-commit di gerbang UX-D tidak mengimpor sys/sqlite3/time dan
langsung jatuh dengan NameError — tangkapan UX-D dibuat dari salinan kerja di luar repo. Impor dipulihkan.

UX-E1 (argumen keempat "e1"): daftar PO dalam lima keadaan — polos, tab + chip, popover Filter,
menu ⋯ baris, popover Atur kolom — terang dan gelap, 390 dan 1440 px, sebagai procurement@ (yang
boleh mengubah PO draf; satu PO draf dibuat lewat API untuk menu ⋯ dan dihapus lagi di akhir).
Berkas keluaran e1-<n>-<keadaan>-<tema>-<lebar>.jpg.

UX-E2 (argumen keempat "e2"): layar dokumen — PO/1 sebagai procurement@ (baris jenis, ubin nilai kunci,
panel sukses dengan tautan GRN), PO fixture yang DIAJUKAN sebagai direktur@ (panel attn, bilah dengan
Setujui/Tolak; di 390 px juga dengan panel catatan terbuka), INV/1 sebagai finance@ (8 ubin, Informasi
rata) — terang dan gelap, 390 dan 1440 px. Fixture dibuat lewat API sebagai procurement dan dihapus di
akhir (PO yang diajukan mungkin ditolak server — dicatat). Berkas keluaran e2-<n>-<layar>-<tema>-<lebar>.jpg."""
import json, sqlite3, sys, time, urllib.request
from playwright.sync_api import sync_playwright
DB=sys.argv[1]; OUT=sys.argv[2]; BASE=sys.argv[3] if len(sys.argv)>3 else "http://127.0.0.1:8000/app/"; ONLY=sys.argv[4] if len(sys.argv)>4 else None
SCREENS=[ # name, role, hash (None = whatever the app lands on after login)
 ("1-landing","admin@nusantara.test",None),
 ("2-tugas-saya","direktur@nusantara.test","#/tugas"),
 ("3-po-daftar","procurement@nusantara.test","#/r/procurement/purchase-orders"),
 ("4-po-form","procurement@nusantara.test","#/f/procurement/purchase-orders"),
 ("5-po-detail","procurement@nusantara.test","#/d/procurement/purchase-orders/1"),
 ("6-pengadaan-modul","procurement@nusantara.test","#/m/prc"),
 ("7-lapangan","site-manager@nusantara.test","#/lapangan"),
]
SCREENS=[x for x in SCREENS if not ONLY or x[0]==ONLY or ONLY in ("e1","e2")]
VIEWS={"390":dict(viewport={"width":390,"height":844},is_mobile=True,has_touch=True,device_scale_factor=1),
       "1440":dict(viewport={"width":1440,"height":900})}
con=sqlite3.connect(DB)
for _,e,_h in SCREENS:
    con.execute("UPDATE users SET onboarding_status='skipped', onboarding_seen_at=datetime('now') WHERE email=? AND onboarding_status IS NULL",(e,))
con.commit(); con.close()
def login(pg,email):
    pg.goto(BASE); pg.wait_for_selector("input[type=email]",timeout=15000)
    pg.fill("input[type=email]",email); pg.fill("input[type=password]","password")
    for _ in range(6):
        with pg.expect_response(lambda r:"iam/auth/login" in r.url,timeout=15000) as i: pg.click("button[type=submit]")
        r=i.value
        if r.status!=429: break
        w=int(r.headers.get("retry-after","20")); print("429 wait",w,flush=True); time.sleep(w+1)
    pg.wait_for_selector("nav.nav, .launcher, #view",timeout=20000); pg.wait_for_timeout(1500)
def api(path, token, method="GET", body=None):
    req=urllib.request.Request(BASE.replace("/app/","/api/")+path, method=method, headers={
        "Authorization":f"Bearer {token}","Accept":"application/json","Content-Type":"application/json"})
    with urllib.request.urlopen(req, data=json.dumps(body).encode() if body is not None else None, timeout=30) as r:
        return json.loads(r.read().decode() or "null")
def e1_shots(b):
    """UX-E1 — lima keadaan daftar PO (lihat docstring)."""
    email="procurement@nusantara.test"
    con=sqlite3.connect(DB); con.execute("UPDATE users SET onboarding_status='skipped', onboarding_seen_at=datetime('now') WHERE email=? AND onboarding_status IS NULL",(email,)); con.commit(); con.close()
    tok=api("iam/auth/login","", "POST", {"email":email,"password":"password"})["data"]["token"]
    vendor=next(v for v in api("procurement/vendors?status=active&per_page=20",tok)["data"] if v.get("vendor_type") in (None,"supplier"))
    po=api("procurement/purchase-orders",tok,"POST",{"vendor_id":vendor["id"],"order_date":"2026-10-01","expected_date":"2026-10-15",
        "pr_bypass_reason":"Tangkapan UX-E1 — PO draf untuk menu baris","items":[{"description":"Kabel NYY 4x16","qty":50,"unit":"m","unit_price":185000}]})["data"]
    route="#/r/procurement/purchase-orders"
    STATES=[ # name, hash, action(pg)
     ("1-polos", route+"?page=1", None),
     ("2-tab-chip", route+"?q=PO&status=approved", None),
     ("3-filter", route+"?page=1", lambda pg: (pg.click(".list-toolbar .filter-toggle"), pg.wait_for_selector(".filter-pop:not([hidden])"))),
     # Gulir pemicunya ke tempatnya DULU: di 390 px ⋯ berada di luar layar di dalam .table-wrap, dan
     # gulir yang dipicu klik Playwright tiba sesudah menu terbuka — menu mengambang menutup diri pada gulir.
     ("4-menu-baris", route+"?page=1", lambda pg: (
         pg.eval_on_selector(f"#view table.data tbody tr:has-text('{po['code']}') .overflow-menu .menu-trigger", "(n) => n.scrollIntoView({ block: 'center', inline: 'center' })"),
         pg.wait_for_timeout(500),
         pg.click(f"#view table.data tbody tr:has-text('{po['code']}') .overflow-menu .menu-trigger"),
         pg.wait_for_selector(".menu-pop.floating"))),
     ("5-atur-kolom", route+"?page=1", lambda pg: (pg.click(".list-toolbar .col-toggle"), pg.wait_for_selector(".col-pop:not([hidden])"))),
    ]
    try:
        for vname,opts in VIEWS.items():
            ctx=b.new_context(**opts); pg=ctx.new_page(); errs=[]
            pg.on("pageerror",lambda e:errs.append(str(e)[:160]))
            login(pg,email)
            for name,h,action in STATES:
                for theme in ("light","dark"):
                    pg.goto(BASE+"#/home"); pg.wait_for_timeout(300)
                    pg.goto(BASE+h); pg.wait_for_selector("#view table.data tbody tr, #view .empty",timeout=20000); pg.wait_for_timeout(1500)
                    pg.evaluate("(t)=>{document.documentElement.dataset.theme=t}",theme); pg.wait_for_timeout(300)
                    if action: action(pg); pg.wait_for_timeout(400)
                    pg.screenshot(path=f"{OUT}/e1-{name}-{theme}-{vname}.jpg",type="jpeg",quality=82)
                    pg.keyboard.press("Escape")
                print(vname,name,"errs",errs[-2:],flush=True)
            ctx.close()
    finally:
        api(f"procurement/purchase-orders/{po['id']}",tok,"DELETE")
def e2_shots(b):
    """UX-E2 — layar dokumen (lihat docstring)."""
    emails=("procurement@nusantara.test","direktur@nusantara.test","finance@nusantara.test")
    con=sqlite3.connect(DB)
    for e in emails: con.execute("UPDATE users SET onboarding_status='skipped', onboarding_seen_at=datetime('now') WHERE email=? AND onboarding_status IS NULL",(e,))
    con.commit(); con.close()
    tok=api("iam/auth/login","", "POST", {"email":emails[0],"password":"password"})["data"]["token"]
    vendor=next(v for v in api("procurement/vendors?status=active&per_page=20",tok)["data"] if v.get("vendor_type") in (None,"supplier"))
    po=api("procurement/purchase-orders",tok,"POST",{"vendor_id":vendor["id"],"order_date":"2026-10-01","expected_date":"2026-10-15",
        "pr_bypass_reason":"Tangkapan UX-E2 — PO diajukan untuk panel attn","items":[{"description":"Kabel NYY 4x16","qty":50,"unit":"m","unit_price":185000}]})["data"]
    api(f"procurement/purchase-orders/{po['id']}/submit",tok,"POST",{})
    def open_note(pg):
        if pg.locator(".page-head .actions details.action-note > summary").count():
            pg.click(".page-head .actions details.action-note > summary"); pg.wait_for_selector(".page-head .actions details.action-note[open]")
    SHOTS=[ # name, email, hash, action, only-view
     ("1-po-terkunci", emails[0], "#/d/procurement/purchase-orders/1", None, None),
     ("2-po-diajukan", emails[1], f"#/d/procurement/purchase-orders/{po['id']}", None, None),
     ("3-po-diajukan-catatan", emails[1], f"#/d/procurement/purchase-orders/{po['id']}", open_note, "390"),
     ("4-invoice", emails[2], "#/d/finance/ar-invoices/1", None, None),
    ]
    try:
        for vname,opts in VIEWS.items():
            for email in emails:
                ctx=b.new_context(**opts); pg=ctx.new_page(); errs=[]
                pg.on("pageerror",lambda e:errs.append(str(e)[:160]))
                login(pg,email)
                for name,e,h,action,only in SHOTS:
                    if e!=email or (only and only!=vname): continue
                    for theme in ("light","dark"):
                        pg.goto(BASE+"#/home"); pg.wait_for_timeout(300)
                        pg.goto(BASE+h); pg.wait_for_selector(".page-head + .action-panel",timeout=20000); pg.wait_for_timeout(1500)
                        pg.evaluate("(t)=>{document.documentElement.dataset.theme=t}",theme); pg.wait_for_timeout(300)
                        if action: action(pg); pg.wait_for_timeout(400)
                        pg.screenshot(path=f"{OUT}/e2-{name}-{theme}-{vname}.jpg",type="jpeg",quality=82)
                    print(vname,name,"errs",errs[-2:],flush=True)
                ctx.close()
    finally:
        try: api(f"procurement/purchase-orders/{po['id']}",tok,"DELETE"); print("fixture dihapus",flush=True)
        except Exception as ex: print("fixture tidak terhapus (PO diajukan):",str(ex)[:80],flush=True)
with sync_playwright() as p:
    b=p.chromium.launch()
    if ONLY=="e1":
        e1_shots(b); b.close(); sys.exit(0)
    if ONLY=="e2":
        e2_shots(b); b.close(); sys.exit(0)
    for vname,opts in VIEWS.items():
        for email in sorted({e for _,e,_h in SCREENS}):
            ctx=b.new_context(**opts); pg=ctx.new_page(); errs=[]
            pg.on("pageerror",lambda e:errs.append(str(e)[:160]))
            login(pg,email)
            for name,e,h in SCREENS:
                if e!=email: continue
                if h: pg.goto(BASE+h); pg.wait_for_timeout(2600)
                landed=pg.evaluate("location.hash")
                for theme in ("light","dark"):
                    pg.evaluate("(t)=>{document.documentElement.dataset.theme=t}",theme); pg.wait_for_timeout(350)
                    f=f"{OUT}/{name}-{theme}-{vname}.jpg"
                    pg.screenshot(path=f,type="jpeg",quality=82)
                print(vname,name,"landed",landed,"errs",errs[-2:],flush=True)
            ctx.close()
    b.close()
