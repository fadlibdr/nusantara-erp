#!/usr/bin/env python3
"""
tests/harness/burst.py — harness burst untuk lima layanan berisiko di MySQL
(Fase 0 T0.4, docs/ROADMAP-HASHMICRO.md §3).

Hanya pustaka standar Python 3 (ThreadPoolExecutor + urllib): erp1 tidak punya
node, dan harness ini harus bisa dijalankan di sana sebelum dan sesudah
cut-over. Sasarannya server CLI PHP yang menyajikan basis data MySQL coretan:

    set -a; . <berkas-cred>; set +a                  # DB_USERNAME, DB_PASSWORD
    tests/harness/serve-mysql.sh erp_scratch 8004 &  # PHP_CLI_SERVER_WORKERS=8
    python3 tests/harness/burst.py --base http://127.0.0.1:8004 \
        --parallel 20,40,80 --out docs/bukti-uji/burst-mysql-2026-09-05.json

Keadaan awal yang diandalkan: erp_scratch hasil `migrate:fresh --seed`
(DatabaseSeeder demo) — pengguna admin@/direktur@nusantara.test, proyek
PRJ-2026-001, tiket TKT-202607-0003, vendor VND-0004, pajak PPh 23, saldo
stok ITM-0001/0002 di WH-PUSAT dan ITM-0001 di gudang site. Saldo dibaca
ulang lewat API pada saat jalan, jadi harness ini boleh diulang tanpa reseed
selama stoknya belum habis.

Skenario, tiap tingkat paralel P (permintaan = P, semua dilepas bersamaan
lewat Barrier):
  1  pr_create              POST purchase-requisitions, satu mask PR — nomor
                            unik & kontigu (DocumentNumberService)
  2a journal_create         POST journals, mask JV, satu periode (JournalService)
  2b journal_post           POST journals/{id}/post oleh pemeriksa — semua
                            terposting, SoD tetap berlaku
  3  issue_post             P bon keluar item yang sama, jumlah per bon dipilih
                            agar hanya ~separuh yang bisa dipenuhi — stok TIDAK
                            pernah negatif, jumlah yang lolos = floor(saldo/qty)
                            (StockService::postIssue)
  4  field_report_create    POST field-reports tiket yang sama, mask PM
                            (FieldReportService)
  4b daily_report_same_date POST daily-reports proyek & tanggal yang sama —
                            tepat satu 201, sisanya 422, nol 500 (indeks
                            live_key T0.2 di bawah beban)
  5  ap_bill_approve        P tagihan vendor ber-PPh disetujui bersamaan —
                            nomor bukti potong unik & kontigu (BuktiPotongNumber)

Laporan per skenario: permintaan, 2xx, 4xx (penolakan bisnis yang diharapkan),
5xx, 503, deadlock (SQLSTATE 40001 / galat 1213 di storage/logs/laravel.log),
lock wait (1205), p50/p95/maks ms, dan pemeriksaan kebenaran datanya. Tidak
ada retry di sisi klien — kalau server menjawab 500, itu dicatat sebagai 500.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import re
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import date, datetime, timedelta, timezone

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))

DEADLOCK_RE = re.compile(r"Deadlock found|SQLSTATE\[40001\]|\b1213\b")
LOCKWAIT_RE = re.compile(r"Lock wait timeout exceeded|\b1205\b")


# --------------------------------------------------------------------- HTTP

class Api:
    def __init__(self, base: str, token: str = ""):
        self.base = base.rstrip("/") + "/api/"
        self.token = token

    def call(self, method: str, path: str, body=None, timeout: float = 180.0):
        """(status, json_body, elapsed_ms). Galat jaringan = status 0."""
        headers = {"Accept": "application/json", "Content-Type": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(self.base + path, method=method, headers=headers, data=data)
        started = time.perf_counter()
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                status, raw = r.status, r.read()
        except urllib.error.HTTPError as e:
            status, raw = e.code, e.read()
        except Exception as e:  # koneksi ditolak / timeout — dicatat, bukan disembunyikan
            return 0, {"message": f"{type(e).__name__}: {e}"}, (time.perf_counter() - started) * 1000
        elapsed = (time.perf_counter() - started) * 1000
        try:
            parsed = json.loads(raw.decode() or "{}")
        except ValueError:
            parsed = {"message": raw[:300].decode(errors="replace")}
        return status, parsed, elapsed


def login(base: str, email: str, password: str) -> Api:
    status, body, _ = Api(base).call("POST", "iam/auth/login", {"email": email, "password": password})
    if status != 200:
        sys.exit(f"login {email} gagal: {status} {body.get('message')}")
    return Api(base, body["data"]["token"])


# -------------------------------------------------------------------- burst

def burst(tasks, parallel: int):
    """Menjalankan semua tasks (callable → (status, body, ms)) bersamaan.

    Pekerja = jumlah tugas; Barrier menahan semuanya sampai siap sehingga
    permintaan benar-benar tiba berbarengan, bukan menetes sesuai waktu
    pembuatan thread.
    """
    gate = threading.Barrier(len(tasks))
    results = [None] * len(tasks)

    def run(i, task):
        gate.wait(timeout=60)
        results[i] = task()

    with ThreadPoolExecutor(max_workers=max(parallel, len(tasks))) as pool:
        list(pool.map(lambda it: run(*it), enumerate(tasks)))
    return results


def percentile(values, p: float):
    if not values:
        return None
    ordered = sorted(values)
    return round(ordered[max(0, math.ceil(p * len(ordered)) - 1)], 1)


class LogScanner:
    def __init__(self, path: str):
        self.path = path
        self.offset = 0

    def mark(self):
        try:
            self.offset = os.path.getsize(self.path)
        except OSError:
            self.offset = 0

    def since_mark(self):
        try:
            with open(self.path, "rb") as fh:
                fh.seek(self.offset)
                text = fh.read().decode(errors="replace")
        except OSError:
            return {"deadlocks": None, "lock_waits": None, "log": "?"}
        return {
            "deadlocks": len(DEADLOCK_RE.findall(text)),
            "lock_waits": len(LOCKWAIT_RE.findall(text)),
            "new_bytes": len(text),
        }


def summarise(name: str, parallel: int, results, log: dict, checks: dict, extra=None):
    statuses = [r[0] for r in results]
    times = [r[2] for r in results]
    messages = {}
    for status, body, _ in results:
        if status >= 400 or status == 0:
            msg = str(body.get("message", ""))[:160] if isinstance(body, dict) else "?"
            messages.setdefault(f"{status} {msg}", 0)
            messages[f"{status} {msg}"] += 1
    row = {
        "scenario": name,
        "parallel": parallel,
        "requests": len(results),
        "2xx": sum(1 for s in statuses if 200 <= s < 300),
        "4xx": sum(1 for s in statuses if 400 <= s < 500),
        "5xx": sum(1 for s in statuses if s >= 500),
        "503": sum(1 for s in statuses if s == 503),
        "network_errors": sum(1 for s in statuses if s == 0),
        "deadlocks": log.get("deadlocks"),
        "lock_waits": log.get("lock_waits"),
        "p50_ms": percentile(times, 0.50),
        "p95_ms": percentile(times, 0.95),
        "max_ms": percentile(times, 1.0),
        "wall_ms": round(max(times), 1) if times else None,
        "checks": checks,
        "refusals": messages,
    }
    if extra:
        row.update(extra)
    row["ok"] = all(v is True for v in checks.values()) and row["5xx"] == 0 and row["network_errors"] == 0 \
        and (row["deadlocks"] or 0) == 0
    return row


def contiguity(codes):
    """Nomor di ujung kode (…/0007 → 7): unik dan tanpa lubang?"""
    numbers = []
    for code in codes:
        m = re.search(r"(\d+)$", str(code))
        if not m:
            return {"unique": False, "contiguous": False, "reason": f"kode tanpa nomor: {code}"}
        numbers.append(int(m.group(1)))
    unique = len(set(numbers)) == len(numbers)
    ordered = sorted(numbers)
    contiguous = bool(ordered) and ordered == list(range(ordered[0], ordered[0] + len(ordered)))
    return {"unique": unique, "contiguous": contiguous,
            "range": f"{ordered[0]}..{ordered[-1]}" if ordered else "-"}


def today() -> str:
    return date.today().isoformat()


# ---------------------------------------------------------------- scenarios

def sc_pr_create(maker: Api, checker: Api, parallel: int, ctx: dict):
    def task(i):
        return lambda: maker.call("POST", "procurement/purchase-requisitions", {
            "needed_date": (date.today() + timedelta(days=7)).isoformat(),
            "purpose": f"Burst T0.4 #{i}",
            "items": [{"description": "Semen Portland 50kg", "qty": 1, "unit": "zak"}],
        })
    results = burst([task(i) for i in range(parallel)], parallel)
    codes = [r[1]["data"]["code"] for r in results if r[0] == 201]
    c = contiguity(codes)
    checks = {"all_created": len(codes) == parallel, "numbers_unique": c["unique"], "numbers_contiguous": c["contiguous"]}
    return results, checks, {"codes": c.get("range"), "sample": codes[:3]}


def sc_journal_create(maker: Api, checker: Api, parallel: int, ctx: dict):
    def task(i):
        return lambda: maker.call("POST", "finance/journals", {
            "journal_date": today(),
            "description": f"Burst T0.4 jurnal #{i}",
            "lines": [
                {"account_id": ctx["cash_account_id"], "debit": 100000, "description": "Kas"},
                {"account_id": ctx["bank_account_id"], "credit": 100000, "description": "Bank"},
            ],
        })
    results = burst([task(i) for i in range(parallel)], parallel)
    created = [r[1]["data"] for r in results if r[0] == 201]
    ctx["journal_ids"] = [d["id"] for d in created]
    c = contiguity([d["code"] for d in created])
    checks = {"all_created": len(created) == parallel, "numbers_unique": c["unique"], "numbers_contiguous": c["contiguous"]}
    return results, checks, {"codes": c.get("range")}


def sc_journal_post(maker: Api, checker: Api, parallel: int, ctx: dict):
    ids = ctx.get("journal_ids", [])
    if not ids:
        return [], {"skipped": "tidak ada jurnal dari 2a"}, {}
    results = burst([(lambda jid=jid: checker.call("POST", f"finance/journals/{jid}/post")) for jid in ids], parallel)
    posted = [r[1]["data"] for r in results if r[0] == 200]
    checks = {"all_posted": len(posted) == len(ids),
              "all_status_posted": all(d.get("status") == "posted" for d in posted)}
    return results, checks, {}


STOCK_PLAN = [(1, 1), (2, 1), (1, 2)]  # (item_id, warehouse_id) per tingkat paralel


def stock_balance(api: Api, item_id: int, warehouse_id: int):
    status, body, _ = api.call("GET", f"inventory/stock/balances?item_id={item_id}&warehouse_id={warehouse_id}&per_page=5")
    rows = body.get("data", []) if status == 200 else []
    return float(rows[0]["qty"]) if rows else 0.0


def sc_issue_post(maker: Api, checker: Api, parallel: int, ctx: dict):
    item_id, warehouse_id = STOCK_PLAN[ctx["level_index"] % len(STOCK_PLAN)]
    before = stock_balance(maker, item_id, warehouse_id)
    if before <= 0:
        return [], {"skipped": f"saldo item {item_id} gudang {warehouse_id} = {before}; reseed erp_scratch"}, {}
    qty_each = max(1, math.ceil(before / max(1, parallel // 2)))
    expected_ok = int(before // qty_each)

    ids = []
    for i in range(parallel):
        status, body, _ = maker.call("POST", "inventory/issues", {
            "warehouse_id": warehouse_id,
            "issue_date": today(),
            "purpose": f"Burst T0.4 bon #{i}",
            "items": [{"item_id": item_id, "qty": qty_each}],
        })
        if status != 201:
            return [], {"setup_failed": f"{status} {body.get('message')}"}, {}
        ids.append(body["data"]["id"])

    results = burst([(lambda iid=iid: maker.call("POST", f"inventory/issues/{iid}/post")) for iid in ids], parallel)
    ok = sum(1 for r in results if r[0] == 200)
    after = stock_balance(maker, item_id, warehouse_id)
    checks = {
        "stock_never_negative": after >= 0,
        "posted_exactly_what_stock_allowed": ok == expected_ok,
        "balance_matches_postings": abs(after - (before - ok * qty_each)) < 0.0005,
    }
    return results, checks, {"item_id": item_id, "warehouse_id": warehouse_id, "qty_before": before,
                             "qty_each": qty_each, "expected_ok": expected_ok, "posted": ok, "qty_after": after}


def sc_field_report_create(maker: Api, checker: Api, parallel: int, ctx: dict):
    def task(i):
        return lambda: maker.call("POST", "servicedesk/field-reports", {
            "ticket_id": ctx["ticket_id"],
            "report_date": today(),
            "technician_employee_id": ctx["technician_employee_id"],
            "findings": f"Burst T0.4 temuan #{i}",
            "actions_taken": "Pemeriksaan unit, pembersihan filter",
        })
    results = burst([task(i) for i in range(parallel)], parallel)
    codes = [r[1]["data"]["code"] for r in results if r[0] == 201]
    c = contiguity(codes)
    checks = {"all_created": len(codes) == parallel, "numbers_unique": c["unique"], "numbers_contiguous": c["contiguous"]}
    return results, checks, {"codes": c.get("range")}


def sc_daily_report_same_date(maker: Api, checker: Api, parallel: int, ctx: dict):
    report_date = (date.today() - timedelta(days=ctx["level_index"])).isoformat()

    def task(i):
        return lambda: maker.call("POST", "projects/daily-reports", {
            "project_id": ctx["project_id"],
            "report_date": report_date,
            "activities": f"Burst T0.4 laporan #{i}",
            "manpower_count": 5,
        })
    results = burst([task(i) for i in range(parallel)], parallel)
    created = [r[1]["data"] for r in results if r[0] == 201]
    refused = sum(1 for r in results if r[0] == 422)
    checks = {"exactly_one_created": len(created) == 1,
              "others_refused_422": refused == parallel - len(created),
              "no_500": all(r[0] < 500 for r in results)}
    # Dihapus lagi (soft delete) supaya tanggal ini bisa dipakai ulang — dan
    # jalur "hapus lalu catat ulang" T0.2 ikut teruji pada harness berikutnya.
    for d in created:
        maker.call("DELETE", f"projects/daily-reports/{d['id']}")
    return results, checks, {"report_date": report_date}


def sc_ap_bill_approve(maker: Api, checker: Api, parallel: int, ctx: dict):
    stamp = datetime.now().strftime("%H%M%S")
    ids = []
    for i in range(parallel):
        status, body, _ = maker.call("POST", "finance/ap-bills", {
            "vendor_id": ctx["vendor_id"],
            "description": f"Burst T0.4 jasa #{i}",
            "dpp": 10000000,
            "ppn_amount": 0,
            "pph_tax_id": ctx["pph_tax_id"],
            "pph_amount": 200000,
            "bill_date": today(),
            "due_date": (date.today() + timedelta(days=30)).isoformat(),
            "vendor_invoice_no": f"BURST-{stamp}-{ctx['level_index']}-{i}",
        })
        if status != 201:
            return [], {"setup_failed": f"create {status} {body.get('message')}"}, {}
        bill_id = body["data"]["id"]
        status, body, _ = maker.call("POST", f"finance/ap-bills/{bill_id}/submit")
        if status != 200:
            return [], {"setup_failed": f"submit {status} {body.get('message')}"}, {}
        ids.append(bill_id)

    results = burst([(lambda bid=bid: checker.call("POST", f"finance/ap-bills/{bid}/approve")) for bid in ids], parallel)
    approved = [r[1]["data"] for r in results if r[0] == 200]
    bupot = [d.get("bupot_no") for d in approved]
    c = contiguity([b for b in bupot if b])
    checks = {"all_approved": len(approved) == parallel,
              "every_bill_has_bupot": all(bool(b) for b in bupot),
              "bupot_unique": c["unique"], "bupot_contiguous": c["contiguous"]}
    return results, checks, {"bupot": c.get("range"), "sample": [b for b in bupot[:2]]}


SCENARIOS = [
    ("1", "pr_create", sc_pr_create),
    ("2a", "journal_create", sc_journal_create),
    ("2b", "journal_post", sc_journal_post),
    ("3", "issue_post", sc_issue_post),
    ("4", "field_report_create", sc_field_report_create),
    ("4b", "daily_report_same_date", sc_daily_report_same_date),
    ("5", "ap_bill_approve", sc_ap_bill_approve),
]


# --------------------------------------------------------------------- main

def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--base", default=os.environ.get("ERP_BASE", "http://127.0.0.1:8004"))
    ap.add_argument("--parallel", default="20,40,80", help="tingkat paralel, dipisah koma")
    ap.add_argument("--scenarios", default="all", help="mis. 1,3,5 atau all")
    ap.add_argument("--out", default=None, help="berkas JSON bukti; bawaan: hanya tabel di layar")
    ap.add_argument("--log", default=os.path.join(ROOT, "storage", "logs", "laravel.log"))
    ap.add_argument("--maker", default="admin@nusantara.test")
    ap.add_argument("--checker", default="direktur@nusantara.test",
                    help="pemeriksa: memposting jurnal & menyetujui tagihan (SoD melarang pembuatnya)")
    ap.add_argument("--password", default=os.environ.get("ERP_PASSWORD", "password"))
    ap.add_argument("--workers", type=int, default=int(os.environ.get("PHP_CLI_SERVER_WORKERS", "8")),
                    help="dicatat ke laporan; server dijalankan terpisah (serve-mysql.sh)")
    ap.add_argument("--note", default="", help="catatan bebas ke laporan (versi MySQL, host, dsb.)")
    args = ap.parse_args()

    levels = [int(x) for x in args.parallel.split(",") if x.strip()]
    wanted = None if args.scenarios == "all" else set(args.scenarios.split(","))

    maker = login(args.base, args.maker, args.password)
    checker = login(args.base, args.checker, args.password)

    # Fixture demo: dicari lewat API, bukan ditebak id-nya.
    ctx = {}
    s, accounts, _ = maker.call("GET", "finance/accounts?per_page=500")
    by_code = {a["code"]: a["id"] for a in accounts.get("data", [])} if s == 200 else {}
    ctx["cash_account_id"] = by_code.get("1-1100")
    ctx["bank_account_id"] = by_code.get("1-1210")
    s, taxes, _ = maker.call("GET", "finance/taxes?per_page=100")
    ctx["pph_tax_id"] = next((t["id"] for t in taxes.get("data", []) if t.get("code") == "PPH23"), None) if s == 200 else None
    s, vendors, _ = maker.call("GET", "procurement/vendors?per_page=100")
    ctx["vendor_id"] = next((v["id"] for v in vendors.get("data", []) if v.get("code") == "VND-0004"), None) if s == 200 else None
    s, projects, _ = maker.call("GET", "projects?per_page=10")
    ctx["project_id"] = next((p["id"] for p in projects.get("data", []) if p.get("code") == "PRJ-2026-001"), None) if s == 200 else None
    s, tickets, _ = maker.call("GET", "servicedesk/tickets?per_page=50")
    ctx["ticket_id"] = next((t["id"] for t in tickets.get("data", []) if t.get("status") == "assigned"), None) if s == 200 else None
    s, employees, _ = maker.call("GET", "hr/employees?per_page=5")
    ctx["technician_employee_id"] = (employees.get("data") or [{}])[0].get("id") if s == 200 else None
    missing = [k for k, v in ctx.items() if v is None]
    if missing:
        sys.exit(f"fixture demo tidak ditemukan lewat API: {missing} — erp_scratch belum di-seed?")

    scanner = LogScanner(args.log)
    rows = []
    started = datetime.now(timezone.utc)
    for level_index, parallel in enumerate(levels):
        ctx["level_index"] = level_index
        for key, name, fn in SCENARIOS:
            if wanted and key not in wanted and name not in wanted:
                continue
            scanner.mark()
            results, checks, extra = fn(maker, checker, parallel, ctx)
            log = scanner.since_mark()
            row = summarise(name, parallel, results, log, checks, extra)
            row["key"] = key
            rows.append(row)
            print(f"[{key:>2}] {name:<24} P={parallel:<3} req={row['requests']:<3} 2xx={row['2xx']:<3} 4xx={row['4xx']:<3} "
                  f"5xx={row['5xx']:<2} 503={row['503']:<2} dl={row['deadlocks']} lw={row['lock_waits']} "
                  f"p95={row['p95_ms']} ms  {'OK' if row['ok'] else 'GAGAL'} {checks}", flush=True)

    report = {
        "generated_at": started.isoformat(timespec="seconds"),
        "finished_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "base": args.base,
        "server": {"kind": "php -S (server.php)", "workers": args.workers, "note": args.note or "?"},
        "levels": levels,
        "maker": args.maker,
        "checker": args.checker,
        "log": args.log,
        "totals": {
            "requests": sum(r["requests"] for r in rows),
            "5xx": sum(r["5xx"] for r in rows),
            "503": sum(r["503"] for r in rows),
            "deadlocks": sum((r["deadlocks"] or 0) for r in rows),
            "lock_waits": sum((r["lock_waits"] or 0) for r in rows),
            "scenarios_ok": sum(1 for r in rows if r["ok"]),
            "scenarios": len(rows),
        },
        "scenarios": rows,
    }
    report["verdict"] = "LULUS" if report["totals"]["scenarios_ok"] == len(rows) and rows else "GAGAL"
    print(f"\n{report['verdict']}: {report['totals']}")
    if args.out:
        with open(args.out, "w") as fh:
            json.dump(report, fh, indent=2, ensure_ascii=False)
            fh.write("\n")
        print(f"ditulis: {args.out}")
    return 0 if report["verdict"] == "LULUS" else 1


if __name__ == "__main__":
    sys.exit(main())
