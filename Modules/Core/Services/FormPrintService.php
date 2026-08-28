<?php

namespace Modules\Core\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Core\Models\Company;
use Modules\Core\Support\PrintableDocuments;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Services\CrmFormService;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Transfer;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectStatus;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Models\OvertimePermit;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WorkPermit;
use Modules\Projects\Services\DefectService;
use Modules\Projects\Services\LaporanFormService;

/**
 * Formulir rumah — the owner's own construction forms, printed by the browser.
 *
 * This is NOT DocumentPdfService and must not become it. That service renders
 * four commercial documents through dompdf at A4 portrait, which is right for
 * an invoice and impossible for these: the weekly schedule is a landscape grid
 * with two-row-deep grouped column headers that dompdf cannot lay out, and its
 * page box is portrait-only. So the house forms are PDF-ready HTML — opened in
 * a tab, printed with the browser's own engine, saved as PDF from the print
 * dialog. Chrome lays out the grid, repeats the grouped header on page 2 and
 * honours a landscape page box; dompdf does none of the three.
 *
 * Everything a house form carries above and below its body table is assembled
 * here, once: the four-party band (PEMILIK / KONSULTAN MK / PROYEK /
 * KONTRAKTOR), the identity block (no. SPK, waktu pelaksanaan, minggu ke, hari
 * ke, sisa hari) and the three-column signature block. A form contributes its
 * body and nothing else, so a laporan harian and a laporan mingguan cannot
 * disagree about which day of the contract today is.
 *
 * THE RULE THAT MATTERS MOST HERE — a form is signed by three parties and
 * filed as the project record. Some cells on the paper have no counterpart in
 * this ERP (lokasi/area and the ALAT table on an izin kerja; every laporan
 * table cell whose report predates the P0-A line tables; perpanjangan waktu on
 * a contract with no approved addendum). The paper leaves those as dotted
 * lines for the site to fill in by hand, and so does this. A cell is printed
 * from the database or it is printed as a ruled blank. There is no third
 * option, and in particular there is no plausible-looking default.
 */
class FormPrintService
{
    /**
     * Every printable form: one entry, one place.
     *
     * A form is reachable only from here, so a slug in a URL can never select
     * anything but a registered method — and the endpoint derives the
     * permission from the same row, which is what lets one route serve forms
     * belonging to different modules while each keeps its owner's .view.
     *
     * ADDING A FORM: append one row below, and add the private composer method
     * it names. Both halves are required; a row whose method is missing is
     * refused exactly like an unknown slug, so a half-applied change cannot
     * reach a user as a 500.
     */
    /**
     * The seven bespoke forms, in the catalogue's row shape.
     *
     * The catalogue used to answer only for the declarative registry, so
     * GET api/core/print/forms said 33 while the endpoint served 40 — the
     * seven Projects forms were printable but undiscoverable (temuan T3,
     * laporan v2 Bagian 10). The SPA already dedups by slug (printButtonsFor
     * keeps a schema.js declaration over its catalogue twin), so listing them
     * here draws no second button anywhere.
     *
     * @return array<int, array{slug: string, resource: string, label: string, idField: string, params: array<string, string>, permission: string}>
     */
    public static function catalogueRows(): array
    {
        $rows = [];

        foreach (self::FORMS as $slug => $definition) {
            $rows[] = [
                'slug' => $slug,
                'resource' => $definition['resource'],
                'label' => $definition['label'],
                'idField' => $definition['idField'],
                'params' => $definition['params'],
                'permission' => $definition['permission'],
            ];
        }

        return $rows;
    }

    private const FORMS = [
        'data-proyek' => [
            'label' => 'Data Proyek',
            'permission' => 'prj.view',
            'compose' => 'dataProyek',
            'resource' => 'projects',
            'idField' => 'id',
            'params' => [],
        ],
        // FORM REGISTRY — tambahkan formulir baru tepat di bawah baris ini.
        'laporan-harian' => [
            'label' => 'Laporan Harian',
            // The context key is a prj_daily_reports id, not a project id — one
            // report is one printed page, and the project comes off the report.
            'permission' => 'prj.view',
            'compose' => 'laporanHarian',
            'resource' => 'projects/daily-reports',
            'idField' => 'id',
            'params' => ['tanggal' => 'report_date'],
        ],
        'laporan-mingguan' => [
            'label' => 'Detail Schedule / Program Kerja',
            'permission' => 'prj.view',
            'compose' => 'laporanMingguan',
            'resource' => 'projects/weekly-progress',
            'idField' => 'project_id',
            'params' => ['minggu' => 'week_no'],
        ],
        'daftar-temuan' => [
            'label' => 'Daftar Temuan / Defect List',
            'permission' => 'prj.view',
            'compose' => 'daftarTemuan',
            'resource' => 'projects/defects',
            'idField' => 'project_id',
            'params' => [],
        ],
        // The three site permits — P0-C: real documents now, so each sheet is
        // anchored on the PERMIT id (one row, one printed permit), no longer on
        // the project id of the blank-pad era. prj.view still guards all three:
        // printing is reading in another shape, and the permit is prj data.
        'izin-kerja' => [
            'label' => 'Izin Kerja Lapangan',
            'permission' => 'prj.view',
            'compose' => 'izinKerja',
            'resource' => 'projects/work-permits',
            'idField' => 'id',
            'params' => [],
        ],
        'izin-lembur' => [
            'label' => 'Izin Kerja Lembur',
            'permission' => 'prj.view',
            'compose' => 'izinLembur',
            'resource' => 'projects/overtime-permits',
            'idField' => 'id',
            'params' => [],
        ],
        'izin-material' => [
            'label' => 'Izin Masuk / Keluar Material & Peralatan',
            'permission' => 'prj.view',
            'compose' => 'izinMaterial',
            'resource' => 'projects/gate-passes',
            'idField' => 'id',
            'params' => [],
        ],
    ];

    /** What a letterhead logo may be, and how large it may get. */
    private const LOGO_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    private const LOGO_MAX_BYTES = 1_048_576;

    /**
     * Indonesian month names. The third copy in this codebase (see
     * DocumentPdfService and Support\CalendarEvents) and deliberately so:
     * APP_LOCALE is 'en' with no lang/ directory, so reaching Carbon's
     * translatedFormat() means switching the whole application locale to 'id'
     * and taking every validation message with it.
     */
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * The abbreviated months of the kop's PERPANJANGAN WAKTU lines — "14 Agu
     * 2027", the format the P0-B spec fixes for a line that packs a day
     * count, a date and a document number into one identity cell. Only there:
     * every other date on every form stays spelled out through MONTHS above
     * (and F/BATK's own time table spells its months in full).
     */
    private const MONTHS_SHORT = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    /**
     * The form as a standalone HTML document, ready for the print dialog.
     *
     * $context is the URL: ['id' => 12, 'date' => '2026-06-15', 'week' => 24].
     * Which of them a form reads is the form's business.
     */
    public function html(string $form, array $context = []): string
    {
        $document = $this->compose($form, $context);

        return view($document['view'], $document['data'])->render();
    }

    /**
     * What guards a form and what to call it — read by the endpoint before it
     * touches the record, and by nothing else.
     *
     * THE LOOKUP ORDER, and there is exactly one: const FORMS above is asked
     * FIRST, Support\PrintableDocuments second. A slug registered in both would
     * therefore be shadowed — the registry entry would never render and nothing
     * would say so — which is why the two key spaces are asserted disjoint by
     * PrintRegistryTest rather than merely hoped to be. Checking it on every
     * request would buy nothing: a collision is a programming mistake made once
     * at edit time, not a condition that can arise at runtime.
     *
     * @return array{label: string, permission: string, compose: ?string, registry: bool}
     */
    public function definition(string $form): array
    {
        $definition = self::FORMS[$form] ?? null;

        if ($definition !== null) {
            // A row whose composer method is missing is refused exactly like an
            // unknown slug — and refused HERE rather than falling through to
            // the registry, so a half-applied bespoke change can never be
            // answered by a registry document that happens to share its name.
            if (! method_exists($this, $definition['compose'])) {
                throw new InvalidArgumentException("Jenis formulir cetak tidak dikenal: {$form}.");
            }

            return $definition + ['registry' => false];
        }

        $registry = app(PrintableDocuments::class);

        if (! $registry->has($form)) {
            throw new InvalidArgumentException("Jenis formulir cetak tidak dikenal: {$form}.");
        }

        $entry = $registry->definition($form);

        return [
            'label' => $entry['label'],
            'permission' => $entry['permission'],
            'compose' => null,
            'registry' => true,
        ];
    }

    /**
     * Everything above the body table and everything below it.
     *
     * Public because every form's composer calls it and because the arithmetic
     * in here is worth testing on its own: "hari ke" and "sisa hari" are read
     * off the form by the site and by the MK, and an off-by-one puts a laporan
     * mingguan in the wrong week for the rest of the job.
     *
     * $options:
     *   date      — the form's own date (default: today)
     *   period    — the PERIODE line (default: the form's date)
     *   pekerjaan — the PEKERJAAN line (default: the contract title, unless it
     *               merely repeats the project name — then a ruled blank)
     *   signer    — ['name' => …, 'role' => …] for the contractor's column
     *               (default: site manager, else project manager)
     */
    public function header(Project $project, array $options = []): array
    {
        /*
         * withTrashed on the two that soft-delete, and this is the one place
         * the registry's own sweep could not reach.
         *
         * PrintableDocuments constrains the eager loads IT declares, entry by
         * entry. These four are loaded HERE, in the shared house header, on
         * behalf of all 22 project-backed sheets — so a soft-deleted customer
         * emptied the PEMILIK box of the four-party band, and a soft-deleted
         * contract ruled NO. SPK / KONTRAK and TANGGAL SPK blank and blanked
         * the PEKERJAAN line on the ten sheets that take it from the contract.
         * The job did not stop having an owner or a contract number when
         * somebody tidied a row; the paper filed against that job still needs
         * to name them.
         *
         * projectManager and siteManager are Users, which hard-delete — a
         * missing one is genuinely gone and its rule is left for the pen.
         */
        $project->loadMissing([
            'customer' => fn ($query) => $query->withTrashed(),
            'contract' => fn ($query) => $query->withTrashed(),
            'projectManager',
            'siteManager',
        ]);

        $company = Company::current();
        $contract = $project->contract;
        $date = $this->toDate($options['date'] ?? null) ?? Carbon::now()->startOfDay();
        $schedule = $this->schedule($project, $date);
        $consultantRole = trim((string) ($project->consultant_role ?? '')) ?: 'Konsultan MK';
        $extensions = $this->timeExtensionLines($contract);

        return [
            // The band across the top. Four boxes on the paper, four boxes here
            // — including the empty one, because a job with no MK still has a
            // form with four columns.
            'parties' => [
                ['caption' => 'PEMILIK', 'name' => $project->customer?->name, 'meta' => null, 'logo' => null],
                ['caption' => mb_strtoupper($consultantRole), 'name' => $project->consultant_name, 'meta' => null, 'logo' => null],
                ['caption' => 'PROYEK', 'name' => $project->name, 'meta' => $project->code, 'logo' => null],
                [
                    'caption' => 'KONTRAKTOR',
                    'name' => $company?->legal_name ?: $company?->name ?: config('erp.company.name'),
                    'meta' => null,
                    'logo' => $this->logo($company),
                ],
            ],
            'projectTitle' => mb_strtoupper((string) $project->name),
            'pekerjaan' => $options['pekerjaan'] ?? $this->pekerjaan($project),
            'identity' => [
                // The number the CUSTOMER knows the job by, which is what an MK
                // checks the form against. Our own contract code is the
                // fallback, never the first choice.
                ['label' => 'NO. SPK / KONTRAK', 'value' => $contract?->contract_number_customer ?: $contract?->code],
                ['label' => 'TANGGAL SPK', 'value' => $this->date($contract?->sign_date)],
                ['label' => 'WAKTU PELAKSANAAN', 'value' => $this->executionWindow($project, $schedule)],
                // P0-B: the first two APPROVED addendum waktu of the contract,
                // in change-date order — see timeExtensionLines() for why a
                // third makes line II read "lihat register". No approved
                // addendum leaves the ruled blanks the paper has always had.
                ['label' => 'PERPANJANGAN WAKTU I', 'value' => $extensions[0]],
                ['label' => 'PERPANJANGAN WAKTU II', 'value' => $extensions[1]],
                ['label' => 'PERIODE', 'value' => $options['period'] ?? $this->date($date)],
                ['label' => 'TANGGAL', 'value' => $this->date($date)],
                ['label' => 'MINGGU KE', 'value' => $schedule['weekNo'] === null ? null : (string) $schedule['weekNo']],
                ['label' => 'HARI KE', 'value' => $schedule['dayNo'] === null ? null : (string) $schedule['dayNo']],
                ['label' => 'SISA HARI', 'value' => $schedule['remainingLabel']],
            ],
            'schedule' => $schedule,
            'signatures' => $this->signatures($project, $company, $consultantRole, $date, $options['signer'] ?? null),
            'place' => $project->city ?: $company?->city ?: '',
            'dateLabel' => $this->date($date),
        ];
    }

    /**
     * @return array{view: string, data: array}
     */
    private function compose(string $form, array $context): array
    {
        $definition = $this->definition($form);

        return $definition['registry']
            ? $this->registryDocument($form, $context)
            : $this->{$definition['compose']}($context);
    }

    // ------------------------------------------------------------ the forms

    /**
     * Lembar data proyek — the cover sheet of the site file.
     *
     * Every row on it is answered by the database, which is why it is the form
     * this lane ships: it proves the whole assembly (band, identity block, body
     * table, notes, signatures, form code) without a single hand-filled cell in
     * the body to hide behind.
     */
    private function dataProyek(array $context): array
    {
        $project = $this->project($context);

        return $this->document('data-proyek', $project, $context, [
            'formTitle' => 'DATA PROYEK',
            'formCode' => 'Form F/DP',
            'rows' => $this->projectFacts($project),
        ]);
    }

    /**
     * What the ERP knows about a project, in the order a site file wants it.
     *
     * @return array<int, array{label: string, value: ?string}>
     */
    private function projectFacts(Project $project): array
    {
        $company = Company::current();
        $contract = $project->contract;

        return [
            ['label' => 'Kode proyek', 'value' => $project->code],
            ['label' => 'Nama proyek', 'value' => $project->name],
            ['label' => 'Jenis pekerjaan', 'value' => $project->type?->label()],
            ['label' => 'Lokasi', 'value' => collect([$project->location, $project->city, $project->province])->filter()->implode(', ') ?: null],
            ['label' => 'Pemilik proyek', 'value' => $project->customer?->name],
            ['label' => trim(($project->consultant_role ?: 'Konsultan MK')), 'value' => $project->consultant_name],
            ['label' => 'Kontraktor pelaksana', 'value' => $company?->legal_name ?: $company?->name],
            ['label' => 'No. kontrak internal', 'value' => $contract?->code],
            ['label' => 'Nilai kontrak (DPP)', 'value' => 'Rp '.$this->money($project->contract_value)],
            ['label' => 'Retensi', 'value' => $this->percent($project->retention_pct).' — Rp '.$this->money($project->retentionAmount())],
            ['label' => 'Masa pemeliharaan', 'value' => ((int) $project->warranty_months).' bulan'],
            ['label' => 'Manajer proyek', 'value' => $project->projectManager?->name],
            ['label' => 'Manajer lapangan', 'value' => $project->siteManager?->name],
            ['label' => 'Progres rencana', 'value' => $this->percent($project->planned_progress_pct)],
            ['label' => 'Progres realisasi', 'value' => $this->percent($project->actual_progress_pct)],
            ['label' => 'Deviasi', 'value' => $this->percent($project->progressDeviation())],
            ['label' => 'Status', 'value' => $project->status?->label()],
        ];
    }

    // ------------------------------------------------------------ assembly

    /**
     * The shared shape every form is rendered through.
     *
     * Defaults live here so a form's composer names only what it changes — and
     * so a form that forgets to say anything about the notes block gets the
     * paper's own notes block rather than none.
     *
     * @return array{view: string, data: array}
     */
    private function document(string $view, Project $project, array $context, array $data): array
    {
        return $this->sheet($view, $data + [
            'project' => $project,
            'header' => $this->header($project, $context),
        ]);
    }

    /**
     * The defaults every sheet carries, bespoke or registry-defined.
     *
     * Split out of document() so the declarative path cannot drift from the
     * seven hand-written forms: both go through this method, so both get the
     * same formatters, the same notes default and the same printed-at stamp,
     * and a change made for one is a change made for both.
     *
     * @return array{view: string, data: array}
     */
    private function sheet(string $view, array $data): array
    {
        return [
            'view' => "coredoc::forms.{$view}",
            'data' => $data + [
                // Portrait unless the form says otherwise; the weekly grid says
                // otherwise.
                'orientation' => 'portrait',
                'formCode' => null,
                // The paper's notes area: "Catatan :", ruled lines, and the two
                // hand-filled sentences about the working day. A form passes
                // null to leave it off entirely.
                'notes' => ['text' => null, 'lines' => 3, 'weather' => null, 'hours' => false],
                // WIKA's IK convention — Judul / No. Dok. / No. Rev. / Tanggal.
                // Off unless a form asks for it.
                'docControl' => null,
                // Formatting is handed to the templates as closures, so every
                // amount and every date on every form is rendered by one piece
                // of code and cannot drift between forms.
                'money' => fn ($value): string => $this->money($value),
                'date' => fn ($value): string => $this->date($value),
                'printedAt' => $this->date(Carbon::now()).' '.Carbon::now()->format('H:i'),
            ],
        ];
    }

    /**
     * The three columns at the bottom of every house form.
     *
     * Columns one and two carry no name on purpose. Nothing in this ERP records
     * who signs for the owner or for the MK — printing a name there would be
     * forging a signature line — so they get the paper's blank rule and the
     * party's role beneath it. Column three is ours, and hr_employees knows
     * both the name and the position.
     */
    private function signatures(Project $project, ?Company $company, string $consultantRole, Carbon $date, ?array $signer): array
    {
        $ours = $project->siteManager ?? $project->projectManager;

        return [
            [
                'heading' => 'Mengetahui,',
                'subheading' => null,
                'party' => $project->customer?->name,
                'name' => null,
                'role' => 'Pemilik Proyek',
            ],
            [
                'heading' => 'Menyetujui / menolak',
                'subheading' => $consultantRole,
                'party' => $project->consultant_name,
                'name' => null,
                'role' => $consultantRole,
            ],
            [
                'heading' => trim(($project->city ?: $company?->city ?: '').', '.$this->date($date), ' ,'),
                'subheading' => 'Kontraktor Pelaksana',
                'party' => $company?->legal_name ?: $company?->name,
                'name' => $signer['name'] ?? $ours?->name,
                'role' => $signer['role'] ?? $ours?->position,
            ],
        ];
    }

    /**
     * Hari ke / minggu ke / sisa hari, counted the way a site counts them.
     *
     * Inclusive of both ends: the first day of the job is HARI KE 1, and a job
     * running 1 Januari to 31 Desember is 365 days, not 364. Week 1 is days
     * 1-7. All three are null when the project has no dates yet, which is
     * ordinary — a project exists before its SPK is signed — and a zero there
     * would be a claim rather than a blank.
     *
     * @return array{dayNo: ?int, weekNo: ?int, totalDays: ?int, remainingDays: ?int, remainingLabel: ?string}
     */
    private function schedule(Project $project, Carbon $date): array
    {
        $start = $this->toDate($project->start_date);
        $end = $this->toDate($project->end_date);

        $dayNo = $start === null ? null : $this->daysBetween($start, $date) + 1;
        $remaining = $end === null ? null : $this->daysBetween($date, $end);

        /*
         * A sheet dated BEFORE the project started has no hari-ke — the job had
         * not begun. Left unguarded the arithmetic prints "HARI KE : -12" and
         * "MINGGU KE : 0" onto a document three people sign, which reads as a
         * system that cannot count. The overrun direction below is different on
         * purpose: there the number is real and hiding it would hide the
         * overrun. Before the start there is no number to hide — the honest
         * answer is a blank, and the identity block already renders null as a
         * ruled blank.
         */
        if ($dayNo !== null && $dayNo < 1) {
            $dayNo = null;
        }

        return [
            'dayNo' => $dayNo,
            // intdiv, not ceil: day 7 is still week 1 and day 8 opens week 2.
            'weekNo' => $dayNo === null ? null : intdiv($dayNo - 1, 7) + 1,
            'totalDays' => ($start === null || $end === null) ? null : $this->daysBetween($start, $end) + 1,
            'remainingDays' => $remaining,
            // A job in overrun is exactly the job whose daily report gets read.
            // "0 hari" would hide the overrun from the people signing the form.
            'remainingLabel' => match (true) {
                $remaining === null => null,
                $remaining < 0 => '0 hari (lewat '.abs($remaining).' hari)',
                default => $remaining.' hari',
            },
        ];
    }

    /**
     * The PEKERJAAN line — the scope this particular sheet covers.
     *
     * On the pad it is the work package ("PEKERJAAN STRUKTUR LANTAI 3"), which
     * no table in this ERP holds, so a form that knows its own scope passes it
     * in. The contract title is the honest fallback: it IS the scope as signed.
     * Except when it merely repeats the project name printed two lines above —
     * which is the common case on single-scope jobs — where a ruled blank is
     * worth more than a second copy of the same sentence.
     */
    private function pekerjaan(Project $project): ?string
    {
        $title = trim((string) ($project->contract?->title ?? ''));

        if ($title === '' || mb_strtolower($title) === mb_strtolower(trim((string) $project->name))) {
            return null;
        }

        return $title;
    }

    private function executionWindow(Project $project, array $schedule): ?string
    {
        if ($schedule['totalDays'] === null) {
            return null;
        }

        return $this->date($project->start_date).' s/d '.$this->date($project->end_date)
            .' ('.$schedule['totalDays'].' hari kalender)';
    }

    /**
     * The kop's two PERPANJANGAN WAKTU lines (P0-B).
     *
     * WHICH rows may reach a letterhead is the module's decision and lives in
     * CrmFormService::approvedTimeExtensions — approved only, change-date
     * order, each row quoting the new_end_date its own approval stamped. That
     * method hands over EVERY row; the paper's two lines are the layout
     * decision made here:
     *
     *   one row   — line I, line II stays the ruled blank;
     *   two rows  — lines I and II;
     *   three+    — line I, and line II reads "lihat register". The paper has
     *               no third line, and a kop that showed I and II while a
     *               third approval exists would be read as the WHOLE story —
     *               "+21 hari in total" — by three parties signing under it.
     *               Naming the register is honest; truncating silently is not.
     *
     * No approved addendum (or no contract at all) leaves both lines null —
     * the ruled blanks the paper has always had, byte-identical to the sheets
     * printed before this paket.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function timeExtensionLines(?Contract $contract): array
    {
        if ($contract === null) {
            return [null, null];
        }

        $extensions = app(CrmFormService::class)->approvedTimeExtensions($contract);
        $first = $extensions->first();

        return [
            $first === null ? null : $this->timeExtensionLine($first),
            match (true) {
                $extensions->count() < 2 => null,
                $extensions->count() === 2 => $this->timeExtensionLine($extensions[1]),
                default => 'lihat register',
            },
        ];
    }

    /**
     * One extension as the kop states it: "+14 hari → 14 Agu 2027
     * (CCO/2026/VIII/0003)". The signed day count (a pengurangan prints its
     * minus), the stamped new_end_date — quoted, never re-derived — and the
     * document number the register files the sheet's authority under.
     */
    private function timeExtensionLine(ContractChangeOrder $extension): string
    {
        return sprintf(
            '%+d hari → %s (%s)',
            (int) $extension->days_change,
            $this->shortDate($extension->new_end_date),
            $extension->code,
        );
    }

    /** "14 Agu 2027" — the kop lines only; every other date stays spelled out. */
    private function shortDate($value): string
    {
        $date = $this->toDate($value);

        if ($date === null) {
            return '';
        }

        return $date->format('d').' '.self::MONTHS_SHORT[(int) $date->format('n')].' '.$date->format('Y');
    }

    /**
     * Whole calendar days from $from to $to, negative when $to is earlier.
     *
     * Written through DateTime::diff rather than Carbon's diffInDays because
     * that method's sign and return type have changed between Carbon majors,
     * and "sisa hari" going positive on an overrunning job is the kind of bug
     * nobody reports — they just stop trusting the form.
     */
    private function daysBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $diff = Carbon::instance($from)->startOfDay()->diff(Carbon::instance($to)->startOfDay());

        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    private function project(array $context): Project
    {
        // The SAME constraints header() declares, and they have to be here as
        // well as there: header() reaches for loadMissing, which is a no-op on
        // a relation this loader has already resolved. Loaded plainly here, a
        // soft-deleted customer arrived as null and header()'s withTrashed
        // never ran — the guard was written and did nothing.
        return Project::query()
            ->with([
                'customer' => fn ($query) => $query->withTrashed(),
                'contract' => fn ($query) => $query->withTrashed(),
                'projectManager',
                'siteManager',
            ])
            ->findOrFail($context['id'] ?? null);
    }

    // ---------------------------------------------------------- formatting

    /**
     * The company mark, inlined as a data URI.
     *
     * DocumentPdfService inlines it too, and for a reason that does not apply
     * here: dompdf runs with remote fetching off. This path ends in the same
     * place by a different route — the sheet is handed to the browser as a blob
     * URL, and a blob URL has no base, so "/storage/logo.png" resolves to
     * nothing at all. Read through the public disk rather than by filename,
     * which is what keeps a logo_path of "../../.env" inside the storage
     * directory. Anything missing, oversized or not a raster image yields no
     * logo: a form without one is a form, a broken image is not.
     */
    private function logo(?Company $company): ?string
    {
        $path = trim((string) ($company?->logo_path ?? ''));
        $type = self::LOGO_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        if ($path === '' || $type === null) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path) || $disk->size($path) > self::LOGO_MAX_BYTES) {
            return null;
        }

        $bytes = $disk->get($path);

        return $bytes === null ? null : 'data:'.$type.';base64,'.base64_encode($bytes);
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    /** 5,0000 stored, "5%" printed — nobody writes four decimals on a form. */
    private function percent($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').'%';
    }

    /** Empty string, not "01 Januari 1970", when there is no such date. */
    private function date($value): string
    {
        $date = $this->toDate($value);

        if ($date === null) {
            return '';
        }

        return $date->format('d').' '.self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }

    private function toDate($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        return Carbon::parse($value)->startOfDay();
    }

    // ==================================================================
    // LAPORAN — the two Projects forms (laporan harian, laporan mingguan).
    //
    // Wiring only. Every value in either body comes from
    // Modules\Projects\Services\LaporanFormService, which is also where the
    // decision about what may NOT be printed lives; what these two methods add
    // is the shared house shell, the form's own date, and the WIKA-style
    // document-control strip.
    // ==================================================================

    /**
     * Laporan harian — one prj_daily_reports row, one page.
     *
     * The sheet is dated by the REPORT, never by the day somebody pressed
     * print: "hari ke 52" and "sisa hari 493" are read off the form months
     * later, and a form that recomputed them at print time would answer a
     * different question every time it was reprinted.
     */
    private function laporanHarian(array $context): array
    {
        $report = DailyReport::query()
            ->with(['materials.item'])
            ->findOrFail($context['id'] ?? null);

        // withTrashed: a report on a project that has since been soft-deleted is
        // still the record of what happened on site that day, and refusing to
        // reprint it would lose the file the site actually needs.
        $project = $report->project()->withTrashed()->firstOrFail();
        $body = app(LaporanFormService::class)->harian($report);

        $options = array_merge($context, [
            'date' => $report->report_date,
            'period' => $this->date($report->report_date),
        ]);

        return $this->document('laporan-harian', $project, $options, $body + [
            'formTitle' => 'LAPORAN HARIAN',
            'formCode' => 'Form F/LH',
            'notes' => [
                // safety_notes is the one free-text field the pad's "Catatan"
                // box actually corresponds to. Nothing recorded prints rules.
                'text' => $body['safetyNotes'],
                'lines' => 3,
                'weather' => [
                    'options' => ['Cerah', 'Mendung', 'Hujan'],
                    'pagi' => $body['weather']['pagi'],
                    'sore' => $body['weather']['sore'],
                ],
                // work_start/work_end and the lost-hours reason, as HH:MM
                // strings off the report (P0-A). A report from before those
                // columns carries nulls and the pad's rules print hand-filled,
                // exactly as before.
                'hours' => $body['workHours'],
            ],
            'docControl' => [
                'judul' => 'Laporan Harian',
                'no_dok' => $report->code,
                // prj_daily_reports has no revision counter and inventing "0"
                // would assert one exists. The site writes it if it reissues.
                'no_rev' => null,
                'tanggal' => $this->date($report->report_date),
            ],
        ]);
    }

    /**
     * Laporan mingguan — the landscape schedule for one calendar month.
     *
     * The month is chosen by ?tanggal= — ANY day inside it, because the site
     * asks for "the schedule covering 15 March", not for a month number — and
     * falls back to today's month when the URL says nothing. (year/month keys
     * are honoured too, for a caller that already has them.)
     *
     * The form is then dated at the END of that month, so HARI KE and SISA HARI
     * answer "as at the close of the period" rather than as at whichever day
     * inside it the clerk happened to type.
     */
    private function laporanMingguan(array $context): array
    {
        $project = $this->project($context);
        $asked = $this->toDate($context['date'] ?? null) ?? Carbon::now();
        $year = (int) ($context['year'] ?? $asked->year);
        $month = (int) ($context['month'] ?? $asked->month);

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2999) {
            throw new InvalidArgumentException("Periode laporan mingguan tidak valid: {$year}-{$month}.");
        }

        $body = app(LaporanFormService::class)->mingguan($project, $year, $month);

        $options = array_merge($context, [
            'date' => $body['periodEnd'],
            'period' => $this->date($body['periodStart']).' s/d '.$this->date($body['periodEnd']),
        ]);

        $header = $this->header($project, $options);
        $header['identity'] = $this->weekSpan($project, $header['identity'], $body['weeks']);

        return $this->document('laporan-mingguan', $project, $options, $body + [
            'formTitle' => 'DETAIL SCHEDULE / PROGRAM KERJA',
            'formCode' => 'Form F/DS',
            // The reason this whole lane is browser-printed rather than dompdf.
            'orientation' => 'landscape',
            'header' => $header,
            'notes' => ['text' => null, 'lines' => 2, 'weather' => null, 'hours' => false],
            'docControl' => [
                'judul' => 'Detail Schedule / Program Kerja',
                'no_dok' => $project->code,
                'no_rev' => null,
                'tanggal' => $this->date($body['periodEnd']),
            ],
        ]);
    }

    /**
     * MINGGU KE as a span, because this sheet covers five or six of them.
     *
     * Every number comes from schedule() — the same arithmetic the daily form's
     * single MINGGU KE uses — so a laporan harian dated inside this month can
     * never name a week the monthly sheet does not contain. Weeks that begin
     * before the project did contribute nothing rather than a zero or a
     * negative.
     *
     * @param  array<int, array{label: string, value: ?string}>  $identity
     * @param  array<int, array<string, mixed>>  $weeks
     * @return array<int, array{label: string, value: ?string}>
     */
    private function weekSpan(Project $project, array $identity, array $weeks): array
    {
        $numbers = [];

        foreach ($weeks as $week) {
            $weekNo = $this->schedule($project, Carbon::parse($week['start'])->startOfDay())['weekNo'];

            if ($weekNo !== null && $weekNo >= 1) {
                $numbers[] = $weekNo;
            }
        }

        $value = match (true) {
            $numbers === [] => null,
            min($numbers) === max($numbers) => (string) min($numbers),
            default => min($numbers).' s/d '.max($numbers),
        };

        foreach ($identity as $index => $entry) {
            if ($entry['label'] === 'MINGGU KE') {
                $identity[$index]['value'] = $value;
            }
        }

        return $identity;
    }

    // ==================================================================
    // QC DAN IZIN — the punch-list register and the three site permits.
    //
    // All four are keyed by a PROJECT: the {id} in
    // /api/core/print/forms/{form}/{id} is a prj_projects id, not a document
    // id, unlike laporan-harian above. daftar-temuan additionally reads
    // ?status=; the permits read nothing but ?tanggal=.
    //
    // Two opposite cases sharing one section on purpose, because together they
    // are what makes the honesty rule a rule rather than a habit.
    //
    // DAFTAR TEMUAN is a form this ERP answers completely. prj_defects carries
    // every column the owner's own defect list has — kode, lokasi, uraian,
    // keparahan, sumber, tanggal dilaporkan, tenggat, status, tanggal
    // diperbaiki, tanggal diverifikasi, keterangan — so not one cell of the
    // body is invented and not one is hand-filled either.
    //
    // The three IZIN forms are the opposite extreme. Nothing anywhere in this
    // database records a work permit, an overtime permit or a gate pass; not a
    // partial table, none. Building that workflow is a different piece of work.
    // What is worth shipping today is the pad the site currently photocopies:
    // the four-party band and the contract identity block, printed, and every
    // body cell ruled for the hand that fills it. Each of the three says so on
    // the sheet in plain Indonesian, because the clerk holding the printout has
    // to know that filing the paper IS the record — there is nowhere else for
    // it to go.
    // ==================================================================

    /**
     * DAFTAR TEMUAN / DEFECT LIST — the punch list as the QC folder keeps it.
     *
     * ?status= narrows the printed rows; the recap band above them does not
     * narrow with it, and that asymmetry is deliberate. A register printed
     * "hanya yang terbuka" is a normal thing to want on a punch walk, but the
     * numbers a reader checks it against — how many findings this job has, how
     * many still block BAST II — are properties of the whole register. Printing
     * a filtered recap next to filtered rows would let a page of two open items
     * read as a job with two findings.
     *
     * Landscape: twelve columns do not fit across a portrait page.
     */
    private function daftarTemuan(array $context): array
    {
        $project = $this->project($context);
        $status = $this->defectStatus($context['status'] ?? null);
        $date = $this->toDate($context['date'] ?? null) ?? Carbon::now()->startOfDay();

        $defects = Defect::query()
            ->where('project_id', $project->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->get()
            // Kritis first, then Mayor, then Minor, and inside each level the
            // order they were raised in. The rows that stop the serah terima
            // are the rows the handover table reads, so they go at the top of
            // page 1 rather than wherever the ids happened to fall.
            ->sortBy(fn (Defect $defect): string => sprintf(
                '%d|%s|%s',
                $this->severityRank($defect->severity),
                $defect->reported_on?->toDateString() ?? '9999-12-31',
                (string) $defect->code,
            ))
            ->values()
            ->all();

        // The register is cumulative — it is not a period document — so PERIODE
        // says so instead of repeating TANGGAL.
        $options = array_merge($context, [
            'period' => 'Sejak awal proyek s/d '.$this->date($date),
        ]);

        return $this->document('daftar-temuan', $project, $options, [
            'formTitle' => 'DAFTAR TEMUAN / DEFECT LIST',
            'formCode' => 'Form F/DT',
            'orientation' => 'landscape',
            'rows' => $this->defectRows($defects),
            'summary' => $this->defectSummary($project),
            'filterNote' => $status === null ? null : sprintf(
                'Disaring menurut status : %s. Rekapitulasi di bawah tetap menghitung SELURUH temuan proyek ini, '
                    .'bukan hanya baris yang tercetak.',
                $status->label(),
            ),
            'notes' => ['text' => null, 'lines' => 2, 'weather' => null, 'hours' => false],
            'docControl' => [
                'judul' => 'Daftar Temuan / Defect List',
                'no_dok' => $project->code,
                // prj_defects has no register revision counter, and inventing
                // "0" would assert one exists.
                'no_rev' => null,
                'tanggal' => $this->date($date),
            ],
        ]);
    }

    /**
     * One printed line per finding, straight off the row.
     *
     * Dates are handed over raw and formatted by the template's $date closure,
     * exactly as the laporan forms do it, so the register cannot start writing
     * 25 Maret 2026 differently from the invoice next door.
     *
     * @param  list<Defect>  $defects
     * @return list<array<string, mixed>>
     */
    private function defectRows(array $defects): array
    {
        $rows = [];

        foreach ($defects as $index => $defect) {
            $rows[] = [
                'no' => $index + 1,
                'code' => $defect->code,
                'location' => $defect->location,
                'title' => $defect->title,
                'description' => $defect->description,
                'severity' => $this->severityPaperLabel($defect->severity),
                'source' => $defect->source->label(),
                'reportedOn' => $defect->reported_on?->toDateString(),
                'dueDate' => $defect->due_date?->toDateString(),
                // Defect::isOverdue(), never a comparison written again here:
                // the list screen's stat card and the register have to agree
                // about which items are late, including the rule that an item
                // due today is not yet late.
                'overdue' => $defect->isOverdue(),
                'status' => $defect->status->label(),
                'fixedAt' => $defect->fixed_at?->toDateString(),
                'verifiedAt' => $defect->verified_at?->toDateString(),
                // Carries the waiver reason, the downgrade trail and the reopen
                // history that DefectService writes into it — which is exactly
                // what the KETERANGAN column of the paper register holds.
                'note' => $defect->resolution_note,
            ];
        }

        return $rows;
    }

    /**
     * The recap band, counted by DefectService::summary() and nothing else.
     *
     * Re-deriving these numbers here would be a second answer to "what is still
     * open on this job", and the printed sheet is the one people carry into the
     * handover meeting — so the register quotes the same counts the BAST II
     * gate refuses on, from the same method.
     *
     * All three severities and all five statuses are listed whether or not any
     * row has them: "Kritis : 0" is a fact this register asserts, and a band
     * that changes shape between prints is a band nobody can read at a glance.
     */
    private function defectSummary(Project $project): array
    {
        $summary = app(DefectService::class)->summary($project->id);
        $bySeverity = collect($summary['by_severity'])->keyBy('value');
        $byStatus = collect($summary['by_status'])->keyBy('value');

        $stats = [
            ['label' => 'Total temuan', 'value' => (string) $summary['total']],
            ['label' => 'Masih terbuka', 'value' => (string) $summary['open_count']],
            // The number the BAST II hard block reads: critical + major, open.
            ['label' => 'Penahan BAST II', 'value' => (string) $summary['open_blocking_count']],
            ['label' => 'Lewat tenggat', 'value' => (string) $summary['overdue_count']],
        ];

        if ($summary['oldest_open_code'] !== null) {
            $stats[] = [
                'label' => 'Temuan tertua',
                'value' => $summary['oldest_open_days'].' hari ('.$summary['oldest_open_code'].')',
            ];
        }

        return [
            'asOf' => $summary['as_of'],
            'stats' => $stats,
            'bySeverity' => array_map(fn (DefectSeverity $severity): array => [
                'label' => $this->severityPaperLabel($severity),
                'count' => (int) ($bySeverity[$severity->value]['count'] ?? 0),
            ], DefectSeverity::cases()),
            'byStatus' => array_map(fn (DefectStatus $status): array => [
                'label' => $status->label(),
                'count' => (int) ($byStatus[$status->value]['count'] ?? 0),
            ], DefectStatus::cases()),
        ];
    }

    /**
     * A status that is not a status refuses the print rather than filtering to
     * nothing.
     *
     * An empty register is precisely what somebody hoping to walk past a punch
     * list would like the sheet to say, and "?status=selesai" (a plausible
     * Indonesian guess at a value that is spelled `closed`) would have said it
     * with no warning at all.
     */
    private function defectStatus(mixed $status): ?DefectStatus
    {
        $value = trim((string) ($status ?? ''));

        if ($value === '') {
            return null;
        }

        return DefectStatus::tryFrom($value)
            ?? throw new InvalidArgumentException("Status temuan tidak dikenal: {$value}.");
    }

    /**
     * The word the paper register uses, which is not the word the API uses.
     *
     * DefectSeverity::label() spells the level out — "Kritis (menghentikan
     * fungsi)" — because a dropdown has room to explain itself. A KEPARAHAN
     * column on a twelve-column landscape sheet is 16mm wide, and the owner's
     * own defect list has always said Kritis / Mayor / Minor. Same three
     * levels, one place that shortens them, and the enum stays the authority on
     * what they MEAN: blocksHandover() is still what decides every count.
     */
    private function severityPaperLabel(DefectSeverity $severity): string
    {
        return match ($severity) {
            DefectSeverity::Critical => 'Kritis',
            DefectSeverity::Major => 'Mayor',
            DefectSeverity::Minor => 'Minor',
        };
    }

    private function severityRank(DefectSeverity $severity): int
    {
        return match ($severity) {
            DefectSeverity::Critical => 0,
            DefectSeverity::Major => 1,
            DefectSeverity::Minor => 2,
        };
    }

    /**
     * IZIN KERJA LAPANGAN — P0-C: printed FROM the prj_work_permits row.
     *
     * The blank-pad behaviour this replaces printed an undated letterhead and
     * ruled everything else. A sheet is now one permit: it carries the permit's
     * own number, date, shift and hours, the work asked for, the hazard/APD
     * table from hazard_notes + ppe_required, and the pemohon's name from
     * requested_by. Cells with NO backing column — lokasi/area, jumlah
     * pekerja, the ALAT table — keep their ruled blanks: printed from the
     * database or printed as a rule, never a plausible default.
     */
    private function izinKerja(array $context): array
    {
        $permit = WorkPermit::query()
            ->with(['wbsTask', 'requestedBy', 'safetyOfficer'])
            ->findOrFail($context['id'] ?? null);

        // withTrashed: reprinting the permit of a since-archived project is
        // still reading the site file (the laporanHarian rule).
        $project = $permit->project()->withTrashed()->firstOrFail();

        $options = array_merge($context, [
            'date' => $permit->permit_date,
            'period' => $this->date($permit->permit_date),
        ]);

        $header = $this->header($project, $options);
        $header['signatures'] = [
            // The one column the ERP genuinely knows: who asked. The two
            // supervisory columns keep their blank rules — the wet signature
            // on the filed sheet is that evidence, and the system's own
            // approval trail lives in core_approvals, not in a printed name.
            ['heading' => 'Pemohon,', 'subheading' => null, 'party' => null, 'name' => $permit->requestedBy?->name, 'role' => 'Pelaksana / Mandor'],
            ['heading' => 'Menyetujui,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => 'Pengawas Lapangan'],
            ['heading' => 'Memeriksa,', 'subheading' => null, 'party' => null, 'name' => $permit->safetyOfficer?->name, 'role' => 'Petugas K3'],
        ];

        return $this->document('izin-kerja', $project, $options, [
            'formTitle' => 'IZIN KERJA LAPANGAN',
            'formCode' => 'Form F/IK',
            'header' => $header,
            'permit' => $permit,
            'jam' => $permit->valid_from?->format('H:i').' s/d '.$permit->valid_until?->format('H:i'),
            'hazards' => $this->hazardRows($permit),
            'notes' => null,
            'docControl' => [
                'judul' => 'Izin Kerja Lapangan',
                'no_dok' => $permit->code,
                'no_rev' => null,
                'tanggal' => $this->date($permit->permit_date),
            ],
        ]);
    }

    /**
     * The POTENSI BAHAYA / APD table: hazard_notes split per line into the
     * bahaya column, ppe_required items into the APD column, row-aligned. The
     * PENGENDALIAN column has no backing field and stays a rule on every row.
     *
     * @return list<array{bahaya: ?string, apd: ?string}>
     */
    private function hazardRows(WorkPermit $permit): array
    {
        $hazards = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) $permit->hazard_notes) ?: [],
        ), fn (string $line): bool => $line !== ''));

        $apd = array_values(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $permit->ppe_required ?? [],
        ), fn (string $item): bool => $item !== ''));

        $rows = [];

        for ($i = 0; $i < max(count($hazards), count($apd)); $i++) {
            $rows[] = ['bahaya' => $hazards[$i] ?? null, 'apd' => $apd[$i] ?? null];
        }

        return $rows;
    }

    /**
     * IZIN KERJA LEMBUR — P0-C: printed FROM prj_overtime_permits + its worker
     * rows. One printed line per worker — employee lines with the name and
     * position payroll knows, worker_name lines exactly as typed, because the
     * mandor's non-employee crew is real on paper even though it has no recap
     * row to feed. Remaining rows of the twelve-row block stay ruled for the
     * names decided on site after printing.
     */
    private function izinLembur(array $context): array
    {
        $permit = OvertimePermit::query()
            ->with(['workers.employee'])
            ->findOrFail($context['id'] ?? null);

        $project = $permit->project()->withTrashed()->firstOrFail();

        $options = array_merge($context, [
            'date' => $permit->overtime_date,
            'period' => $this->date($permit->overtime_date),
        ]);

        $header = $this->header($project, $options);
        $header['signatures'] = [
            ['heading' => 'Pemohon,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => 'Pelaksana / Mandor'],
            ['heading' => 'Mengetahui,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => 'Pengawas Lapangan'],
            ['heading' => 'Menyetujui,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => 'Manajer Proyek'],
        ];

        return $this->document('izin-lembur', $project, $options, [
            'formTitle' => 'IZIN KERJA LEMBUR',
            'formCode' => 'Form F/IL',
            'header' => $header,
            'permit' => $permit,
            // end < start crosses midnight (OvertimePermitService's decision);
            // the sheet says so instead of looking like a typo three people sign.
            'jamLembur' => substr((string) $permit->start_time, 0, 5).' s/d '.substr((string) $permit->end_time, 0, 5)
                .($permit->crossesMidnight() ? ' (lewat tengah malam)' : ''),
            'workers' => $permit->workers->map(fn ($worker): array => [
                'name' => $worker->displayName(),
                'position' => $worker->employee?->position,
                'hours' => rtrim(rtrim(number_format((float) $worker->hours, 2, '.', ''), '0'), '.'),
            ])->all(),
            'blankRows' => 12,
            'notes' => null,
            'docControl' => [
                'judul' => 'Izin Kerja Lembur',
                'no_dok' => $permit->code,
                'no_rev' => null,
                'tanggal' => $this->date($permit->overtime_date),
            ],
        ]);
    }

    /**
     * IZIN MASUK / KELUAR MATERIAL & PERALATAN — P0-C: printed FROM
     * prj_gate_passes + its item lines, and the direction box is TICKED from
     * the row: the direction is a recorded fact of the pass now, no longer the
     * computer guessing at a load it never saw.
     *
     * The Diperiksa column prints the name only after the gate's periksa act
     * stamped it — before that the rule stays blank, because "checked" is
     * exactly the claim that column exists to carry.
     */
    private function izinMaterial(array $context): array
    {
        $pass = GatePass::query()
            ->with(['items', 'vendor', 'checkedBy'])
            ->findOrFail($context['id'] ?? null);

        $project = $pass->project()->withTrashed()->firstOrFail();

        $options = array_merge($context, [
            'date' => $pass->pass_date,
            'period' => $this->date($pass->pass_date),
        ]);

        $header = $this->header($project, $options);
        $header['signatures'] = [
            ['heading' => 'Diperiksa,', 'subheading' => null, 'party' => null, 'name' => $pass->checked_at !== null ? $pass->checkedBy?->name : null, 'role' => 'Security / Satpam'],
            ['heading' => 'Menyerahkan / Menerima,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => 'Gudang Proyek'],
            ['heading' => 'Mengetahui,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => 'Pengawas Lapangan'],
        ];

        return $this->document('izin-material', $project, $options, [
            'formTitle' => 'IZIN MASUK / KELUAR MATERIAL & PERALATAN',
            'formCode' => 'Form F/IM',
            'header' => $header,
            'pass' => $pass,
            'counterparty' => $pass->counterpartyName(),
            'reference' => $this->gatePassReference($pass),
            'blankRows' => 10,
            'notes' => null,
            'docControl' => [
                'judul' => 'Izin Masuk / Keluar Material & Peralatan',
                'no_dok' => $pass->code,
                'no_rev' => null,
                'tanggal' => $this->date($pass->pass_date),
            ],
        ]);
    }

    /**
     * NO. SURAT JALAN / REFERENSI: the code of the GRN or transfer the pass
     * escorts. Shared-ID references (no FK), so a pointed-at document that has
     * since vanished simply leaves the rule blank — the pass does not invent a
     * number for paper it cannot find.
     */
    private function gatePassReference(GatePass $pass): ?string
    {
        $codes = array_filter([
            $pass->goods_receipt_id === null ? null : GoodsReceipt::query()->withTrashed()->find($pass->goods_receipt_id)?->code,
            $pass->transfer_id === null ? null : Transfer::query()->withTrashed()->find($pass->transfer_id)?->code,
        ]);

        return $codes === [] ? null : implode(' / ', $codes);
    }

    // ==================================================================
    // THE REGISTRY PATH — every document declared in
    // Modules\Core\Support\PrintableDocuments, rendered through ONE Blade.
    //
    // Nothing below is per-document. A module lane adds an array entry there
    // and the sheet, the button and the endpoint all follow; nobody writes a
    // composer method or a Blade again. The seven bespoke forms above are
    // untouched and stay untouched — laporan mingguan's landscape grid with its
    // two-row grouped header is not a shape a declaration can describe, and
    // pretending otherwise would cost more than the seven methods it saves.
    //
    // The honesty rule survives the generalisation because it is enforced at
    // the only place a value can appear: printed() returns null for anything
    // the record could not answer, and generic.blade.php has exactly two
    // branches — the string, or the ruled blank. There is nowhere for a
    // plausible default to enter.
    // ==================================================================

    /**
     * Which caption sits over the counterparty box, what to call that party
     * underneath a signature rule, and what our own box is called.
     *
     * The four-party band is a PROJECT letterhead: PEMILIK / KONSULTAN MK /
     * PROYEK / KONTRAKTOR. Most of the forty documents are not project
     * documents at all — a purchase order's counterparty is a vendor, a payslip
     * belongs to an employee — and printing "PEMILIK : CV Sinar Abadi" over a
     * supplier would be a letterhead that lies about who the parties are.
     */
    private const PARTY_KINDS = [
        'project' => ['caption' => 'PEMILIK', 'ours' => 'KONTRAKTOR', 'role' => 'Pemilik Proyek'],
        'contract' => ['caption' => 'PEMILIK', 'ours' => 'KONTRAKTOR', 'role' => 'Pemberi Kerja'],
        'customer' => ['caption' => 'PEMILIK', 'ours' => 'KONTRAKTOR', 'role' => 'Pemberi Kerja'],
        'vendor' => ['caption' => 'PEMASOK / VENDOR', 'ours' => 'KONTRAKTOR', 'role' => 'Pemasok / Vendor'],
        'employee' => ['caption' => 'KARYAWAN', 'ours' => 'PERUSAHAAN', 'role' => 'Karyawan'],
        'none' => ['caption' => null, 'ours' => 'PERUSAHAAN', 'role' => null],
    ];

    /**
     * A registry-defined document, composed into the generic sheet.
     *
     * @return array{view: string, data: array}
     */
    private function registryDocument(string $slug, array $context): array
    {
        $definition = app(PrintableDocuments::class)->definition($slug);

        /** @var class-string<Model> $model */
        $model = $definition['model'];

        $record = $model::query()
            ->with($definition['with'])
            ->findOrFail($context['id'] ?? null);

        $company = Company::current();
        [$header, $project] = $this->registryHeader($definition, $record, $context, $company);

        return $this->sheet('generic', [
            // The layout titles the browser tab with it and prints nothing else
            // from it; null is ordinary here — most documents have no project.
            'project' => $project,
            'header' => $header,
            // Through caption(): a plain string prints as written (which every
            // entry but one declares), and the one slug that serves two
            // instruments resolves its title off the record — a berita acara
            // addendum waktu headed PEKERJAAN TAMBAH / KURANG would misname
            // the instrument three parties sign.
            'formTitle' => $this->caption($definition['formTitle'], $record),
            'formCode' => $definition['formCode'],
            'orientation' => $definition['orientation'] === 'landscape' ? 'landscape' : 'portrait',
            'tables' => $this->registryTables($definition, $record),
            'notes' => $this->registryNotes($definition, $record),
            'docControl' => $this->registryDocControl($definition, $record, $header),
        ]);
    }

    /**
     * The band, the identity block and the signatures for a registry document.
     *
     * When the document resolves a PROJECT this delegates wholly to header() —
     * the same band, the same ten contract lines, the same hari-ke arithmetic
     * the seven bespoke forms print. A second implementation of "hari ke" would
     * be a second answer to a question three parties sign against.
     *
     * @return array{0: array<string, mixed>, 1: ?Project}
     */
    private function registryHeader(array $definition, object $record, array $context, ?Company $company): array
    {
        $kind = $definition['header']['kind'] ?? 'none';
        $party = $this->registryParty($definition, $record, $kind);
        $project = $this->registryProject($definition, $record, $kind, $party);

        // The registry's own date column wins over ?tanggal=: an invoice dated
        // 12 Juli is dated 12 Juli whenever it is reprinted, and a URL that
        // could re-date it would make every reprint a different document. A
        // document that declares no date of its own honours the URL, which is
        // what a blank pad wants.
        $date = $this->toDate($this->resolve($definition['date'], $record))
            ?? $this->toDate($context['date'] ?? null)
            ?? Carbon::now()->startOfDay();

        $options = array_filter([
            'date' => $date,
            'period' => $this->printed($definition['period'], $record),
            'pekerjaan' => $this->printed($definition['pekerjaan'], $record),
        ], fn ($value): bool => $value !== null);

        /*
         * A document whose counterparty IS the job gets the shipped four-party
         * band verbatim. A document whose counterparty is a vendor or an
         * employee gets its own band even when it also names a project — a
         * purchase order that printed PEMILIK / KONSULTAN MK / PROYEK and left
         * the supplier off the letterhead would be a letterhead about the wrong
         * parties. It still BORROWS the job's contract facts below, because
         * those are the same facts either way.
         */
        $house = $project instanceof Project ? $this->header($project, $options) : null;
        $projectBand = $house !== null && in_array($kind, ['project', 'contract'], true);

        $header = $projectBand
            ? $house
            : $this->partyHeader($definition, $record, $kind, $party, $project, $company, $date);

        if (! $projectBand && $house !== null) {
            // Counted once, in schedule(), so a purchase order printed for a
            // job can never disagree with that job's laporan harian about which
            // day of the contract it is.
            $header['identity'] = $house['identity'];
            $header['schedule'] = $house['schedule'];
            $header['projectTitle'] = $house['projectTitle'];
            $header['pekerjaan'] ??= $house['pekerjaan'];
            $header['place'] = $house['place'];
            $header['dateLabel'] = $house['dateLabel'];
        }

        // The house identity block is the CONTRACT block — SPK number, waktu
        // pelaksanaan, hari ke. It belongs on a document that hangs off a
        // project and is meaningless on one that does not, so it is on by
        // default exactly when a project resolved.
        $houseIdentity = $definition['identityHouse'] ?? ($project instanceof Project);

        $header['identity'] = array_merge(
            $houseIdentity ? $header['identity'] : [],
            // The sheet's own date travels into the identity resolvers. A line
            // that answers "as at when?" — days remaining, days elapsed — must
            // answer it as at the date PRINTED ON THE SHEET, not as at the
            // moment somebody pressed the button. Without it a contract sheet
            // printed with ?tanggal=2026-01-01 headed itself "01 Januari 2026"
            // and then said the contract had 234 days left, counted from
            // today, for a period that had not started on the date it claimed.
            $this->registryIdentity($definition, $record, $date),
        );

        if ($definition['signatures'] !== 'house') {
            $header['signatures'] = $this->registrySignatures(
                $definition['signatures'],
                $record,
                $company,
                $header['place'] ?? '',
                $header['dateLabel'] ?? '',
            );
        }

        if (($definition['title'] ?? null) !== null) {
            $header['projectTitle'] = mb_strtoupper((string) $this->printed($definition['title'], $record));
        }

        return [$header, $project instanceof Project ? $project : null];
    }

    /** The counterparty model, or null when the document names none. */
    private function registryParty(array $definition, object $record, string $kind): ?object
    {
        $source = $definition['header']['source'] ?? null;

        if ($source !== null) {
            $party = $this->resolve($source, $record);

            return is_object($party) ? $party : null;
        }

        // No source declared: the record IS the party (a Project printing a
        // project document) or carries it under the kind's own name.
        if ($kind === 'project' && $record instanceof Project) {
            return $record;
        }

        $party = $kind === 'none' ? null : data_get($record, $kind);

        return is_object($party) ? $party : null;
    }

    /** The Project behind the sheet, when there is one at all. */
    private function registryProject(array $definition, object $record, string $kind, ?object $party): ?Project
    {
        if ($kind === 'project' && $party instanceof Project) {
            return $party;
        }

        $declared = $definition['header']['project'] ?? null;

        if ($declared === null) {
            return null;
        }

        $project = $this->resolve($declared, $record);

        return $project instanceof Project ? $project : null;
    }

    /**
     * The band for a document whose counterparty is not the owner of a job.
     *
     * Fewer boxes than four, and deliberately. The one that goes first is
     * KONSULTAN MK: it exists on the paper because a construction job has a
     * supervising consultant, and an empty box captioned KONSULTAN MK on a
     * payslip asserts a party that has nothing to do with the document. The
     * PROYEK box appears only when the record really names a project — a blank
     * one on a penawaran would read as a job that exists, and the whole point
     * of a penawaran is that it does not yet. The band's cells are fixed-layout
     * percentages, so two or three boxes fill the width as evenly as four.
     *
     * @return array<string, mixed>
     */
    private function partyHeader(
        array $definition,
        object $record,
        string $kind,
        ?object $party,
        ?Project $project,
        ?Company $company,
        Carbon $date,
    ): array {
        $shape = self::PARTY_KINDS[$kind] ?? self::PARTY_KINDS['none'];
        // name for a customer, vendor, employee or project; title for a
        // contract. Whichever column that party is recognised by on paper.
        $partyName = $party === null
            ? null
            : (string) (data_get($party, 'name') ?: data_get($party, 'title') ?: data_get($party, 'legal_name') ?: '');
        $place = (string) ($project?->city ?: $company?->city ?: '');
        $dateLabel = $this->date($date);

        $parties = [];

        if ($shape['caption'] !== null) {
            $parties[] = [
                'caption' => $shape['caption'],
                'name' => $partyName,
                'meta' => $party === null ? null : (string) (data_get($party, 'code') ?? ''),
                'logo' => null,
            ];
        }

        if ($project !== null) {
            $parties[] = [
                'caption' => 'PROYEK',
                'name' => $project->name,
                'meta' => $project->code,
                'logo' => null,
            ];
        }

        $parties[] = [
            'caption' => $shape['ours'],
            'name' => $company?->legal_name ?: $company?->name ?: config('erp.company.name'),
            'meta' => null,
            'logo' => $this->logo($company),
        ];

        return [
            'parties' => $parties,
            'projectTitle' => mb_strtoupper((string) ($partyName ?? '')),
            'pekerjaan' => $this->printed($definition['pekerjaan'], $record),
            'identity' => [],
            // No project means no start date, no end date and nothing to count
            // from. Every counter is null, which the identity block renders as
            // a ruled blank and which nothing here turns into a zero.
            'schedule' => [
                'dayNo' => null, 'weekNo' => null, 'totalDays' => null,
                'remainingDays' => null, 'remainingLabel' => null,
            ],
            'signatures' => $this->partySignatures($company, $place, $dateLabel),
            'place' => $place,
            'dateLabel' => $dateLabel,
        ];
    }

    /**
     * The default signature block for a document with no project.
     *
     * WIKA's own IK convention — Dibuat / Diperiksa / Mengetahui — because that
     * is what the owner's non-project paperwork already carries, and because
     * all three rules are UNNAMED. core_approvals knows who pressed Setujui in
     * this application; that is not the same claim as "this person signed the
     * document", and printing one under the other would be forging a signature
     * line on a sheet somebody files.
     *
     * @return array<int, array<string, ?string>>
     */
    private function partySignatures(?Company $company, string $place, string $dateLabel): array
    {
        return [
            ['heading' => 'Dibuat,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => null],
            ['heading' => 'Diperiksa,', 'subheading' => null, 'party' => null, 'name' => null, 'role' => null],
            [
                'heading' => trim($place.', '.$dateLabel, ' ,'),
                'subheading' => 'Mengetahui,',
                'party' => $company?->legal_name ?: $company?->name,
                'name' => null,
                'role' => null,
            ],
        ];
    }

    /**
     * The document's own identity lines, in the order the registry wrote them.
     *
     * @return array<int, array{label: string, value: ?string}>
     */
    private function registryIdentity(array $definition, object $record, ?Carbon $date = null): array
    {
        $identity = [];

        foreach ($definition['identity'] as $label => $spec) {
            $identity[] = [
                // The array key is the label, EXCEPT where the record decides
                // what the line is called. One document needs this and the
                // reason is worth the code: a bukti pembayaran serves both
                // directions, and on a receipt the counterparty did not
                // receive the money — they handed it over. "PENERIMA : PT
                // Graha Sentosa" over a receipt for their own payment is a
                // filed voucher stating the opposite of what happened, so the
                // line reads DITERIMA DARI instead. The key stays PENERIMA
                // because that is what the line IS in declaration order; only
                // the printed caption moves.
                'label' => (is_array($spec) && array_key_exists('label', $spec))
                    ? ($this->caption($spec['label'], $record) ?? $label)
                    : $label,
                'value' => $this->printed($spec, $record, [$date]),
            ];
        }

        return $identity;
    }

    /**
     * Every declared body table, resolved to strings and ruled blanks.
     *
     * Two declared tables come out as two entries and render as two bordered
     * tables. The cells are cast HERE rather than in the Blade so that the one
     * place a value can be invented is the one place that refuses to.
     *
     * @return array<int, array<string, mixed>>
     */
    private function registryTables(array $definition, object $record): array
    {
        $tables = [];

        foreach ($definition['body'] as $table) {
            /*
             * 'when' — a VALUE SPEC deciding whether this table belongs on
             * THIS record's sheet at all (P0-B). The layout branch for a slug
             * that serves two instruments: F/BATK prints money tables on a
             * tambah-kurang and the time table on an addendum waktu, and a
             * skipped table leaves no trace — no empty grid asserting columns
             * the instrument does not have. Absent = always printed.
             */
            if (array_key_exists('when', $table) && ! $this->resolve($table['when'], $record)) {
                continue;
            }

            $rows = [];
            $source = $this->resolve($table['rows'] ?? null, $record);

            foreach (($source ?? []) as $index => $row) {
                $cells = [];

                foreach ($table['columns'] as $column) {
                    $cells[] = $this->printed($column, $row, [$index, $record]);
                }

                $rows[] = $cells;
            }

            $totals = [];

            foreach ($table['totals'] ?? [] as $total) {
                $totals[] = [
                    'label' => (string) ($this->caption($total['label'] ?? null, $record) ?? ''),
                    'value' => $this->printed($total, $record),
                ];
            }

            $tables[] = [
                'id' => $table['id'] ?? null,
                'title' => $table['title'] ?? null,
                'columns' => $table['columns'],
                'rows' => $rows,
                // A pad the site fills in by hand: the rows the ERP has, then
                // ruled blanks up to the declared minimum. Never rows of zeros.
                'blanks' => max(0, (int) ($table['minRows'] ?? 0) - count($rows)),
                'totals' => $totals,
                'empty' => $table['empty'] ?? null,
            ];
        }

        return $tables;
    }

    /** The "Catatan :" block, with its text resolved off the record. */
    private function registryNotes(array $definition, object $record): ?array
    {
        $notes = $definition['notes'];

        if ($notes === null) {
            return null;
        }

        return [
            'text' => $this->printed($notes['text'] ?? null, $record),
            'lines' => (int) ($notes['lines'] ?? 3),
            'weather' => $notes['weather'] ?? null,
            'hours' => (bool) ($notes['hours'] ?? false),
        ];
    }

    /** The WIKA IK strip — off unless the document asks for it. */
    private function registryDocControl(array $definition, object $record, array $header): ?array
    {
        $control = $definition['docControl'];

        if ($control === null || $control === false) {
            return null;
        }

        if ($control === true) {
            return [
                'judul' => $definition['label'],
                'no_dok' => $this->printed('code', $record),
                // Nothing in this ERP issues a revision number for a printed
                // form, and "0" would assert one exists.
                'no_rev' => null,
                'tanggal' => $header['dateLabel'] ?? '',
            ];
        }

        return [
            'judul' => $this->caption($control['judul'] ?? null, $record),
            'no_dok' => $this->printed($control['no_dok'] ?? null, $record),
            'no_rev' => $this->printed($control['no_rev'] ?? null, $record),
            'tanggal' => $this->printed($control['tanggal'] ?? null, $record) ?? ($header['dateLabel'] ?? ''),
        ];
    }

    /**
     * Three declared signature columns, resolved.
     *
     * Column three is ours by convention: a null heading becomes the
     * place-and-date line and a null party becomes our legal name, exactly as
     * the house block does it, so a lane writes those two lines only when it
     * wants something else there.
     *
     * @return array<int, array<string, ?string>>
     */
    private function registrySignatures(array $columns, object $record, ?Company $company, string $place, string $dateLabel): array
    {
        $resolved = [];

        foreach (array_values($columns) as $index => $column) {
            $heading = $this->caption($column['heading'] ?? null, $record);
            $party = $this->printed($column['party'] ?? null, $record);

            if ($index === 2) {
                $heading ??= trim($place.', '.$dateLabel, ' ,');
                $party ??= $company?->legal_name ?: $company?->name;
            }

            $resolved[] = [
                'heading' => $heading,
                'subheading' => $this->caption($column['subheading'] ?? null, $record),
                'party' => $party,
                // Filled only from a column that really records who signs.
                // Everywhere else this is null and the rule is left for the pen.
                'name' => $this->printed($column['name'] ?? null, $record),
                'role' => $this->caption($column['role'] ?? null, $record),
            ];
        }

        return $resolved;
    }

    // ------------------------------------------------- the value resolver

    /**
     * A VALUE SPEC's raw answer: a dotted path, a closure, or either wrapped in
     * ['value' => …, 'cast' => …].
     *
     * @param  array<int, mixed>  $extra  extra closure arguments — a body row
     *                                    resolver also receives its index and
     *                                    the parent record
     */
    private function resolve(mixed $spec, mixed $subject, array $extra = []): mixed
    {
        if (is_array($spec)) {
            $spec = $spec['value'] ?? null;
        }

        if ($spec === null) {
            return null;
        }

        if ($spec instanceof \Closure) {
            return $spec($subject, ...$extra);
        }

        return data_get($subject, (string) $spec);
    }

    /**
     * A VALUE SPEC as it appears on the paper — or null, which is a RULED BLANK.
     *
     * This is where the honesty rule is mechanical rather than a habit. Every
     * cell on every registry-defined form comes through here, and there is no
     * branch that produces a value the record did not have: null in, null out;
     * empty string in, null out. A stored 0 is a fact and prints as 0,00, and
     * the difference between that and an unanswered cell is the whole point.
     */
    private function printed(mixed $spec, mixed $subject, array $extra = []): ?string
    {
        $value = $this->resolve($spec, $subject, $extra);

        if ($value === null || $value === '') {
            return null;
        }

        $cast = is_array($spec) ? ($spec['cast'] ?? 'text') : 'text';

        $printed = match ($cast) {
            'date' => $this->date($value),
            'money' => $this->money($value),
            'rupiah' => 'Rp '.$this->money($value),
            'qty' => $this->qty($value),
            'percent' => $this->percent($value),
            'int' => number_format((float) $value, 0, ',', '.'),
            default => $this->text($value),
        };

        return $printed === '' ? null : $printed;
    }

    /**
     * A LABEL on the paper: printed exactly as written.
     *
     * The split between this and printed() is the one thing a registry entry
     * has to get right, and it is the split the paper itself makes. "Menyetujui,"
     * , "Diperiksa,", "Subtotal", "Direktur" are captions PRINTED on the form —
     * they are not columns of anything, and reading them as dotted paths (which
     * is what an earlier draft of this method did) silently prints every one of
     * them as an empty cell: a signature block of three unlabelled rules.
     *
     * A caption that genuinely varies with the record — "PPN 11%", whose rate
     * is stored per document and must never be hard-coded into a template —
     * passes a closure or a ['value' => …] spec and is resolved like any value.
     */
    private function caption(mixed $spec, mixed $subject): ?string
    {
        if (is_string($spec)) {
            return trim($spec) === '' ? null : trim($spec);
        }

        return $this->printed($spec, $subject);
    }

    /**
     * Whatever a plain resolver returned, as the paper spells it.
     *
     * The two automatic cases are the ones that would otherwise be wrong on
     * every form: a date column handed straight to the template prints
     * "2026-09-30 00:00:00", and a backed enum prints "system_integration".
     * Both have one canonical Indonesian rendering already, and this is it.
     */
    private function text(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $this->date($value);
        }

        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? (string) $value->label() : (string) $value->value;
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        return trim((string) $value);
    }

    /** 2 prints as "2", 2,5 as "2,5" — nobody writes 2,0000 on a form. */
    private function qty($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }
}
