<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Crm\Enums\ChangeOrderType;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\WorkPermit;
use Modules\Projects\Services\DailyReportService;
use Modules\Projects\Services\MeasurementService;
use Modules\Projects\Services\WorkPermitService;

/**
 * Dokumen yang boleh dimintakan keputusan MK/Owner lewat tautan sekali-pakai
 * atau lembar fisik (keputusan pemilik #1), dialamatkan dengan SLUG — kosakata
 * yang sama dengan AttachableDocuments, dan alasan yang sama: string kelas
 * tidak pernah menyeberangi kawat.
 *
 * DUA MODE, karena keputusan pemilik #6 mempertahankan proksi internal untuk
 * 18 dokumen Approvable:
 *
 *  - MODE_RECORD (bawaan): keputusan eksternal DICATAT sebagai bukti; siapa
 *    yang menggerakkan status dokumen tetap si proksi internal. Baris boleh
 *    membawa 'hook' — service modul yang dipanggil begitu keputusan tercatat
 *    (laporan harian: kunci locked_at pada keputusan pertama).
 *  - MODE_TRANSITION ('transisi'): keputusan eksternal MENGGERAKKAN dokumen,
 *    lewat ADAPTER SERVICE di modul pemiliknya ('hook' yang menerima dokumen
 *    dan baris keputusannya) — bukan trait, sesuai catatan 🧪 P0-C. Adapter
 *    itulah yang memegang aturan Approvable dan maker-checker.
 *
 * Core menunjuk kelas modul HANYA dari berkas registri ini — preseden
 * ApprovableDocuments: satu tabel eksplisit, bukan konvensi namespace, karena
 * prefix izin yang salah adalah notifikasi yang terkirim ke kosong.
 */
class ExternalApprovableDocuments
{
    public const MODE_RECORD = 'record';

    public const MODE_TRANSITION = 'transisi';

    /**
     * issuable_statuses: status dokumen yang mengizinkan PENERBITAN tautan
     * (dan hanya penerbitan — pencatatan lembar fisik tidak dibatasi status,
     * lihat ExternalApprovalService::recordPhysical). CCO: hanya submitted
     * (keputusan pemilik #7, pertahankan). Izin kerja: hanya submitted,
     * KEPUTUSAN DI SINI karena mode transisi — tautan atas draf akan
     * menghasilkan keputusan yang tidak bisa diterapkan Approvable, dan
     * tautan yang pasti gagal bukan tautan yang jujur. Laporan harian tidak
     * punya status, maka null.
     *
     * @var array<string, array{class: class-string, prefix: string, label: string,
     *      mode: string, hook: array{class-string, string}|null,
     *      issuable_statuses: list<string>|null}>
     */
    private const MAP = [
        'projects/daily-reports' => [
            'class' => DailyReport::class,
            'prefix' => 'prj',
            'label' => 'Laporan harian',
            'mode' => self::MODE_RECORD,
            'hook' => [DailyReportService::class, 'lockFromExternalDecision'],
            'issuable_statuses' => null,
        ],
        'crm/contract-change-orders' => [
            'class' => ContractChangeOrder::class,
            'prefix' => 'crm',
            'label' => 'Pekerjaan tambah-kurang',
            'mode' => self::MODE_RECORD,
            'hook' => null,
            'issuable_statuses' => ['submitted'],
        ],
        'projects/work-permits' => [
            'class' => WorkPermit::class,
            'prefix' => 'prj',
            'label' => 'Izin kerja lapangan',
            'mode' => self::MODE_TRANSITION,
            'hook' => [WorkPermitService::class, 'applyExternalDecision'],
            'issuable_statuses' => ['submitted'],
        ],
        /*
         * P3 — OPNAME KE PEMILIK. Mode TRANSISI, because the signature this
         * sheet exists to collect is the MK's: an opname the contractor
         * approves alone is a claim, and only the MK's mark turns it into a
         * measurement the owner can be billed for.
         *
         * The adapter is MeasurementService::applyExternalDecision — a service
         * method and not the trait, roadmap §7 — so an external approval
         * re-checks the ceiling against live rows and re-derives the weekly
         * curve exactly as an internal approval does. And per §7 again: this
         * records who the MK's representative WAS in core_external_approvals;
         * nothing here writes an owner/MK name onto the document from project
         * master data.
         *
         * Only `submitted` may be linked, the same decision the izin kerja
         * carries: a link over a draft would produce a decision Approvable
         * cannot apply, and a link that is certain to fail is not an honest one.
         */
        'projects/progress-measurements' => [
            'class' => ProgressMeasurement::class,
            'prefix' => 'prj',
            'label' => 'Opname progres owner',
            'mode' => self::MODE_TRANSITION,
            'hook' => [MeasurementService::class, 'applyExternalDecision'],
            'issuable_statuses' => ['submitted'],
        ],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::MAP);
    }

    public static function knows(string $slug): bool
    {
        return isset(self::MAP[$slug]);
    }

    /** @return class-string|null */
    public static function classFor(string $slug): ?string
    {
        return self::MAP[$slug]['class'] ?? null;
    }

    public static function prefixFor(string $slug): ?string
    {
        return self::MAP[$slug]['prefix'] ?? null;
    }

    public static function labelFor(string $slug): string
    {
        return self::MAP[$slug]['label'] ?? 'Dokumen';
    }

    public static function modeFor(string $slug): string
    {
        return self::MAP[$slug]['mode'] ?? self::MODE_RECORD;
    }

    /** @return array{class-string, string}|null [service class, method] */
    public static function hookFor(string $slug): ?array
    {
        return self::MAP[$slug]['hook'] ?? null;
    }

    /** @return list<string>|null null = penerbitan tidak dibatasi status */
    public static function issuableStatusesFor(string $slug): ?array
    {
        return self::MAP[$slug]['issuable_statuses'] ?? null;
    }

    public static function slugFor(object|string $document): ?string
    {
        $class = is_string($document) ? $document : $document::class;

        foreach (self::MAP as $slug => $entry) {
            if ($entry['class'] === $class) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Rute detail SPA, bentuk "#/d/…" yang sama dengan ApprovableDocuments —
     * slug registri ini memang kosakata resource SPA.
     */
    public static function linkFor(string $slug, int $id): string
    {
        return "#/d/{$slug}/{$id}";
    }

    /**
     * Ringkasan dokumen untuk halaman keputusan publik: label → nilai, angka
     * kunci milik modulnya. Di berkas registri INI dan bukan di service, agar
     * ExternalApprovalService tetap buta terhadap modul — satu-satunya tempat
     * Core boleh mengenal kelas modul adalah tabel di atas.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function summarize(string $slug, Model $document): array
    {
        return match ($slug) {
            'projects/daily-reports' => self::summarizeDailyReport($document),
            'crm/contract-change-orders' => self::summarizeChangeOrder($document),
            'projects/work-permits' => self::summarizeWorkPermit($document),
            'projects/progress-measurements' => self::summarizeMeasurement($document),
            default => [],
        };
    }

    /**
     * What the MK sees on the public decision page before signing an opname.
     *
     * The LINE COUNT and the period value, not the lines themselves: the
     * backsheet with every measured quantity is F/OPN, which the MK already
     * has on paper, and reproducing it inside a one-time link would put a
     * hundred rows behind a URL that needs no login.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function summarizeMeasurement(ProgressMeasurement $measurement): array
    {
        $project = $measurement->project;
        $lines = $measurement->items()->count();

        return array_values(array_filter([
            ['label' => 'Proyek', 'value' => trim(($project?->code ?? '—').' — '.($project?->name ?? ''))],
            ['label' => 'Periode', 'value' => sprintf(
                '%s s/d %s',
                $measurement->period_start?->format('d-m-Y') ?? '—',
                $measurement->period_end?->format('d-m-Y') ?? '—',
            )],
            ['label' => 'Jumlah item diukur', 'value' => $lines.' item'],
            ['label' => 'Nilai pekerjaan periode ini', 'value' => Money::format((float) $measurement->period_amount)],
            ['label' => 'Nilai kumulatif terukur', 'value' => Money::format((float) $measurement->cumulative_amount)],
            $measurement->notes ? ['label' => 'Catatan', 'value' => mb_substr((string) $measurement->notes, 0, 200)] : null,
        ]));
    }

    /** @return list<array{label: string, value: string}> */
    private static function summarizeDailyReport(DailyReport $report): array
    {
        $project = $report->project;

        return array_values(array_filter([
            ['label' => 'Proyek', 'value' => trim(($project?->code ?? '—').' — '.($project?->name ?? ''))],
            ['label' => 'Tanggal laporan', 'value' => $report->report_date?->format('d-m-Y') ?? '—'],
            ['label' => 'Jumlah tenaga kerja', 'value' => (string) ($report->manpower_count ?? 0).' orang'],
            $report->activities ? ['label' => 'Kegiatan', 'value' => mb_substr((string) $report->activities, 0, 200)] : null,
        ]));
    }

    /** @return list<array{label: string, value: string}> */
    private static function summarizeChangeOrder(ContractChangeOrder $order): array
    {
        $contract = $order->contract;

        return array_values(array_filter([
            ['label' => 'Kontrak', 'value' => trim(($contract?->code ?? '—').' — '.($contract?->title ?? ''))],
            ['label' => 'Jenis perubahan', 'value' => $order->change_type?->label() ?? '—'],
            $order->change_type === ChangeOrderType::Waktu
                ? ['label' => 'Perubahan waktu', 'value' => sprintf('%+d hari', (int) $order->days_change)]
                : ['label' => 'Nilai perubahan', 'value' => Money::format((float) $order->value_change)],
            ['label' => 'Tanggal', 'value' => $order->change_date?->format('d-m-Y') ?? '—'],
            $order->title ? ['label' => 'Uraian', 'value' => mb_substr((string) $order->title, 0, 200)] : null,
        ]));
    }

    /** @return list<array{label: string, value: string}> */
    private static function summarizeWorkPermit(WorkPermit $permit): array
    {
        $project = $permit->project;

        return array_values(array_filter([
            ['label' => 'Proyek', 'value' => trim(($project?->code ?? '—').' — '.($project?->name ?? ''))],
            ['label' => 'Tanggal izin', 'value' => $permit->permit_date?->format('d-m-Y') ?? '—'],
            ['label' => 'Shift', 'value' => ucfirst((string) ($permit->shift?->value ?? '—'))],
            ['label' => 'Uraian pekerjaan', 'value' => mb_substr((string) $permit->work_description, 0, 200)],
            ['label' => 'Berlaku', 'value' => sprintf(
                '%s s/d %s',
                $permit->valid_from?->format('d-m-Y H:i') ?? '—',
                $permit->valid_until?->format('d-m-Y H:i') ?? '—',
            )],
            $permit->hazard_notes ? ['label' => 'Catatan bahaya', 'value' => mb_substr((string) $permit->hazard_notes, 0, 200)] : null,
        ]));
    }
}
