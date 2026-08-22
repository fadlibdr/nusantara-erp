<?php

namespace Modules\Projects\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\NumberSequence;
use Modules\Projects\Enums\IncidentCategory;
use Modules\Projects\Enums\IncidentSeverity;
use Modules\Projects\Enums\IncidentStatus;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Enums\ProjectType;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\ManpowerAssignment;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Models\SafetyIncident;
use Modules\Projects\Services\BaselineService;
use Modules\Projects\Services\ProgressService;

class ProjectsDatabaseSeeder extends Seeder
{
    /**
     * Demo dataset:
     *  - PRJ-2026-001 (active) from CTR/2026/I/0001 — Gedung Kantor Graha Sentosa,
     *    WBS 3 parents + 8 leaves weighted by the BOQ/2026/0001 cost shares,
     *    8 kurva-S weeks (planned 62% vs actual 55% cumulative), 3 daily reports,
     *    2 manpower assignments and 2 termin-linked milestones.
     *  - PRJ-2026-002 (active) from CTR/2026/II/0002 — ELV & Data Center Bank Artha.
     */
    public function run(ProgressService $progressService, BaselineService $baselineService): void
    {
        $graha = $this->seedProjectGraha();
        $this->seedProjectBankArtha();

        $this->seedWbs($graha, $progressService);
        $this->seedWeeklyProgress($graha, $progressService);
        $this->seedDailyReports($graha);
        $this->seedSafetyIncidents($graha);
        $this->seedManpower($graha);
        $this->seedMilestones($graha);

        // Estimation and Inventory seed before Projects, so their prj_projects
        // lookups stored null; repair those links now that the projects exist.
        $this->backfillCrossModuleLinks();

        // AFTER the backfill, not next to seedWbs: RAP/2026/0001 only points at
        // this project once backfillCrossModuleLinks has run, and a baseline
        // taken before that finds no RAP and refuses itself.
        $this->seedBaseline($graha, $baselineService);

        // Seeded codes carry fixed sequence numbers; push the shared counters
        // past them so runtime-generated numbers never collide with the canon.
        $this->bumpSequence('PRJ', 2);
        $this->bumpSequence('DRP', 3);
        $this->bumpSequence('K3', 2);
    }

    /**
     * Revision 0 for PRJ-2026-001, so a FRESH install matches what the
     * 2026_08_01_000795 data migration produces on the live file.
     *
     * PRJ-2026-002 is left un-baselined on purpose: it has no RAP, and the
     * "susun RAP lebih dulu" empty state is part of the demo — it is the case
     * where EVM and PSAK 115 deliberately diverge.
     */
    private function seedBaseline(Project $project, BaselineService $baselineService): void
    {
        if (ProjectBaseline::query()->where('project_id', $project->id)->exists()) {
            return; // idempotent: never mint a revision 1 on a re-run
        }

        $direktur = User::query()->where('email', 'direktur@nusantara.test')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($direktur === null) {
            return; // Iam not seeded yet
        }

        try {
            $baseline = $baselineService->snapshot($project, [
                'effective_date' => '2026-02-02',
                'notes' => 'Baseline awal saat penandatanganan kontrak CTR/2026/I/0001.',
            ]);
        } catch (LogicException) {
            return; // no RAP or no WBS yet — skip gracefully (CONVENTIONS §8)
        }

        // Written approved directly: maker-checker needs two people and a
        // seeder is nobody. The runtime submit → approve path is covered by
        // tests/Feature/Projects/ProjectBaselineTest.
        $baseline->forceFill([
            'status' => DocumentStatus::Approved,
            'created_by' => (int) $direktur,
            'approved_by' => (int) $direktur,
            'approved_at' => now(),
        ])->save();

        $baseline->approvals()->create([
            'action' => 'approved',
            'user_id' => (int) $direktur,
            'note' => 'Baseline awal proyek — dibekukan bersamaan dengan penandatanganan kontrak.',
        ]);
    }

    /**
     * Back-fill project_id on canon rows owned by modules that seeded earlier
     * (single-pass db:seed correctness). Only null links are touched.
     */
    private function backfillCrossModuleLinks(): void
    {
        $links = [
            ['table' => 'est_boqs', 'code' => 'BOQ/2026/0001', 'project' => 'PRJ-2026-001'],
            ['table' => 'est_boqs', 'code' => 'BOQ/2026/0002', 'project' => 'PRJ-2026-002'],
            ['table' => 'est_cost_budgets', 'code' => 'RAP/2026/0001', 'project' => 'PRJ-2026-001'],
            ['table' => 'inv_warehouses', 'code' => 'WH-PRJ-2026-001', 'project' => 'PRJ-2026-001'],
            ['table' => 'inv_warehouses', 'code' => 'WH-PRJ-2026-002', 'project' => 'PRJ-2026-002'],
        ];

        foreach ($links as $link) {
            if (! Schema::hasTable($link['table'])) {
                continue;
            }

            $projectId = Project::query()->where('code', $link['project'])->value('id');

            if ($projectId === null) {
                continue;
            }

            DB::table($link['table'])
                ->where('code', $link['code'])
                ->whereNull('project_id')
                ->update(['project_id' => $projectId]);
        }
    }

    private function seedProjectGraha(): Project
    {
        return Project::withTrashed()->updateOrCreate(
            ['code' => 'PRJ-2026-001'],
            [
                'name' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
                'contract_id' => $this->lookupId('crm_contracts', 'CTR/2026/I/0001'),
                'customer_id' => $this->lookupId('crm_customers', 'CUST-0001'),
                'boq_id' => $this->lookupId('est_boqs', 'BOQ/2026/0001'),
                'type' => ProjectType::Construction,
                'location' => 'Jl. TB Simatupang Kav. 18',
                'city' => 'Jakarta Selatan',
                'province' => 'DKI Jakarta',
                'latitude' => -6.2903970,
                'longitude' => 106.7997560,
                // Contract dates / value (DPP) / retensi per CTR/2026/I/0001.
                'start_date' => '2026-02-02',
                'end_date' => '2027-07-31',
                'actual_start_date' => '2026-02-02',
                'actual_end_date' => null,
                'contract_value' => 48500000000,
                'retention_pct' => 5,
                'warranty_months' => 12,
                'status' => ProjectStatus::Active,
                'project_manager_id' => $this->lookupId('hr_employees', 'EMP-0002'), // Rina Wijaya
                'site_manager_id' => $this->lookupId('hr_employees', 'EMP-0003'),    // Agus Prasetyo
            ],
        );
    }

    private function seedProjectBankArtha(): Project
    {
        return Project::withTrashed()->updateOrCreate(
            ['code' => 'PRJ-2026-002'],
            [
                'name' => 'Instalasi ELV & Data Center Bank Artha Nusantara',
                'contract_id' => $this->lookupId('crm_contracts', 'CTR/2026/II/0002'),
                'customer_id' => $this->lookupId('crm_customers', 'CUST-0002'),
                'boq_id' => $this->lookupId('est_boqs', 'BOQ/2026/0002'),
                'type' => ProjectType::SystemIntegration,
                'location' => 'Menara Artha, Jl. Jend. Sudirman Kav. 34 + 12 kantor cabang',
                'city' => 'Jakarta Pusat',
                'province' => 'DKI Jakarta',
                'latitude' => -6.2146200,
                'longitude' => 106.8206600,
                // Contract dates / value (DPP) per CTR/2026/II/0002.
                'start_date' => '2026-03-02',
                'end_date' => '2026-12-18',
                'actual_start_date' => '2026-03-09',
                'actual_end_date' => null,
                'contract_value' => 9800000000,
                'retention_pct' => 5,
                'warranty_months' => 12,
                'status' => ProjectStatus::Active,
                'project_manager_id' => $this->lookupId('hr_employees', 'EMP-0002'), // Rina Wijaya
                'site_manager_id' => $this->lookupId('hr_employees', 'EMP-0007'),    // Joko Susilo (lead ELV)
            ],
        );
    }

    /**
     * WBS for PRJ-2026-001: 3 parents + 8 leaves.
     *
     * Leaf weights are the BOQ/2026/0001 item cost shares over the BOQ grand
     * total DPP of Rp 43.706.265.800 (round(amount / total * 100, 4)); the last
     * leaf absorbs the rounding residue so leaf weights sum to exactly 100.0000
     * — the same rule ProjectService::generateWbsFromBoq applies. Leaves A.1-B.4
     * map 1:1 to BOQ items (same wbs_code); the two C leaves consolidate BOQ
     * items C.1+C.2 and C.3+C.4+C.5 respectively, so they carry no boq_item_id.
     *
     * Leaf progress values put the weighted rollup at exactly 55.0000%, matching
     * the week-8 kurva-S actual (C.1's 4.0604 is the balancing figure).
     */
    private function seedWbs(Project $project, ProgressService $progressService): void
    {
        $structure = [
            [
                'wbs_code' => 'A',
                'name' => 'Pekerjaan Persiapan',
                'weight_pct' => 1.8304,
                'planned_start' => '2026-02-02',
                'planned_end' => '2026-04-30',
                'children' => [
                    // 350.000.000 / 43.706.265.800
                    ['wbs_code' => 'A.1', 'boq_wbs' => 'A.1', 'name' => 'Mobilisasi & demobilisasi peralatan', 'weight_pct' => 0.8008, 'planned_start' => '2026-02-02', 'planned_end' => '2026-03-31', 'progress_pct' => 100, 'actual_start' => '2026-02-02', 'actual_end' => '2026-02-20'],
                    // 450.000.000 / 43.706.265.800
                    ['wbs_code' => 'A.2', 'boq_wbs' => 'A.2', 'name' => 'Direksi keet, pagar sementara & fasilitas proyek', 'weight_pct' => 1.0296, 'planned_start' => '2026-02-02', 'planned_end' => '2026-03-15', 'progress_pct' => 100, 'actual_start' => '2026-02-02', 'actual_end' => '2026-02-27'],
                ],
            ],
            [
                'wbs_code' => 'B',
                'name' => 'Pekerjaan Struktur',
                'weight_pct' => 83.4996,
                'planned_start' => '2026-02-09',
                'planned_end' => '2026-11-30',
                'children' => [
                    // 1.143.450.000 / 43.706.265.800
                    ['wbs_code' => 'B.1', 'boq_wbs' => 'B.1', 'name' => 'Galian tanah basement & pondasi', 'weight_pct' => 2.6162, 'planned_start' => '2026-02-09', 'planned_end' => '2026-03-20', 'progress_pct' => 100, 'actual_start' => '2026-02-09', 'actual_end' => '2026-03-18'],
                    // 12.251.415.000 / 43.706.265.800
                    ['wbs_code' => 'B.2', 'boq_wbs' => 'B.2', 'name' => 'Beton ready mix K-300 kolom, balok & plat', 'weight_pct' => 28.0313, 'planned_start' => '2026-03-02', 'planned_end' => '2026-10-31', 'progress_pct' => 65, 'actual_start' => '2026-03-02', 'actual_end' => null],
                    // 16.169.656.800 / 43.706.265.800
                    ['wbs_code' => 'B.3', 'boq_wbs' => 'B.3', 'name' => 'Pembesian besi beton ulir', 'weight_pct' => 36.9962, 'planned_start' => '2026-02-23', 'planned_end' => '2026-10-15', 'progress_pct' => 60, 'actual_start' => '2026-02-23', 'actual_end' => null],
                    // 6.930.000.000 / 43.706.265.800
                    ['wbs_code' => 'B.4', 'boq_wbs' => 'B.4', 'name' => 'Bekisting kolom, balok & plat lantai', 'weight_pct' => 15.8559, 'planned_start' => '2026-03-02', 'planned_end' => '2026-11-15', 'progress_pct' => 60, 'actual_start' => '2026-03-02', 'actual_end' => null],
                ],
            ],
            [
                'wbs_code' => 'C',
                'name' => 'Pekerjaan Arsitektur & MEP',
                'weight_pct' => 14.6700,
                'planned_start' => '2026-03-16',
                'planned_end' => '2027-06-30',
                'children' => [
                    // BOQ C.1 + C.2 = 5.195.960.000 / 43.706.265.800
                    ['wbs_code' => 'C.1', 'boq_wbs' => null, 'name' => 'Pasangan bata, plesteran & finishing arsitektur', 'weight_pct' => 11.8884, 'planned_start' => '2026-03-16', 'planned_end' => '2027-03-31', 'progress_pct' => 4.0604, 'actual_start' => '2026-03-16', 'actual_end' => null],
                    // BOQ C.3 + C.4 + C.5 = 1.215.784.000 (residue leaf: 100 - sum of the others)
                    ['wbs_code' => 'C.2', 'boq_wbs' => null, 'name' => 'MEP, ELV & ICT (CCTV, LAN, plumbing, HVAC, arus kuat)', 'weight_pct' => 2.7816, 'planned_start' => '2026-03-23', 'planned_end' => '2027-06-30', 'progress_pct' => 5, 'actual_start' => '2026-03-23', 'actual_end' => null],
                ],
            ],
        ];

        // Replace wholesale — children first (self-referencing FK).
        $project->wbsTasks()->whereNotNull('parent_id')->delete();
        $project->wbsTasks()->delete();

        foreach ($structure as $parentIndex => $parentData) {
            $parent = $project->wbsTasks()->create([
                'parent_id' => null,
                'boq_item_id' => null,
                'wbs_code' => $parentData['wbs_code'],
                'name' => $parentData['name'],
                'weight_pct' => $parentData['weight_pct'],
                'planned_start' => $parentData['planned_start'],
                'planned_end' => $parentData['planned_end'],
                'progress_pct' => 0, // rolled up from children below
                'sort_order' => $parentIndex + 1,
            ]);

            foreach ($parentData['children'] as $childIndex => $child) {
                $project->wbsTasks()->create([
                    'parent_id' => $parent->id,
                    'boq_item_id' => $child['boq_wbs'] !== null
                        ? $this->lookupBoqItemId('BOQ/2026/0001', $child['boq_wbs'])
                        : null,
                    'wbs_code' => $child['wbs_code'],
                    'name' => $child['name'],
                    'weight_pct' => $child['weight_pct'],
                    'planned_start' => $child['planned_start'],
                    'planned_end' => $child['planned_end'],
                    'actual_start' => $child['actual_start'],
                    'actual_end' => $child['actual_end'],
                    'progress_pct' => $child['progress_pct'],
                    'sort_order' => $childIndex + 1,
                ]);
            }
        }

        // Real math: parents and the project header derive from the leaves.
        $progressService->recalcWbsRollups($project);
    }

    /**
     * Eight kurva-S weeks — planned S-curve vs a lagging actual (deviation -7%
     * by week 8). recordWeekly() derives deviation and keeps the project header
     * planned percentage on the latest week.
     */
    private function seedWeeklyProgress(Project $project, ProgressService $progressService): void
    {
        $weeks = [
            [1, '2026-02-02', '2026-02-08', 2, 1.5, 'Mobilisasi & pekerjaan persiapan dimulai.'],
            [2, '2026-02-09', '2026-02-15', 6, 5, null],
            [3, '2026-02-16', '2026-02-22', 12, 10, null],
            [4, '2026-02-23', '2026-03-01', 20, 17, 'Hujan 3 hari; galian basement sempat terhenti.'],
            [5, '2026-03-02', '2026-03-08', 30, 26, null],
            [6, '2026-03-09', '2026-03-15', 41, 36, null],
            [7, '2026-03-16', '2026-03-22', 52, 47, 'Percepatan: penambahan 1 grup bekisting.'],
            [8, '2026-03-23', '2026-03-29', 62, 55, 'Deviasi -7%; rapat percepatan dengan MK dijadwalkan.'],
        ];

        foreach ($weeks as [$weekNo, $start, $end, $planned, $actual, $notes]) {
            $progressService->recordWeekly([
                'project_id' => $project->id,
                'week_no' => $weekNo,
                'period_start' => $start,
                'period_end' => $end,
                'planned_pct' => $planned,
                'actual_pct' => $actual,
                'notes' => $notes,
            ]);
        }
    }

    private function seedDailyReports(Project $project): void
    {
        $createdBy = User::query()->orderBy('id')->value('id');

        $reports = [
            [
                'code' => 'DRP/2026/03/0001',
                'report_date' => '2026-03-25',
                'weather_am' => 'cerah',
                'weather_pm' => 'mendung',
                'manpower_count' => 148,
                'activities' => 'Pengecoran plat & balok lantai 5 zona A (86 m3); pembesian kolom lantai 6 zona B; pemasangan bekisting core wall.',
                'obstacles' => null,
                'safety_notes' => 'Toolbox meeting pagi; APD lengkap; nihil insiden.',
                'photos' => ['projects/prj-2026-001/2026-03-25/cor-lantai5-zona-a.jpg'],
                'materials' => [
                    ['item' => 'ITM-0007', 'qty_used' => 86, 'unit' => 'm3'],   // Ready Mix K-300
                    ['item' => 'ITM-0002', 'qty_used' => 420, 'unit' => 'btg'], // Besi Beton D16
                ],
            ],
            [
                'code' => 'DRP/2026/03/0002',
                'report_date' => '2026-03-26',
                'weather_am' => 'cerah',
                'weather_pm' => 'cerah',
                'manpower_count' => 152,
                'activities' => 'Lanjutan pengecoran plat lantai 5 zona B (92 m3); pasangan dinding bata lantai 2; instalasi sparing MEP plat lantai 6.',
                'obstacles' => 'Antrian truk mixer 1,5 jam akibat pembatasan lalu lintas.',
                'safety_notes' => 'Inspeksi scaffolding lantai 5 oleh HSE; hasil aman.',
                'photos' => ['projects/prj-2026-001/2026-03-26/cor-lantai5-zona-b.jpg'],
                'materials' => [
                    ['item' => 'ITM-0007', 'qty_used' => 92, 'unit' => 'm3'],  // Ready Mix K-300
                    ['item' => 'ITM-0001', 'qty_used' => 150, 'unit' => 'zak'], // Semen Portland 50kg
                ],
            ],
            [
                'code' => 'DRP/2026/03/0003',
                'report_date' => '2026-03-27',
                'weather_am' => 'mendung',
                'weather_pm' => 'hujan',
                'manpower_count' => 137,
                'activities' => 'Pembesian balok lantai 6 zona A; erection scaffolding lantai 6; marking pasangan bata lantai 3.',
                'obstacles' => 'Hujan deras pukul 14.00-16.00, pekerjaan area terbuka dihentikan sementara.',
                'safety_notes' => 'Satu near-miss material jatuh dari lantai 5; briefing ulang housekeeping & jaring pengaman.',
                'photos' => null,
                'materials' => [
                    ['item' => 'ITM-0002', 'qty_used' => 380, 'unit' => 'btg'], // Besi Beton D16
                    ['item' => 'ITM-0005', 'qty_used' => 24, 'unit' => 'm3'],   // Pasir Beton
                ],
            ],
        ];

        foreach ($reports as $data) {
            $report = DailyReport::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'project_id' => $project->id,
                    'report_date' => $data['report_date'],
                    'weather_am' => $data['weather_am'],
                    'weather_pm' => $data['weather_pm'],
                    'manpower_count' => $data['manpower_count'],
                    'activities' => $data['activities'],
                    'obstacles' => $data['obstacles'],
                    'safety_notes' => $data['safety_notes'],
                    'photos' => $data['photos'],
                    'created_by' => $createdBy,
                ],
            );

            $report->materials()->delete();

            foreach ($data['materials'] as $line) {
                $itemId = $this->lookupId('inv_items', $line['item']);

                if ($itemId === null) {
                    continue; // Inventory not seeded yet — skip the line gracefully.
                }

                $report->materials()->create([
                    'item_id' => $itemId,
                    'qty_used' => $line['qty_used'],
                    'unit' => $line['unit'],
                ]);
            }
        }
    }

    /**
     * The two events that were only ever prose.
     *
     * DRP/2026/03/0003 recorded "Satu near-miss material jatuh dari lantai 5" in
     * safety_notes — an event PP 50/2012 requires to be investigated and followed
     * up, with no severity, no cause, nobody accountable and no closing date. It
     * is the register's first row, alongside one closed lost-time case so the
     * monthly K3 report has a frequency rate to show.
     */
    private function seedSafetyIncidents(Project $project): void
    {
        $reporter = User::query()->where('email', 'admin@nusantarakarya.co.id')->value('id');
        $responsible = DB::table('hr_employees')->where('code', 'EMP-0003')->value('id');

        $incidents = [
            [
                'code' => 'K3/2026/III/001',
                'occurred_at' => '2026-03-27 14:35:00',
                'location' => 'Lantai 5 zona B, area pembesian',
                'severity' => IncidentSeverity::NearMiss,
                'category' => IncidentCategory::StruckByObject,
                'description' => 'Sisa besi beton jatuh dari lantai 5 ke area lantai 3 yang sedang kosong. '
                    .'Tidak ada pekerja di bawahnya; tidak ada korban dan tidak ada kerusakan.',
                'people_involved' => 0,
                'lost_days' => 0,
                'immediate_action' => 'Area lantai 3 di bawah zona kerja ditutup dan diberi barikade; '
                    .'pekerjaan lantai 5 dihentikan 30 menit untuk toolbox meeting.',
                'root_cause' => 'Jaring pengaman (safety net) lantai 5 belum terpasang di sisi timur '
                    .'dan housekeeping sisa potongan besi belum dilakukan sejak pagi.',
                'corrective_action' => 'Pemasangan jaring pengaman seluruh keliling lantai 5 dan 6; '
                    .'housekeeping wajib sebelum istirahat siang; briefing ulang housekeeping ke seluruh mandor.',
                'responsible_employee_id' => $responsible,
                'due_date' => '2026-04-03',
                'status' => IncidentStatus::Closed,
                'closed_at' => '2026-04-02',
                'is_reportable' => false,
            ],
            [
                'code' => 'K3/2026/IV/002',
                'occurred_at' => '2026-04-15 10:20:00',
                'location' => 'Lantai 2, area pasangan bata',
                'severity' => IncidentSeverity::LostTime,
                'category' => IncidentCategory::CaughtBetween,
                'description' => 'Tangan kiri seorang tukang bata terjepit antara palet material dan dinding '
                    .'saat penurunan material dari lift barang. Luka memar, dirawat di klinik dan '
                    .'diistirahatkan tiga hari kerja.',
                'people_involved' => 1,
                'lost_days' => 3,
                'immediate_action' => 'P3K di lokasi, dirujuk ke klinik terdekat, dilaporkan ke BPJS Ketenagakerjaan.',
                'root_cause' => null,
                'corrective_action' => null,
                'responsible_employee_id' => null,
                'due_date' => '2026-04-30',
                'status' => IncidentStatus::Investigating,
                'closed_at' => null,
                'is_reportable' => true,
            ],
        ];

        foreach ($incidents as $data) {
            SafetyIncident::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                $data + ['project_id' => $project->id, 'created_by' => $reporter],
            );
        }
    }

    private function seedManpower(Project $project): void
    {
        $assignments = [
            ['employee' => 'EMP-0003', 'role' => 'Site Manager', 'from' => '2026-02-02'],   // Agus Prasetyo
            ['employee' => 'EMP-0007', 'role' => 'Teknisi ELV', 'from' => '2026-03-16'],    // Joko Susilo
        ];

        foreach ($assignments as $data) {
            $employeeId = $this->lookupId('hr_employees', $data['employee']);

            if ($employeeId === null) {
                continue; // HR module not seeded yet.
            }

            ManpowerAssignment::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_id' => $employeeId],
                [
                    'role_on_project' => $data['role'],
                    'assigned_from' => $data['from'],
                    'assigned_until' => null,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedMilestones(Project $project): void
    {
        $milestones = [
            [
                'name' => 'Progres fisik 50% — syarat penagihan Termin 2',
                'due_date' => '2026-04-15',
                'achieved_date' => '2026-03-27',
                'termin_no' => 2,
                'notes' => 'Tercapai minggu ke-8 (aktual kumulatif 55%); BAP progres diajukan ke MK.',
            ],
            [
                'name' => 'Progres fisik 80% — syarat penagihan Termin 3',
                'due_date' => '2026-10-31',
                'achieved_date' => null,
                'termin_no' => 3,
                'notes' => 'Prasyarat: struktur atas selesai dan arsitektur berjalan.',
            ],
        ];

        foreach ($milestones as $data) {
            Milestone::query()->updateOrCreate(
                ['project_id' => $project->id, 'name' => $data['name']],
                [
                    'due_date' => $data['due_date'],
                    'achieved_date' => $data['achieved_date'],
                    'termin_id' => $this->lookupTerminId('CTR/2026/I/0001', $data['termin_no']),
                    'notes' => $data['notes'],
                ],
            );
        }
    }

    /**
     * Resolve a cross-module row id by its canonical seed code;
     * returns null (graceful skip) when the other module isn't migrated/seeded.
     */
    private function lookupId(string $table, string $code): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $id = DB::table($table)->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Resolve an Estimation BOQ item by BOQ code + wbs_code (est_boq_items
     * has no own code column); null when Estimation isn't migrated/seeded.
     */
    private function lookupBoqItemId(string $boqCode, string $wbsCode): ?int
    {
        if (! Schema::hasTable('est_boqs') || ! Schema::hasTable('est_boq_items')) {
            return null;
        }

        $boqId = DB::table('est_boqs')->where('code', $boqCode)->value('id');

        if ($boqId === null) {
            return null;
        }

        $id = DB::table('est_boq_items')
            ->where('boq_id', $boqId)
            ->where('wbs_code', $wbsCode)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Resolve a CRM billing termin by contract code + termin_no;
     * null when the CRM module isn't migrated/seeded.
     */
    private function lookupTerminId(string $contractCode, int $terminNo): ?int
    {
        if (! Schema::hasTable('crm_contracts') || ! Schema::hasTable('crm_contract_termins')) {
            return null;
        }

        $contractId = DB::table('crm_contracts')->where('code', $contractCode)->value('id');

        if ($contractId === null) {
            return null;
        }

        $id = DB::table('crm_contract_termins')
            ->where('contract_id', $contractId)
            ->where('termin_no', $terminNo)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Seeded documents carry fixed numbers (e.g. PRJ-2026-001); push the
     * shared sequence past them so runtime-generated numbers never collide.
     */
    private function bumpSequence(string $type, int $to): void
    {
        $sequence = NumberSequence::query()->firstOrCreate(
            ['type' => $type, 'year' => 2026],
            ['last_number' => 0],
        );

        if ((int) $sequence->last_number < $to) {
            $sequence->last_number = $to;
            $sequence->save();
        }
    }
}
