#!/usr/bin/env python3
"""
Pengukuran T0.7 (ROADMAP-HASHMICRO Fase 0): P permintaan DAFTAR yang tiba
bersamaan pada tumpukan nginx/php-fpm yang sebenarnya — 40 paralel → 0×503 dan
p95 ≤ 1,5 s; 80 paralel → 0×503 dan p95 ≤ 3 s.

Hanya GET (baca): aman dijalankan terhadap basis data produksi sesudah
cut-over, tidak membuat satu baris pun. Satu pengguna, satu token; pembatas
laju API (120/menit/pengguna) tidak tersentuh selama setiap gelombang < 120 dan
gelombang berikutnya menunggu --pause detik (bawaan 65).

    python3 tests/harness/list-burst.py --base https://erp1.pi2.co.id \
        --email <akun> --password <sandi> --parallel 40,80 \
        --out docs/bukti-uji/t07-list-burst-<tanggal>.json

Pustaka standar saja (tidak ada node di erp1). Tanpa retry: 503 adalah 503.
"""

import argparse
import json
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

TARGETS = {40: 1500, 80: 3000}  # p95 ms per roadmap; other P: no target, just measured


def call(base, path, token=None, body=None, timeout=180.0):
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    data = None
    if body is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(body).encode()
    req = urllib.request.Request(f"{base.rstrip('/')}/api/{path}", data=data, headers=headers, method="POST" if body is not None else "GET")
    started = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            raw = r.read()
            status = r.status
    except urllib.error.HTTPError as e:
        raw = e.read()
        status = e.code
    except Exception:
        raw, status = b"", 0
    ms = (time.perf_counter() - started) * 1000
    try:
        parsed = json.loads(raw.decode() or "{}")
    except ValueError:
        parsed = {}
    return status, parsed, ms


def wave(base, path, token, parallel):
    barrier = threading.Barrier(parallel)

    def one():
        barrier.wait()
        return call(base, path, token)

    with ThreadPoolExecutor(max_workers=parallel) as pool:
        results = list(pool.map(lambda _: one(), range(parallel)))

    ms = sorted(r[2] for r in results)
    statuses = [r[0] for r in results]
    p = lambda q: ms[min(len(ms) - 1, int(round(q * (len(ms) - 1))))]
    target = TARGETS.get(parallel)
    p95 = p(0.95)
    return {
        "parallel": parallel,
        "path": path,
        "requests": len(results),
        "2xx": sum(1 for s in statuses if 200 <= s < 300),
        "429": statuses.count(429),
        "503": statuses.count(503),
        "5xx": sum(1 for s in statuses if s >= 500),
        "network_error": statuses.count(0),
        "p50_ms": round(p(0.5)),
        "p95_ms": round(p95),
        "max_ms": round(ms[-1]),
        "target_p95_ms": target,
        "pass": (statuses.count(503) == 0 and sum(1 for s in statuses if s >= 500) == 0 and (target is None or p95 <= target)),
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True)
    ap.add_argument("--email", required=True)
    ap.add_argument("--password", required=True)
    ap.add_argument("--path", default="procurement/purchase-orders", help="endpoint daftar (relatif terhadap /api/)")
    ap.add_argument("--parallel", default="40,80")
    ap.add_argument("--pause", type=float, default=65.0, help="jeda antar gelombang (detik) agar pembatas 120/menit tidak ikut terukur")
    ap.add_argument("--out")
    args = ap.parse_args()

    status, body, _ = call(args.base, "iam/auth/login", body={"email": args.email, "password": args.password})
    if status != 200:
        sys.exit(f"login gagal: {status} {body.get('message')}")
    token = body["data"]["token"]

    status, _, ms = call(args.base, args.path, token)
    if status != 200:
        sys.exit(f"{args.path} menjawab {status} untuk satu permintaan — perbaiki dulu sebelum mengukur")
    print(f"pemanasan: {args.path} 200 dalam {ms:.0f} ms")

    waves = []
    for i, p in enumerate(int(x) for x in args.parallel.split(",")):
        if i:
            print(f"jeda {args.pause:.0f} s")
            time.sleep(args.pause)
        w = wave(args.base, args.path, token, p)
        waves.append(w)
        print(
            f"P={p:3d}: 2xx={w['2xx']:3d} 429={w['429']} 503={w['503']} 5xx={w['5xx']} err={w['network_error']} "
            f"p50={w['p50_ms']} p95={w['p95_ms']} max={w['max_ms']} ms  target p95 ≤ {w['target_p95_ms'] or '—'}  → {'LULUS' if w['pass'] else 'GAGAL'}"
        )

    report = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "base": args.base,
        "path": args.path,
        "waves": waves,
        "all_pass": all(w["pass"] for w in waves),
    }
    if args.out:
        with open(args.out, "w") as fh:
            json.dump(report, fh, indent=2, ensure_ascii=False)
            fh.write("\n")
        print(f"ditulis: {args.out}")
    sys.exit(0 if report["all_pass"] else 1)


if __name__ == "__main__":
    main()
