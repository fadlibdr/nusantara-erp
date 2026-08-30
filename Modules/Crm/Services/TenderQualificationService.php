<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7 — the qualification annexes of a bid, ASSEMBLED READ-ONLY from the
 * masters this ERP already keeps: personnel (hr_certificates), plant
 * (ast_assets) and subcontractors (prc_vendors).
 *
 * ============================================================================
 * THREE RAW READS, AND WHY THEY ARE RAW
 *
 * ARCHITECTURE.md draws no arrow from Crm to HrPayroll, to Assets, or to
 * Procurement — the sales side reaches the delivery side through Estimation
 * and nothing else. Importing three models here to compose three annexes
 * would buy three new dependency arrows into the module that must stay the
 * thinnest, so all three are reads BY VALUE behind Schema::hasTable: the
 * device BastPrerequisiteService uses on qc_ncr, ArInvoiceService uses on four
 * Projects tables, and TerminBillingService already uses on prj_milestones
 * from inside this very module. Nothing is written; every enum value the
 * queries mention ('rented', 'subcontractor', 'active') is spelled as a
 * literal, and each is pinned by a test so it cannot drift.
 *
 * NO SELECTION TABLE, deliberately. The roadmap asks for a "penyusun
 * kualifikasi" and this is one: it lists what the company can actually put
 * forward. WHICH of those people and machines a particular bid nominates is a
 * decision the owner has not asked to record, and inventing a table for it
 * would create a second roster nobody maintains — worse than no roster, since
 * a stale nomination list is exactly the thing a tender committee checks.
 * ============================================================================
 *
 * THE LAPSED-COVER DECISION, written down because it is a judgement, and it
 * governs BOTH personnel and plant:
 *
 *   COVER THAT HAS RUN OUT IS NEVER LISTED AS A QUALIFICATION, AND IS NEVER
 *   SILENTLY DROPPED EITHER.
 *
 * personnel() and equipment() each return TWO buckets. `memenuhi` holds what
 * the company can really put forward on the sheet's date — certificates still
 * valid (and those that never expire), plant we own and plant whose LEASE IS
 * STILL RUNNING. `kedaluwarsa` holds the rest, with the date the cover ran out:
 * a lapsed certificate with its expiry, a rented machine with the day its lease
 * ended. F/SBD and F/DA print the first bucket only — a sheet claiming a
 * qualified engineer must not rest on an SKK that expired in March, and a sheet
 * offering an excavator must not offer one already returned to its lessor.
 *
 * The lease half exists because NOTHING IN ASSETS MOVES A RENTED MACHINE'S
 * STATUS WHEN ITS LEASE EXPIRES: the row stays `available` for ever, so
 * filtering on status alone lists returned plant as available plant.
 *
 * Dropping the lapsed ones entirely was the other candidate and is worse: the
 * bid team would discover the gap after losing the tender, or worse, not at
 * all. Listing them in their own bucket, named as lapsed, is the disclosure
 * that lets somebody renew the certificate — or extend the lease — before the
 * deadline.
 *
 * "Valid on WHICH date" is the SHEET'S date, not today: a qualification sheet
 * dated 12 September must answer as at 12 September however long afterwards it
 * is reprinted — the same rule PrintableDocuments states for SISA MASA BERLAKU
 * on a kontrak layanan. Both entry points take that date as their argument, and
 * BOTH CALLERS PASS ONE: the API passes ?as_of= (the screen's tanggal acuan),
 * and the printed sheets pass the tender package's SUBMISSION DEADLINE, which
 * is the date the registry heads them with (PrintableDocuments, 'date' =>
 * 'submission_deadline'). A package that records no deadline falls back to the
 * print date, and the sheet prints the date it answered as at either way.
 */
class TenderQualificationService
{
    /**
     * Personil bersertifikat, dipisah menurut masih berlaku atau tidak.
     *
     * @return array{as_of: string, memenuhi: array<int, array<string, mixed>>, kedaluwarsa: array<int, array<string, mixed>>}
     */
    public function personnel(?Carbon $asOf = null, ?string $certificateType = null): array
    {
        // copy(): Carbon bisa berubah, dan startOfDay() pada objek milik
        // pemanggil akan diam-diam menggeser tanggal yang dipegangnya.
        $date = ($asOf === null ? Carbon::now() : $asOf->copy())->startOfDay();

        $payload = ['as_of' => $date->toDateString(), 'memenuhi' => [], 'kedaluwarsa' => []];

        if (! Schema::hasTable('hr_certificates') || ! Schema::hasTable('hr_employees')) {
            return $payload;
        }

        $rows = DB::table('hr_certificates as c')
            ->join('hr_employees as e', 'e.id', '=', 'c.employee_id')
            ->whereNull('c.deleted_at')
            ->whereNull('e.deleted_at')
            // 'active' by value: an employee who has resigned is not personnel
            // we may put forward, whatever certificate they left behind.
            ->where('e.status', 'active')
            ->when($certificateType !== null, fn ($query) => $query->where('c.certificate_type', $certificateType))
            ->orderBy('e.name')
            ->orderBy('c.certificate_type')
            ->get([
                'c.id as certificate_id', 'c.certificate_type', 'c.name as certificate_name',
                'c.number', 'c.issuer', 'c.issued_date', 'c.expiry_date',
                'e.id as employee_id', 'e.code as employee_code', 'e.name as employee_name',
                'e.position', 'e.department',
            ]);

        foreach ($rows as $row) {
            $expiry = $row->expiry_date === null ? null : Carbon::parse($row->expiry_date)->startOfDay();
            $expired = $expiry !== null && $expiry->lt($date);

            $entry = [
                'certificate_id' => (int) $row->certificate_id,
                'employee_id' => (int) $row->employee_id,
                'employee_code' => $row->employee_code,
                'employee_name' => $row->employee_name,
                'position' => $row->position,
                'department' => $row->department,
                'certificate_type' => $row->certificate_type,
                // Kata yang tercetak pada kolom JENIS SERTIFIKAT F/SBD — cara
                // yang sama equipment() menulis ownership_label di bawah.
                'certificate_type_label' => self::certificateTypeLabel($row->certificate_type),
                'certificate_name' => $row->certificate_name,
                'number' => $row->number,
                'issuer' => $row->issuer,
                'issued_date' => $row->issued_date === null ? null : Carbon::parse($row->issued_date)->toDateString(),
                'expiry_date' => $expiry?->toDateString(),
                // Bertanda: negatif = sudah lewat, null = tidak kedaluwarsa.
                'days_to_expiry' => $expiry === null ? null : (int) $date->diffInDays($expiry, false),
                'expired' => $expired,
            ];

            $payload[$expired ? 'kedaluwarsa' : 'memenuhi'][] = $entry;
        }

        return $payload;
    }

    /**
     * Label jenis sertifikat sebagaimana F/SBD mencetaknya — cermin BY VALUE
     * dari HrPayroll\Enums\CertificateType::label(), karena arah panah yang
     * sama yang membuat query di atas raw juga melarang mengimpor enum-nya.
     * Dipaku case demi case oleh TenderQualificationTest supaya kedua sisi
     * tidak bisa saling menyimpang. Nilai yang tak dikenal pulang apa adanya:
     * mencetak mentah masih jujur, menggarisi sel yang ADA isinya tidak.
     */
    private static function certificateTypeLabel(?string $type): ?string
    {
        return match ($type) {
            'skk' => 'SKK Konstruksi',
            'k3' => 'Sertifikat K3/AK3',
            'principal' => 'Sertifikasi Principal',
            'lainnya' => 'Lainnya',
            default => $type,
        };
    }

    /**
     * Dukungan alat — MILIK SENDIRI DAN SEWA, dan setiap baris menyebut yang
     * mana.
     *
     * P5 membuat alat sewa menjadi baris ast_assets yang sah, dan sebuah alat
     * sewa BOLEH mendukung penawaran: itu fakta yang dapat diungkapkan, bukan
     * kelemahan yang harus disembunyikan. Yang tidak boleh adalah lembar
     * dukungan alat yang membuat alat sewa terbaca seperti milik sendiri —
     * karena itulah persis kolom yang diperiksa panitia. Maka setiap baris
     * membawa ownership dan, untuk yang sewa, nama lessor dan masa sewanya.
     *
     * DUA EMBER, CERMIN DARI ATURAN SERTIFIKAT LEWAT DI ATAS:
     *
     *   SEWA YANG SUDAH BERAKHIR BUKAN DUKUNGAN ALAT, DAN JUGA TIDAK DIBUANG
     *   DIAM-DIAM.
     *
     * Tidak ada apa pun di modul Aset yang memindahkan status sebuah alat sewa
     * ketika masa sewanya habis — statusnya tetap `available` selamanya — jadi
     * tanpa aturan ini F/DA mencetak excavator yang sudah dikembalikan ke
     * lessor dan menghitungnya di JUMLAH ALAT DIDAFTAR. `memenuhi` memuat alat
     * yang benar-benar bisa diajukan pada tanggal lembar; `kedaluwarsa` memuat
     * yang masa sewanya sudah lewat, dengan tanggal berakhirnya, supaya sewanya
     * sempat diperpanjang sebelum batas pemasukan — bukan supaya lubangnya
     * ditemukan setelah kalah lelang.
     *
     * Tanggalnya adalah tanggal LEMBAR, sama seperti personnel().
     *
     * @return array{as_of: string, memenuhi: array<int, array<string, mixed>>, kedaluwarsa: array<int, array<string, mixed>>}
     */
    public function equipment(?string $ownership = null, ?Carbon $asOf = null): array
    {
        // copy(): lihat personnel() — startOfDay() pada objek milik pemanggil
        // akan diam-diam menggeser tanggal yang dipegangnya.
        $date = ($asOf === null ? Carbon::now() : $asOf->copy())->startOfDay();

        $payload = ['as_of' => $date->toDateString(), 'memenuhi' => [], 'kedaluwarsa' => []];

        if (! Schema::hasTable('ast_assets')) {
            return $payload;
        }

        $hasVendors = Schema::hasTable('prc_vendors');

        $query = DB::table('ast_assets as a')
            ->whereNull('a.deleted_at')
            // 'disposed' by value: a machine we have sold cannot support a bid.
            ->where('a.status', '!=', 'disposed')
            ->when($ownership !== null, fn ($q) => $q->where('a.ownership', $ownership))
            ->orderBy('a.code');

        if ($hasVendors) {
            $query->leftJoin('prc_vendors as v', 'v.id', '=', 'a.vendor_id');
        }

        $columns = [
            'a.id', 'a.code', 'a.name', 'a.brand', 'a.model', 'a.serial_no',
            'a.ownership', 'a.status', 'a.rental_start', 'a.rental_end', 'a.vendor_id',
        ];

        if ($hasVendors) {
            $columns[] = 'v.name as lessor_name';
        }

        foreach ($query->get($columns) as $row) {
            // 'rented' by value — Assets\Enums\AssetOwnership::Rented. Pinned by
            // TenderQualificationTest so the literal cannot drift.
            $rented = $row->ownership === 'rented';

            // Hanya alat sewa yang punya masa sewa untuk lewat. Alat milik
            // sendiri dengan kolom rental_end nyasar tidak boleh terlempar ke
            // ember kedaluwarsa: yang dimiliki tidak berakhir. Sewa tanpa
            // tanggal berakhir juga tetap didaftar — cermin sertifikat tanpa
            // masa berlaku: yang tidak dicatat bukan yang sudah lewat.
            $end = $rented && $row->rental_end !== null
                ? Carbon::parse($row->rental_end)->startOfDay()
                : null;
            $expired = $end !== null && $end->lt($date);

            $entry = [
                'asset_id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'brand' => $row->brand,
                'model' => $row->model,
                'serial_no' => $row->serial_no,
                'ownership' => $row->ownership,
                'rented' => $rented,
                // The words that go on F/DA. An owned machine says so; a
                // rented one says so AND names its lessor.
                'ownership_label' => $rented ? 'Sewa' : 'Milik sendiri',
                'lessor_name' => $rented && $hasVendors ? ($row->lessor_name ?? null) : null,
                'rental_start' => $rented && $row->rental_start !== null
                    ? Carbon::parse($row->rental_start)->toDateString()
                    : null,
                'rental_end' => $end?->toDateString(),
                // Bertanda: negatif = sewa sudah lewat, null = tidak bermasa sewa.
                'days_to_rental_end' => $end === null ? null : (int) $date->diffInDays($end, false),
                'rental_expired' => $expired,
                'status' => $row->status,
            ];

            $payload[$expired ? 'kedaluwarsa' : 'memenuhi'][] = $entry;
        }

        return $payload;
    }

    /**
     * Daftar subkontraktor — vendor bertipe subcontractor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function subcontractors(): array
    {
        if (! Schema::hasTable('prc_vendors')) {
            return [];
        }

        return DB::table('prc_vendors')
            ->whereNull('deleted_at')
            // 'subcontractor' / 'active' by value — Procurement\Enums\VendorType.
            ->where('vendor_type', 'subcontractor')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'legal_name', 'classification', 'npwp', 'city', 'rating'])
            ->map(static fn (object $row): array => [
                'vendor_id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'legal_name' => $row->legal_name,
                'classification' => $row->classification,
                'npwp' => $row->npwp,
                'city' => $row->city,
                'rating' => $row->rating === null ? null : (float) $row->rating,
            ])->all();
    }
}
