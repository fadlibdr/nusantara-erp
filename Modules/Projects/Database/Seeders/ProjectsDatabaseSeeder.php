<?php

namespace Modules\Projects\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Location;
use Modules\Core\Models\NumberSequence;
use Modules\Crm\Models\RkkDocument;
use Modules\Crm\Services\RkkService;
use Modules\Projects\Enums\CertifyingParty;
use Modules\Projects\Enums\IncidentCategory;
use Modules\Projects\Enums\IncidentSeverity;
use Modules\Projects\Enums\IncidentStatus;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Enums\ProjectType;
use Modules\Projects\Enums\ZoneCertificateStatus;
use Modules\Projects\Models\ContractVariation;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\HseDaily;
use Modules\Projects\Models\ManpowerAssignment;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Models\RiskRegisterEntry;
use Modules\Projects\Models\SafetyIncident;
use Modules\Projects\Models\ZoneCertificate;
use Modules\Projects\Services\BaselineService;
use Modules\Projects\Services\MeasurementService;
use Modules\Projects\Services\ProgressService;
use Modules\Projects\Services\ZoneCertificateService;

class ProjectsDatabaseSeeder extends Seeder
{
    /**
     * What OPN 1 measures, per BOQ/2026/0001 item: the SAME physical percentage
     * the matching WBS leaf carries in seedWbs() below, and nothing invented.
     *
     * The C leaves consolidate several BOQ items each (C.1 covers BOQ C.1+C.2,
     * C.2 covers BOQ C.3+C.4+C.5 — see seedWbs), so those items repeat their
     * leaf's percentage. Because the leaf weights ARE the BOQ cost shares, the
     * value-weighted total comes to 55,0001 % — the kurva-S's own week-8
     * actual, which is the whole reason approving this opname leaves the demo's
     * narrative standing instead of contradicting it.
     */
    private const MEASURED_PCT = [
        'A.1' => 100,     // WBS A.1 mobilisasi — selesai
        'A.2' => 100,     // WBS A.2 direksi keet — selesai
        'B.1' => 100,     // WBS B.1 galian — selesai
        'B.2' => 65,      // WBS B.2 beton ready mix
        'B.3' => 60,      // WBS B.3 pembesian
        'B.4' => 60,      // WBS B.4 bekisting
        'C.1' => 4.0604,  // WBS C.1 (BOQ C.1 + C.2)
        'C.2' => 4.0604,
        'C.3' => 5,       // WBS C.2 (BOQ C.3 + C.4 + C.5)
        'C.4' => 5,
        'C.5' => 5,
    ];

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
        // P6 — after the daily reports: the FM-10-13 link resolves by
        // (project, date) against DRP/2026/03/0001, which must exist first.
        $this->seedHseDaily($graha);
        $this->seedRiskRegister($graha);
        // P7 — segera setelah registernya ada: Crm diseed SEBELUM modul ini,
        // jadi pada seed segar seedRkk-nya menemukan register kosong dan RKK
        // demo tinggal tanpa tautan IBPRP; kembaran di sini yang melengkapinya.
        $this->completeRkkIbprpLinks();
        $this->seedManpower($graha);
        $this->seedMilestones($graha);

        // Estimation and Inventory seed before Projects, so their prj_projects
        // lookups stored null; repair those links now that the projects exist.
        $this->backfillCrossModuleLinks();

        // AFTER the backfill, not next to seedWbs: RAP/2026/0001 only points at
        // this project once backfillCrossModuleLinks has run, and a baseline
        // taken before that finds no RAP and refuses itself.
        $this->seedBaseline($graha, $baselineService);

        // P3 — after the backfill for the same reason the baseline is: the
        // opname measures BOQ/2026/0001, and MeasurementService resolves that
        // BOQ through the contract/project link the backfill repairs. The order
        // inside the block is load-bearing: the variation register raises the
        // ceiling the second opname measures against, and the zones are what
        // its lines and the BAPP sheets point at.
        $this->seedContractVariations($graha);
        $zones = $this->seedZones($graha);
        $this->seedZoneCertificates($graha, $zones);
        $this->seedProgressMeasurement($graha, $progressService, $zones);

        // Seeded codes carry fixed sequence numbers; push the shared counters
        // past them so runtime-generated numbers never collide with the canon.
        $this->bumpSequence('PRJ', 2);
        $this->bumpSequence('DRP', 3);
        $this->bumpSequence('K3', 2);
        $this->bumpSequence('HSE', 1);
    }

    /**
     * P6 — satu FM-10-13 pada hari laporan harian pertama. Tautannya di-seed
     * dengan resolusi yang sama dengan HseDailyService: dicari dari (proyek,
     * tanggal), bukan angka yang dikarang.
     */
    private function seedHseDaily(Project $project): void
    {
        $createdBy = User::query()->orderBy('id')->value('id');

        $reportId = DailyReport::query()
            ->where('project_id', $project->id)
            ->whereDate('report_date', '2026-03-25')
            ->value('id');

        /** @var HseDaily $daily */
        $daily = HseDaily::withTrashed()->updateOrCreate(
            ['code' => 'HSE/2026/03/0001'],
            [
                'project_id' => $project->id,
                'report_date' => '2026-03-25',
                'daily_report_id' => $reportId,
                'toolbox_topic' => 'Bekerja di ketinggian: pengecoran lantai 5',
                'toolbox_attendees' => ['Agus Prasetyo', 'Joko Susilo', 'Harjo Wibowo'],
                'notes' => 'Seluruh pekerja zona A hadir toolbox meeting pagi.',
                'created_by' => $createdBy,
            ],
        );

        $daily->apd()->delete();

        foreach ([
            ['category' => 'helm', 'qty' => 148],
            ['category' => 'rompi', 'qty' => 148],
            ['category' => 'sepatu safety', 'qty' => 148],
            ['category' => 'harness', 'qty' => 22],
        ] as $line) {
            $daily->apd()->create($line);
        }

        $daily->findings()->delete();
        $daily->findings()->create([
            'sort_order' => 1,
            'finding' => 'Toe board scaffolding sisi timur lantai 5 belum terpasang',
            'follow_up' => 'Dipasang sebelum pengecoran zona B (26/3)',
        ]);
    }

    /** P6 — tiga baris IBPRP; skor = L×S, aritmetika yang sama dengan service. */
    private function seedRiskRegister(Project $project): void
    {
        $createdBy = User::query()->orderBy('id')->value('id');

        $rows = [
            [
                'activity' => 'Pengecoran plat & balok lantai atas',
                'hazard' => 'Bekerja di ketinggian tanpa proteksi tepi',
                'impact' => 'Pekerja terjatuh — cedera berat/fatal',
                'likelihood' => 3, 'severity' => 5,
                'controls' => 'Railing tepi & jaring pengaman, harness terkait di atas 1,8 m, toolbox meeting harian',
                'residual_likelihood' => 1, 'residual_severity' => 5,
            ],
            [
                'activity' => 'Pengangkatan material dengan tower crane',
                'hazard' => 'Muatan lepas / rigging gagal di atas area kerja',
                'impact' => 'Tertimpa material — cedera berat/fatal',
                'likelihood' => 2, 'severity' => 5,
                'controls' => 'Inspeksi rigging harian, zona eksklusi di bawah lintasan, signalman bersertifikat',
                'residual_likelihood' => 1, 'residual_severity' => 5,
            ],
            [
                'activity' => 'Pekerjaan pembesian',
                'hazard' => 'Ujung besi terekspos pada stek kolom',
                'impact' => 'Tertusuk / tergores — P3K s.d. perawatan medis',
                'likelihood' => 3, 'severity' => 2,
                'controls' => 'Pelindung ujung stek (rebar cap), sarung tangan wajib',
                // Risiko sisa sengaja belum dinilai pada baris ini: demo untuk
                // sel BERGARIS di F/IBPRP (bukan 0).
                'residual_likelihood' => null, 'residual_severity' => null,
            ],
        ];

        foreach ($rows as $order => $row) {
            RiskRegisterEntry::withTrashed()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'activity' => $row['activity'],
                    'hazard' => $row['hazard'],
                ],
                [
                    'sort_order' => $order + 1,
                    'impact' => $row['impact'],
                    'likelihood' => $row['likelihood'],
                    'severity' => $row['severity'],
                    'initial_score' => $row['likelihood'] * $row['severity'],
                    'controls' => $row['controls'],
                    'residual_likelihood' => $row['residual_likelihood'],
                    'residual_severity' => $row['residual_severity'],
                    'residual_score' => $row['residual_likelihood'] !== null
                        ? $row['residual_likelihood'] * $row['residual_severity']
                        : null,
                    'created_by' => $createdBy,
                ],
            );
        }
    }

    /**
     * P7 — KEMBARAN dari blok IBPRP pada CrmDatabaseSeeder::seedRkk (pola
     * AST-0007: Procurement & Assets meng-updateOrCreate baris yang sama,
     * CONVENTIONS §8). Crm diseed pada posisi 3, modul ini pada posisi 7 —
     * jadi pada `migrate:fresh --seed` yang sebenarnya, seedRkk bertanya ke
     * prj_risk_register yang MASIH KOSONG dan RKK demo tinggal dengan
     * project_id NULL dan nol tautan; sisi yang jalan BELAKANGAN inilah yang
     * melengkapinya, begitu barisan registernya ada.
     *
     * PEMILIHANNYA HURUF DEMI HURUF SAMA dengan sisi Crm (register hidup
     * ber-project_id terkecil, lima baris pertama menurut sort_order), dan
     * penulisannya lewat RkkService::syncIbprpLinks yang sama — ganti utuh,
     * jadi jalan dua kali mendarat di keadaan yang sama, bukan menggandakan.
     * SIAPA PUN YANG MENGUBAH PEMILIHANNYA WAJIB MENGUBAH KEDUA SEEDER;
     * RkkSeederLinkageTest yang berbunyi bila salah satu sisi bergeser.
     */
    private function completeRkkIbprpLinks(): void
    {
        if (! class_exists(RkkService::class)
            || ! Schema::hasTable('crm_rkk_documents')
            || ! Schema::hasTable('crm_rkk_ibprp_links')) {
            return;
        }

        // Hanya melengkapi RKK kanon yang sudah dibuat sisi Crm — membuatnya
        // adalah pekerjaan CrmDatabaseSeeder, bukan seeder ini.
        $rkk = RkkDocument::query()->where('code', 'RKK/2026/VIII/0001')->first();

        if ($rkk === null) {
            return;
        }

        $source = DB::table('prj_risk_register')
            ->whereNull('deleted_at')
            ->orderBy('project_id')
            ->value('project_id');

        $entryIds = $source === null ? [] : DB::table('prj_risk_register')
            ->where('project_id', $source)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->limit(5)
            ->pluck('id')
            ->all();

        if ($entryIds !== []) {
            $rkk->forceFill(['project_id' => $source])->save();
            app(RkkService::class)->syncIbprpLinks($rkk->refresh(), $entryIds);
        }
    }

    /**
     * P3 — two owner opnames on PRJ-2026-001: OPN 1 APPROVED, OPN 2 a draft.
     *
     * WHY ONE OF THEM IS APPROVED. An approved opname is what makes P3 visible
     * at all: ProgressService rewrites every weekly row it covers with the
     * value-weighted measurement and relabels it 'progress_measurement'. A demo
     * whose only opname is a draft shows the form and none of the mechanism —
     * not the actual_pct switch, not an owner claim built from a measurement,
     * not the F/DS sheet naming its opname.
     *
     * AND WHY IT DOES NOT MOVE THE KURVA-S. The measured volumes are not
     * invented: each BOQ item is measured at the physical percentage its own
     * WBS leaf reports (self::MEASURED_PCT mirrors seedWbs above, item for
     * item). The WBS leaf weights ARE the BOQ cost shares, so the
     * value-weighted percentage lands on 55,0001 % — the week-8 actual the
     * kurva-S already carries, CONFIRMED by measurement rather than replaced by
     * a different story. The opname closes on 29-03-2026, the last day of week
     * 8, so it covers that week and no earlier one: week 8 flips its source to
     * the opname and weeks 1-7 keep their typed percentages, which is both
     * halves of the rule standing side by side in the demo.
     *
     * WRITTEN APPROVED, NOT APPROVED THROUGH MeasurementService — the
     * seedBaseline rule (maker-checker needs two people, a seeder is nobody).
     * The service's ceiling guards already ran on create(); its other effect,
     * re-deriving the weekly rows, is called explicitly below through the same
     * public method the approval itself calls, so the demo's weekly rows are
     * derived by the real service and never typed here. NO JOURNAL IS POSTED —
     * approving an opname is not an accounting event (roadmap §7,
     * FORWARD-ONLY); the owner claim built from it is what books revenue, and
     * that is left to whoever walks the demo.
     *
     * OPN 2 STAYS A DRAFT, and carries the two things worth demonstrating:
     * 800 m3 of galian that ONLY the approved addendum volume in
     * prj_contract_variations makes legal (its qty_cum lands exactly on the
     * ceiling), and lines located in the two zones — one accepted, one waiting
     * for repair — so approving it and billing it demonstrates kriteria #6
     * refusing the blocked zone by name.
     */
    /**
     * Point already-seeded opname lines back at the BOQ rows that exist NOW.
     *
     * Silent no-op in the normal case (a first seed, or a second one where the
     * ids happened to survive). It repairs rather than reports because a demo
     * database is not a place to raise an alarm — but it repairs only what it
     * can prove: a line whose description matches exactly one current BOQ row.
     * A line it cannot resolve is LEFT ALONE and left broken, because guessing
     * which item a measured volume belongs to is the one thing worse than a
     * dangling id.
     */
    private function rekeyMeasurementLines(Project $project): void
    {
        if ($project->contract_id === null || ! Schema::hasTable('est_boq_items')) {
            return;
        }

        $boqId = DB::table('est_boqs')->where('contract_id', $project->contract_id)->value('id');

        if ($boqId === null) {
            return;
        }

        $current = DB::table('est_boq_items')->where('boq_id', $boqId)->get(['id', 'description']);
        $live = $current->pluck('id')->all();

        // description => id, only where the description names exactly one row.
        $byDescription = $current->groupBy('description')
            ->filter(fn ($rows): bool => $rows->count() === 1)
            ->map(fn ($rows): int => (int) $rows->first()->id);

        $stale = DB::table('prj_progress_measurement_items')
            ->join('prj_progress_measurements as opname', 'opname.id', '=', 'prj_progress_measurement_items.progress_measurement_id')
            ->where('opname.project_id', $project->id)
            ->whereNotIn('prj_progress_measurement_items.boq_item_id', $live)
            ->get(['prj_progress_measurement_items.id', 'prj_progress_measurement_items.description']);

        foreach ($stale as $line) {
            $id = $byDescription[(string) $line->description] ?? null;

            if ($id !== null) {
                DB::table('prj_progress_measurement_items')->where('id', $line->id)->update(['boq_item_id' => $id]);
            }
        }
    }

    private function seedProgressMeasurement(Project $project, ProgressService $progressService, array $zones): void
    {
        if (ProgressMeasurement::query()->where('project_id', $project->id)->exists()) {
            // Idempotent — but not merely "already there". EstimationDatabaseSeeder
            // rebuilds BOQ/2026/0001 through BoqService::replaceSections, which
            // hard-deletes and re-inserts every est_boq_items row, so on a SECOND
            // `db:seed` the boq_item_id these lines point at no longer exists. The
            // sibling register (seedContractVariations) converges by replacing its
            // rows; an opname cannot — it is an approved document, and rewriting an
            // approved measurement is exactly what the ceiling exists to prevent.
            // So it re-keys instead, matching on the description it snapshotted at
            // approval, which is the only stable handle it kept.
            $this->rekeyMeasurementLines($project);

            return;
        }

        if ($project->contract_id === null || ! Schema::hasTable('est_boq_items')) {
            return; // Crm/Estimation not seeded yet — skip gracefully (CONVENTIONS §8)
        }

        $boqId = DB::table('est_boqs')->where('contract_id', $project->contract_id)->value('id');

        if ($boqId === null) {
            return;
        }

        $items = DB::table('est_boq_items')
            ->where('boq_id', $boqId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'wbs_code', 'qty']);

        $lines = [];

        foreach ($items as $item) {
            $pct = self::MEASURED_PCT[(string) $item->wbs_code] ?? null;
            $qty = $pct === null ? 0.0 : round((float) $item->qty * $pct / 100, 3);

            if ($qty > 0) {
                $lines[] = ['boq_item_id' => (int) $item->id, 'qty_this' => $qty];
            }
        }

        if ($lines === []) {
            return;
        }

        $measurements = app(MeasurementService::class);

        $first = $measurements->create([
            'project_id' => $project->id,
            'period_start' => '2026-02-02',
            'period_end' => '2026-03-29',
            'notes' => 'Opname bersama MK — volume terpasang kumulatif s/d minggu ke-8 (29 Maret 2026), '
                .'diukur per item BOQ/2026/0001.',
            'items' => $lines,
        ]);

        $approver = User::query()->where('email', 'direktur@nusantara.test')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($approver !== null) {
            $first->forceFill(['status' => DocumentStatus::Approved])->save();

            $first->approvals()->create([
                'action' => 'approved',
                'user_id' => (int) $approver,
                'note' => 'Opname 1 disetujui bersama Konsultan MK; volume terukur sesuai laporan mingguan ke-8.',
            ]);

            // The one effect of an approval that is not a status: every weekly
            // row this opname covers is re-derived from the measurement, by the
            // service, exactly as MeasurementService::approve does it.
            $progressService->refreshWeeklyActualsFromMeasurements($project);
        }
        // else: Iam not seeded, so there is nobody to approve on behalf of and
        // the opname ships as the draft it was created as — the demo then shows
        // the document without the switch, which is honest about what it has.

        $this->seedSecondMeasurement($project, $zones);
        $this->bumpSequence('OPN', 2);
    }

    /**
     * OPN 2 — the April draft: the addendum volume, and the two zones.
     *
     * Skipped line by line rather than wholesale: without the approved CCO
     * volume in the register the galian line would exceed the ceiling and
     * MeasurementService would (rightly) refuse the whole document, so that
     * line is added only when the register actually carries it.
     */
    private function seedSecondMeasurement(Project $project, array $zones): void
    {
        $lines = [];

        $galian = $this->lookupBoqItemId('BOQ/2026/0001', 'B.1');
        $beton = $this->lookupBoqItemId('BOQ/2026/0001', 'B.2');
        $besi = $this->lookupBoqItemId('BOQ/2026/0001', 'B.3');

        $addendumVolume = $galian === null ? 0.0 : (float) ContractVariation::query()
            ->where('boq_item_id', $galian)
            ->where('qty_change', '>', 0)
            ->sum('qty_change');

        if ($galian !== null && $addendumVolume > 0) {
            $lines[] = [
                'boq_item_id' => $galian,
                'qty_this' => $addendumVolume,
                'notes' => 'Volume tambahan Addendum I (galian basement) — tepat pada plafon kontrak + CCO.',
            ];
        }

        if ($beton !== null) {
            $lines[] = [
                'boq_item_id' => $beton,
                'location_id' => $zones['a']?->id,
                'qty_this' => 300,
                'notes' => 'Pengecoran plat & balok lantai 5 zona A.',
            ];
        }

        if ($besi !== null) {
            $lines[] = [
                'boq_item_id' => $besi,
                'location_id' => $zones['b']?->id,
                'qty_this' => 40000,
                'notes' => 'Pembesian lantai 5 zona B — zona masih menunggu perbaikan (BAPP terakhir).',
            ];
        }

        if ($lines === []) {
            return;
        }

        app(MeasurementService::class)->create([
            'project_id' => $project->id,
            'period_start' => '2026-03-30',
            'period_end' => '2026-04-30',
            'notes' => 'Opname 2 (draf) — periode April 2026, termasuk volume tambahan Addendum I.',
            'items' => $lines,
        ]);
    }

    /**
     * P3 — the VOLUME face of the two approved addenda CrmDatabaseSeeder signs.
     *
     * The change order is a VALUE document and carries no lines, so without
     * these rows the opname ceiling silently degrades to the bare BOQ and every
     * legitimate addendum volume is refused with no way out. Addendum I adds
     * 800 m3 to BOQ B.1 at the contract's own unit price; Addendum II removes
     * lump-sum scope, whose volume face is a FRACTION of the one 'ls' the item
     * carries — the money is the primary figure there and 0,102 ls is that
     * figure divided by the lump-sum price, which the row's own notes say.
     *
     * REPLACED PER CHANGE ORDER ON EVERY RUN, not updateOrCreate'd on
     * (change_order_id, boq_item_id). EstimationDatabaseSeeder rebuilds
     * BOQ/2026/0001 through BoqService::replaceSections, which hard-deletes and
     * re-inserts every est_boq_items row — so on a second `db:seed` the item id
     * this register points at no longer exists, and a key that included it
     * would leave the orphan behind and add a second row beside it. Replacing
     * the change order's rows converges instead: one row per addendum item,
     * pointing at the BOQ line that exists now.
     */
    private function seedContractVariations(Project $project): void
    {
        if ($project->contract_id === null || ! Schema::hasTable('crm_contract_change_orders')) {
            return; // Crm not seeded yet — skip gracefully (CONVENTIONS §8)
        }

        $rows = [
            ['ref' => 'ADD-I/GSP/2026', 'wbs' => 'B.1', 'qty' => 800.0, 'unit' => 'm3',
                'notes' => 'Tambah volume galian tanah basement 800 m3 (Addendum I) pada harga satuan kontrak.'],
            ['ref' => 'ADD-II/GSP/2026', 'wbs' => 'C.5', 'qty' => -0.102, 'unit' => 'ls',
                'notes' => 'Kurang lingkup MEP lainnya senilai Rp 84.592.000 (Addendum II) = 0,102 dari harga lump sum item C.5.'],
        ];

        foreach ($rows as $row) {
            $orderId = $this->lookupChangeOrderId((int) $project->contract_id, $row['ref']);
            $itemId = $this->lookupBoqItemId('BOQ/2026/0001', $row['wbs']);

            if ($orderId === null || $itemId === null) {
                continue;
            }

            ContractVariation::query()->where('change_order_id', $orderId)->delete();

            ContractVariation::query()->create([
                'contract_id' => (int) $project->contract_id,
                'change_order_id' => $orderId,
                'boq_item_id' => $itemId,
                'qty_change' => $row['qty'],
                'unit' => $row['unit'],
                'notes' => $row['notes'],
            ]);
        }
    }

    /**
     * The two zones of lantai 5 the seeded daily reports already work in
     * ("pengecoran plat & balok lantai 5 zona A", "zona B").
     *
     * core_locations is Core's table and EngineeringDatabaseSeeder seeds the
     * SAME tower row by code — with the same payload, and it runs after this
     * one — so whichever seeder runs first creates it and the other's
     * updateOrCreate is a no-op. Lantai 5 and its two zones belong here because
     * BAPP and the opname's per-zone lines are Projects documents and the
     * Engineering seeder knows nothing about them.
     *
     * @return array{a: ?Location, b: ?Location}
     */
    private function seedZones(Project $project): array
    {
        if (! Schema::hasTable('core_locations')) {
            return ['a' => null, 'b' => null];
        }

        $tower = Location::query()->updateOrCreate(['code' => 'GSP-T1'], [
            'project_id' => $project->id,
            'kind' => 'tower',
            'name' => 'Gedung Utama',
            'sort_order' => 1,
        ]);

        $floor = Location::query()->updateOrCreate(['code' => 'GSP-T1-L05'], [
            'project_id' => $project->id,
            'parent_id' => $tower->id,
            'kind' => 'floor',
            'name' => 'Lantai 5',
            'sort_order' => 5,
        ]);

        $zones = [];

        foreach ([['a', 'ZA', 'Zona A', 1], ['b', 'ZB', 'Zona B', 2]] as [$key, $suffix, $name, $order]) {
            $zones[$key] = Location::query()->updateOrCreate(['code' => "GSP-T1-L05-{$suffix}"], [
                'project_id' => $project->id,
                'parent_id' => $floor->id,
                'kind' => 'zone',
                'name' => $name,
                'sort_order' => $order,
            ]);
        }

        return $zones;
    }

    /**
     * P3 — four BAPP sheets over two zones, which is what the register looks
     * like on a real floor.
     *
     * Zona A tells the story the table exists for: BAPP I found a defect
     * (nunggu perbaikan), BAPP II accepted the repair (selesai) — the second
     * sheet rests on the first, which is why prj_zone_certificates has no
     * unique key per zone and why "the zone's status" is the LATEST sheet.
     * Zona B is mid-story: inspected (diperiksa), then found defective
     * (nunggu perbaikan) — so the demo carries a zone an owner claim refuses to
     * bill, which is kriteria #6 with something to point at.
     *
     * Through the service, not the model: the `done` sheet on zona A has to
     * pass the open-NCR gate like any other, and a demo that bypassed its own
     * gate would be seeding a state the app refuses.
     */
    private function seedZoneCertificates(Project $project, array $zones): void
    {
        if ($zones['a'] === null || $zones['b'] === null) {
            return;
        }

        if (ZoneCertificate::query()->where('project_id', $project->id)->exists()) {
            return; // idempotent: never mint a second round of sheets
        }

        $sheets = [
            ['zone' => 'a', 'status' => ZoneCertificateStatus::WaitingRepair, 'date' => '2026-03-20',
                'notes' => 'Keropos pada sisi bawah balok B5-B7 dan sudut kolom K12; zona menunggu perbaikan.'],
            ['zone' => 'a', 'status' => ZoneCertificateStatus::Done, 'date' => '2026-03-27',
                'notes' => 'Grouting balok B5-B7 dan perbaikan sudut kolom K12 selesai serta diperiksa ulang; zona diterima.'],
            ['zone' => 'b', 'status' => ZoneCertificateStatus::Check, 'date' => '2026-03-24',
                'notes' => 'Pemeriksaan bersama plat lantai 5 zona B dimulai; hasil belum lengkap.'],
            ['zone' => 'b', 'status' => ZoneCertificateStatus::WaitingRepair, 'date' => '2026-03-28',
                'notes' => 'Retak susut pada plat zona B dan bekas bekisting belum dirapikan; zona menunggu perbaikan.'],
        ];

        $service = app(ZoneCertificateService::class);

        foreach ($sheets as $sheet) {
            $service->create([
                'project_id' => $project->id,
                'location_id' => $zones[$sheet['zone']]->id,
                'status' => $sheet['status']->value,
                'certified_at' => $sheet['date'],
                // A recorded fact, never derived from project master data
                // (roadmap §7): the MK walked these zones and signed.
                'certified_by_party' => CertifyingParty::Mk->value,
                'certified_by_name' => 'Ir. Bambang Setiawan (Konsultan MK)',
                'notes' => $sheet['notes'],
            ]);
        }

        $this->bumpSequence('BAPP', 4);
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
                // P0-A: rincian per jabatan; jumlahnya = manpower_count di atas.
                'manpower' => [
                    'project_manager' => 1, 'deputy_project_manager' => 1, 'engineering' => 3,
                    'komersial' => 2, 'keuangan' => 1, 'danlat' => 2, 'produksi' => 4,
                    'safety_officer' => 2, 'mandor_sipil' => 60, 'mandor_arsitek' => 30,
                    'mandor_mep' => 24, 'subkont' => 18,
                ], // 148
                'activity_lines' => [
                    [
                        'description' => 'Pengecoran plat & balok lantai 5 zona A',
                        'progress_note' => '86 m3 tercor', 'target_note' => 'Zona B besok',
                    ],
                    [
                        'description' => 'Pembesian kolom lantai 6 zona B',
                        'progress_note' => '420 btg terpasang', 'target_note' => 'Selesai kolom K19-K24',
                    ],
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
                'manpower' => [
                    'project_manager' => 1, 'deputy_project_manager' => 1, 'engineering' => 3,
                    'komersial' => 2, 'keuangan' => 1, 'danlat' => 2, 'produksi' => 4,
                    'safety_officer' => 2, 'mandor_sipil' => 62, 'mandor_arsitek' => 30,
                    'mandor_mep' => 24, 'subkont' => 20,
                ], // 152
                'activity_lines' => [
                    [
                        'description' => 'Lanjutan pengecoran plat lantai 5 zona B',
                        'progress_note' => '92 m3 tercor', 'obstacle' => 'Antrian truk mixer 1,5 jam',
                    ],
                    [
                        'description' => 'Pasangan dinding bata lantai 2 & sparing MEP plat lantai 6',
                        'target_note' => 'Marking bata lantai 3',
                    ],
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
                'manpower' => [
                    'project_manager' => 1, 'deputy_project_manager' => 1, 'engineering' => 3,
                    'komersial' => 2, 'keuangan' => 1, 'danlat' => 2, 'produksi' => 4,
                    'safety_officer' => 2, 'mandor_sipil' => 55, 'mandor_arsitek' => 28,
                    'mandor_mep' => 22, 'subkont' => 16,
                ], // 137
                'activity_lines' => [
                    [
                        'description' => 'Pembesian balok lantai 6 zona A',
                        'progress_note' => '380 btg terpasang',
                        'obstacle' => 'Hujan deras 14.00-16.00, area terbuka berhenti',
                    ],
                    [
                        'description' => 'Erection scaffolding lantai 6 & marking bata lantai 3',
                        'target_note' => 'Scaffolding tuntas sebelum cor zona B',
                    ],
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

            // P0-A: rincian per jabatan + baris uraian, SEEDER SAJA — basis
            // data hidup tidak pernah di-seed ulang, dan laporan lama di
            // instalasi nyata tetap tanpa rincian (forward-only, tanpa
            // backfill). Ditulis lewat model, bukan DailyReportService: seeder
            // ini di-replay idempoten dan angka manpower_count demo di atas
            // memang = jumlah rinciannya.
            $report->manpower()->delete();

            foreach ($data['manpower'] as $roleKey => $headcount) {
                $report->manpower()->create([
                    'role_key' => $roleKey,
                    'headcount' => $headcount,
                ]);
            }

            $report->activityLines()->delete();

            foreach ($data['activity_lines'] as $i => $line) {
                $report->activityLines()->create($line + ['sort_order' => $i + 1]);
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
     * Resolve an approved CRM change order by the customer reference the Crm
     * seeder stamps on it; null when Crm isn't migrated/seeded.
     *
     * ONLY AN APPROVED ONE: the variation register may hold rows for a draft
     * addendum (that is how a QS works) but the demo's ceiling story rests on a
     * signed document, and MeasurementService counts nothing else.
     */
    private function lookupChangeOrderId(int $contractId, string $customerRef): ?int
    {
        $id = DB::table('crm_contract_change_orders')
            ->where('contract_id', $contractId)
            ->where('customer_ref', $customerRef)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('deleted_at')
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
