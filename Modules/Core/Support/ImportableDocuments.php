<?php

namespace Modules\Core\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Crm\Http\Requests\QuotationStoreRequest;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\QuotationService;
use Modules\Estimation\Http\Requests\AhspStoreRequest;
use Modules\Estimation\Http\Requests\BoqStoreRequest;
use Modules\Estimation\Http\Requests\CostBudgetStoreRequest;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Services\AhspService;
use Modules\Estimation\Services\BoqService;
use Modules\Estimation\Services\RapService;
use Modules\Inventory\Http\Requests\StockAdjustmentStoreRequest;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Projects\Http\Requests\DailyReportStoreRequest;
use Modules\Projects\Http\Requests\ProgressMeasurementStoreRequest;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Services\DailyReportService;
use Modules\Projects\Services\MeasurementService;
use Modules\Quality\Http\Requests\InspectionTemplateStoreRequest;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Services\InspectionTemplateService;
use Modules\Subcontract\Http\Requests\LaborContractStoreRequest;
use Modules\Subcontract\Models\LaborContract;
use Modules\Subcontract\Services\LaborContractService;

/**
 * What can be loaded in bulk when one file carries whole DOCUMENTS, and the
 * shape each of those files must have.
 *
 * ImportableResources is the sibling of this class and covers what is FLAT —
 * one row in the file is one row in the table. Its own comment says AHSP is
 * deliberately absent because "an analysis is a header plus N components, and
 * pretending that fits a flat sheet would silently drop components". This is
 * where AHSP went, together with penawaran, BOQ and RAP: four documents that
 * are a parent plus lines, and one of them (BOQ) a parent plus sections plus
 * lines. Items stayed flat and stayed there — `items` is already importable at
 * core/master-data/items and must not be duplicated here.
 *
 * ONE FILE GRAMMAR COVERS ALL OF THEM. A single header row; a `tipe` column
 * saying what each row IS; a group column so one workbook can carry many
 * documents (mandatory for AHSP — nobody imports one analysis at a time).
 * Row types are declared per resource, `abaikan` is universal and means "this
 * is a subtotal or a spacer, skip it", and a row with any content but no
 * recognised `tipe` is REFUSED rather than skipped: silently skipping a row
 * that carries money is how a BOQ imports 8% short and nobody notices.
 *
 * ------------------------------------------------------------------ the shape
 *
 * Each entry:
 *   label            what the screen calls it
 *   module           display only ('Crm', 'Estimation')
 *   permission       spatie prefix; reading needs .view, importing .create AND
 *                    .update, because an import both creates and updates
 *   model            the parent model — matched by code to decide create/update
 *   code_column      default 'code'
 *   document_type    the config/erp.php `documents` key ('BOQ'), or null when
 *                    the code is the operator's to supply (AHSP). Used ONLY for
 *                    the looks-like-a-document-number test: a group value that
 *                    matches the configured prefix but exists nowhere is
 *                    refused, because one wrong digit in a code you meant to
 *                    update would otherwise silently mint a second document.
 *   group            heading of the group column ('dokumen', 'kode'). Declare it
 *                    as a column of the header row type as well when its value
 *                    is also the stored code — that is AHSP, and only AHSP.
 *   rows             the row types, keyed by the word that goes in `tipe`:
 *                      label     what the template calls it
 *                      role      'header' (exactly one per document)
 *                                | 'group' (a bagian; lines nest under it)
 *                                | 'line'
 *                      aliases   extra words accepted in `tipe`
 *                      relation  payload key AND Eloquent relation. 'group' and
 *                                'line' only; 'sections', 'items', 'components'
 *                      parent    row-type key a line nests under, implicitly:
 *                                the nearest preceding row of that type
 *                      amount    [qtyField, priceField] — the pair whose product
 *                                is the line amount, for the checksum and the
 *                                document total
 *                      columns   see below
 *   request          FormRequest whose rules() validate the assembled payload.
 *                    Only the four Store requests may be instantiated bare;
 *                    AhspUpdateRequest::rules() reads $this->route('ahsp') and
 *                    would fatal. Every registered request is instantiated and
 *                    read by DocumentImportTest so a later $this->input() call
 *                    in one of them breaks a Core test, not a live import.
 *   update_rules     fn (array $rules, object $target): array — the one named
 *                    exception: AHSP updating itself must not trip its own
 *                    Rule::unique on code.
 *   create           fn (array $payload): object
 *   update           fn (object $target, array $payload): object
 *                    Both call the module's own service, never a model, so every
 *                    status guard, recomputeTotals / recalcUnitPrice /
 *                    recalcTotals and wholesale-line-replacement rule applies
 *                    unchanged.
 *   blockers         fn (object $target): array<int,string> — extra refusals
 *                    beyond the status check (a BOQ whose sections other
 *                    documents already point at).
 *   checks           fn (array $payload, ?object $target): array{errors: array,
 *                    warnings: array} — arithmetic a column pair cannot express,
 *                    e.g. an AHSP's stated unit price against its components.
 *                    An error here refuses the document, exactly like a bad line.
 *   source_column    optional — a column on the target table that the ENGINE
 *                    stamps with the uploaded file's name after every landed
 *                    create/update (P8, the legacy importers). The one narrow
 *                    exception to "the importer never touches a model", and
 *                    deliberately so: provenance is the importer's own fact —
 *                    no service can know a file was involved, no screen edits
 *                    it, and the fin_bank_statements.source_format precedent
 *                    says a document born from a file names that file on its
 *                    own row. NULL on the column always means "typed by a
 *                    person on its own screen".
 *   template_notes   extra '# ...' lines for the generated template
 *   template_example rows of the worked example, emitted commented out
 *
 * Each column:
 *   header    the exact column heading in the file (and in the template)
 *   field     where it lands in the payload; omit for a read-only column
 *   required  refuse the row without it. UNCONDITIONALLY required only —
 *             "required unless ahsp_kode is filled" is the FormRequest's job
 *             (required_without), because only the request knows the sibling.
 *   cast      text | int | decimal | bool | date | money | qty | coefficient |
 *             percent. money and qty/coefficient resolve a lone dot by opposite
 *             rules; see SpreadsheetReader::castAmount().
 *   rules     Laravel validation applied to this row alone, with the column
 *             heading as the attribute name. Needed where no FormRequest covers
 *             the lines at all — CostBudgetStoreRequest describes only the RAP
 *             header, so every RAP line rule has to live here.
 *   lookup    [table, column] — resolve a business code to an id. An unknown
 *             code is REFUSED, never silently nulled: a BOQ attached to no
 *             project is a different document.
 *   scope     [column, payloadKey] — narrow a lookup to this document's own
 *             parent, so a RAP line's item_boq resolves inside its own BOQ.
 *   enum      canonical => [synonyms] — 'upah'/'bahan'/'alat' are the words the
 *             AHSP book actually uses
 *   checksum  true: read, never stored, compared against the row type's `amount`
 *   default   value for an empty cell in a column the file CARRIES, and only
 *             while CREATING. It exists for a NOT NULL insert ("diskon kosong =
 *             0"), never to re-set a stored value: a default applied to a column
 *             the sheet does not carry, or to a blank cell on an update, is the
 *             importer writing money the file never mentioned.
 *   blank     'keep' (default) | 'clear' — what a cell that is PRESENT but empty
 *             means on an UPDATE. 'keep' leaves the stored value alone, because
 *             an operator who retypes three rows and leaves the rest blank is
 *             not asking to detach the BOQ from its project. Mark a column
 *             'clear' only where blanking it is an instruction somebody would
 *             recognise as one; the generated template names every such column.
 *
 * ---------------------------------------------------------------- three rules
 *
 * A column the FILE DOES NOT CARRY never writes. A cell that is PRESENT BUT
 * EMPTY writes only what this definition says it means. A cell that is present
 * and unreadable REFUSES its row and therefore its document, and never becomes
 * 0 or null. A BOQ that imports 8% short because a column was missing is the
 * failure this importer exists to prevent.
 */
class ImportableDocuments
{
    /** The word that skips a row, whatever the resource. */
    public const SKIP = 'abaikan';

    /**
     * Accepted alongside it — and deliberately NOT "subtotal" or "rekap".
     *
     * A word that names the row's CONTENT must never be a skip word: a row whose
     * tipe says REKAP and whose last cell says 999.000.000 would then vanish in
     * silence, which is the exact failure `abaikan` exists to make deliberate.
     */
    public const SKIP_ALIASES = ['lewati'];

    /** The discriminator column, always physically first in the template. */
    public const TYPE_COLUMN = 'tipe';

    /**
     * Instance methods, not statics like ImportableResources.
     *
     * The four definitions carry closures into module services, so the engine
     * cannot be exercised without them — and a static registry would force a
     * production backdoor (a register()/fake() the live app can call) purely so
     * a test could install a fixture definition. A container binding is the
     * ordinary Laravel answer and leaves nothing behind in production: see
     * DocumentImportTest, which binds a subclass of this class.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            // ============================================ penawaran (Crm) ===
            'quotations' => [
                'label' => 'Penawaran',
                'module' => 'Crm',
                'permission' => 'crm',
                'model' => Quotation::class,
                'document_type' => 'QTN',
                'group' => 'dokumen',
                'request' => QuotationStoreRequest::class,
                'rows' => [
                    'dokumen' => [
                        'label' => 'Kepala penawaran',
                        'role' => 'header',
                        'columns' => [
                            // Resolved from a code, never accepted as an id: an
                            // unknown pelanggan refuses the penawaran instead of
                            // producing one attached to nobody.
                            ['header' => 'pelanggan_kode', 'field' => 'customer_id', 'required' => true, 'lookup' => ['crm_customers', 'code']],
                            ['header' => 'prospek_kode', 'field' => 'lead_id', 'lookup' => ['crm_leads', 'code']],
                            ['header' => 'judul', 'field' => 'title', 'required' => true],
                            ['header' => 'lingkup', 'field' => 'scope_type', 'required' => true, 'enum' => [
                                'construction' => ['konstruksi', 'sipil'],
                                'system_integration' => ['integrasi', 'integrasi sistem', 'elv', 'ict'],
                                'maintenance' => ['pemeliharaan', 'perawatan'],
                            ]],
                            ['header' => 'berlaku_sampai', 'field' => 'valid_until', 'cast' => 'date'],
                            // crm_quotations.discount_amount and .ppn_rate are
                            // both NOT NULL. Without these defaults an empty cell
                            // reaches the insert as null and the whole penawaran
                            // is refused with an SQLSTATE 23000 nobody can act on;
                            // 0 and the house rate are what the operator meant.
                            ['header' => 'diskon', 'field' => 'discount_amount', 'cast' => 'money', 'default' => 0],
                            ['header' => 'ppn_persen', 'field' => 'ppn_rate', 'cast' => 'percent', 'default' => Erp::float('tax.ppn_rate', 11.0)],
                            ['header' => 'catatan', 'field' => 'notes'],
                        ],
                    ],
                    'item' => [
                        'label' => 'Baris penawaran',
                        'role' => 'line',
                        'relation' => 'items',
                        'amount' => ['qty', 'unit_price'],
                        'columns' => [
                            ['header' => 'uraian', 'field' => 'description', 'required' => true],
                            ['header' => 'volume', 'field' => 'qty', 'required' => true, 'cast' => 'qty'],
                            ['header' => 'satuan', 'field' => 'unit'],
                            ['header' => 'harga_satuan', 'field' => 'unit_price', 'required' => true, 'cast' => 'money'],
                            ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                        ],
                        // line_no is deliberately NOT a column. crm_quotation_items
                        // carries unique(quotation_id, line_no) and syncItems
                        // numbers the lines in file order; letting a sheet supply
                        // the number would turn one duplicated cell into a
                        // constraint violation that refuses the whole penawaran.
                    ],
                ],
                // No `code` column anywhere above: QTN numbers belong to
                // NumberSequence. A code taken from a file is a number the
                // sequence will re-issue months later, onto somebody else's
                // penawaran.
                'create' => fn (array $payload): object => app(QuotationService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(QuotationService::class)->update($target, $payload),
                // Re-pointing an existing penawaran at another pelanggan is
                // legitimate (a draft typed against the wrong customer) but it is
                // also what one mistyped code does, and the contract minted from
                // this penawaran later bills whoever it names. Warned, not
                // refused, and the preview shows both codes.
                'checks' => function (array $payload, ?object $target): array {
                    $wanted = $payload['customer_id'] ?? null;

                    if ($target === null || $wanted === null || (int) $wanted === (int) $target->customer_id) {
                        return ['errors' => [], 'warnings' => []];
                    }

                    $from = DB::table('crm_customers')->where('id', $target->customer_id)->value('code');

                    return ['errors' => [], 'warnings' => [
                        "pelanggan penawaran ini berubah dari {$from}; pastikan itu memang yang dimaksud.",
                    ]];
                },
                'template_notes' => [
                    'kolom dokumen: isi nomor penawaran yang sudah ada untuk MEMPERBARUI,'
                        .' atau label bebas (mis. PNW-GRAHA) untuk MEMBUAT BARU — nomor QTN diberikan sistem, bukan berkas.',
                    'urutan baris item di berkas menjadi nomor barisnya; jangan menambah kolom nomor baris.',
                    'pada penawaran BARU: ppn_persen kosong = tarif PPN yang berlaku di Pengaturan, diskon kosong = 0.'
                        .' Pada penawaran yang diperbarui keduanya tidak berubah bila selnya kosong.',
                    'subtotal, DPP, PPN dan total dihitung ulang oleh sistem dan tidak punya kolom.',
                ],
                'template_example' => [
                    ['tipe' => 'dokumen', 'dokumen' => 'PNW-GRAHA', 'pelanggan_kode' => 'CUST-001',
                        'judul' => 'Upgrade CCTV Gudang Cakung', 'lingkup' => 'integrasi',
                        'berlaku_sampai' => '31/08/2026', 'ppn_persen' => '11'],
                    ['tipe' => 'item', 'dokumen' => 'PNW-GRAHA', 'uraian' => 'Kamera IP dome 4MP',
                        'volume' => '24', 'satuan' => 'unit', 'harga_satuan' => '4.250.000', 'jumlah' => '102.000.000'],
                    ['tipe' => 'item', 'dokumen' => 'PNW-GRAHA', 'uraian' => 'Instalasi dan konfigurasi NVR',
                        'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '18.500.000', 'jumlah' => '18.500.000'],
                ],
            ],

            // ====================================== BOQ / RAB (Estimation) ===
            'boqs' => [
                'label' => 'BOQ / RAB',
                'module' => 'Estimation',
                'permission' => 'est',
                'model' => Boq::class,
                'document_type' => 'BOQ',
                'group' => 'dokumen',
                'request' => BoqStoreRequest::class,
                'rows' => [
                    'dokumen' => [
                        'label' => 'Kepala BOQ',
                        'role' => 'header',
                        'columns' => [
                            ['header' => 'judul', 'field' => 'title', 'required' => true],
                            // BoqStoreRequest deliberately carries no exists rule
                            // on these three, "to keep modules decoupled". The
                            // import resolves a CODE instead, so an unknown one
                            // cannot be silently nulled: a BOQ attached to no
                            // project is a different document, and the RAP,
                            // baseline and variance reports built on it would all
                            // be looking for a link that was never made.
                            ['header' => 'proyek_kode', 'field' => 'project_id', 'lookup' => ['prj_projects', 'code']],
                            ['header' => 'kontrak_kode', 'field' => 'contract_id', 'lookup' => ['crm_contracts', 'code']],
                            ['header' => 'penawaran_kode', 'field' => 'quotation_id', 'lookup' => ['crm_quotations', 'code']],
                            ['header' => 'catatan', 'field' => 'notes'],
                        ],
                    ],
                    'bagian' => [
                        'label' => 'Judul bagian',
                        'role' => 'group',
                        'relation' => 'sections',
                        'columns' => [
                            // cast text, not the raw cell: a workbook hands back
                            // a section numbered 1 as the integer 1 and 2.1 as
                            // the float 2.1, and section_no is a string column
                            // whose 'string' rule an integer fails.
                            ['header' => 'nomor', 'field' => 'section_no', 'required' => true, 'cast' => 'text'],
                            ['header' => 'uraian', 'field' => 'name', 'required' => true],
                        ],
                    ],
                    'item' => [
                        'label' => 'Baris pekerjaan',
                        'role' => 'line',
                        // Implicit nesting: an item joins the nearest bagian
                        // above it, which is how a person reads the sheet. An
                        // item before any bagian is refused, never adopted.
                        'parent' => 'bagian',
                        'relation' => 'items',
                        'amount' => ['qty', 'unit_price'],
                        'columns' => [
                            ['header' => 'nomor', 'field' => 'wbs_code', 'required' => true, 'cast' => 'text'],
                            // uraian / satuan / harga_satuan are NOT marked
                            // required here on purpose: BoqStoreRequest makes
                            // each of them required_without ahsp_id, and only the
                            // request knows about the sibling column. That is
                            // what lets a sheet of nothing but ahsp_kode +
                            // volume import as a fully priced BOQ — addItem
                            // defaults description, unit and price off the
                            // analysis.
                            ['header' => 'uraian', 'field' => 'description'],
                            ['header' => 'ahsp_kode', 'field' => 'ahsp_id', 'lookup' => ['est_ahsp', 'code']],
                            ['header' => 'volume', 'field' => 'qty', 'required' => true, 'cast' => 'qty'],
                            ['header' => 'satuan', 'field' => 'unit'],
                            ['header' => 'harga_satuan', 'field' => 'unit_price', 'cast' => 'money'],
                            // Read, never stored: recalcTotals owns amount,
                            // subtotal and total. It is the estimator's own
                            // arithmetic checking ours, and the only thing that
                            // catches a misread thousands separator.
                            ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                        ],
                    ],
                ],
                'create' => fn (array $payload): object => app(BoqService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(BoqService::class)->update($target, $payload),
                // Replacing a BOQ's sections deletes its items, and four tables
                // outside Estimation point at those items — one of them with
                // cascadeOnDelete. The importer does this 400 lines at a time,
                // so it asks first.
                'blockers' => fn (object $target): array => app(BoqService::class)->dependencyBlockers($target),
                'checks' => fn (array $payload, ?object $target): array => [
                    'errors' => [],
                    'warnings' => array_merge(
                        app(BoqService::class)->duplicateWbsCodes($payload['sections'] ?? []),
                        // computed_total is volume x the price the SHEET carried,
                        // which is null on every line priced from an analysis — so
                        // the preview of an ahsp_kode-only RAB reads Rp 0. This
                        // resolves those prices and restates the number.
                        app(BoqService::class)->analysisPricedWarnings($payload['sections'] ?? []),
                        $target === null ? [] : app(BoqService::class)->dependencyWarnings($target),
                    ),
                ],
                'template_notes' => [
                    'kolom dokumen: isi nomor BOQ yang sudah ada untuk MEMPERBARUI seluruh isinya,'
                        .' atau label bebas (mis. RAB-GRAHA) untuk MEMBUAT BARU — nomor BOQ diberikan sistem, bukan berkas.',
                    'baris item menempel pada baris bagian terdekat di atasnya; baris SUB TOTAL dan REKAPITULASI ditandai abaikan.',
                    'penomoran berjenjang (I / 1.1 / 1.1.1) ditulis apa adanya di kolom nomor:'
                        .' setiap tingkat yang punya rincian sendiri menjadi satu baris bagian.',
                    'nomor bagian maksimal 10 karakter, nomor item maksimal 20 karakter — tidak dipotong, tetapi ditolak.',
                    'baris item boleh hanya berisi nomor + ahsp_kode + volume: uraian, satuan dan harga satuan diambil dari analisa.'
                        .' Bila memakai ahsp_kode tanpa harga_satuan, kosongkan juga kolom jumlah.',
                    'subtotal bagian dan total BOQ dihitung ulang oleh sistem dan tidak punya kolom.',
                ],
                'template_example' => [
                    ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Gedung Kantor Graha Sentosa',
                        'proyek_kode' => 'PRJ-2026-001'],
                    ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
                    ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                        'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
                    ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.2', 'ahsp_kode' => 'A.4.3.1.3',
                        'volume' => '120'],
                ],
            ],

            // ========================================== AHSP (Estimation) ===
            'ahsp' => [
                'label' => 'AHSP / Analisa Harga Satuan',
                'module' => 'Estimation',
                'permission' => 'est',
                'model' => Ahsp::class,
                // No document_type, and that is the deliberate exception to the
                // "a code the file supplies is a code the sequence will re-issue"
                // rule: an analysis code is a domain code the estimator owns
                // (SNI A.2.3.1.1), not a document number, so an unknown one
                // creates rather than being suspected of being a typo.
                'document_type' => null,
                // The group column IS the stored code. One workbook is a price
                // book of two hundred analyses; nobody imports one at a time.
                'group' => 'kode',
                'request' => AhspStoreRequest::class,
                // The one named exception in the whole registry: AhspUpdateRequest
                // cannot be instantiated bare (its rules() read
                // $this->route('ahsp')), so the Store rules are reused with the
                // code column's unique rule taught to ignore the analysis that is
                // updating itself. Without it, re-importing a price book refuses
                // every analysis already in it.
                'update_rules' => function (array $rules, object $target): array {
                    $rules['code'] = ['required', 'string', 'max:40',
                        Rule::unique('est_ahsp', 'code')->ignore($target->id)->whereNull('deleted_at')];

                    return $rules;
                },
                'rows' => [
                    'analisa' => [
                        'label' => 'Kepala analisa',
                        'role' => 'header',
                        'aliases' => ['dokumen'],
                        'columns' => [
                            ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text'],
                            // uraian and satuan serve both row types, which is
                            // exactly the book's own layout: the analysis title
                            // and its component names share one column.
                            ['header' => 'uraian', 'field' => 'name', 'required' => true],
                            ['header' => 'satuan', 'field' => 'unit', 'required' => true],
                            ['header' => 'kategori', 'field' => 'category', 'required' => true, 'enum' => [
                                'sipil' => ['struktur'],
                                'arsitektur' => ['arsitek'],
                                'mep' => ['me', 'mekanikal elektrikal'],
                                'elv' => ['elektronik'],
                                'ict' => ['it', 'jaringan'],
                            ]],
                            // No default: a blank cell means "leave it alone",
                            // and AhspService::update drops it rather than
                            // writing null into a NOT NULL column. Defaulting an
                            // empty cell to 10 would quietly reset a 15% analysis
                            // every time somebody re-imported the book.
                            ['header' => 'overhead_persen', 'field' => 'overhead_pct', 'cast' => 'percent'],
                            ['header' => 'catatan', 'field' => 'notes'],
                            // The book's printed unit price. NEVER stored —
                            // recalcUnitPrice computes est_ahsp.unit_price — but
                            // read so `checks` can compare it against the
                            // components the file carries. The per-line jumlah
                            // checksum catches a misread cell; only this catches
                            // a component row that was never copied at all.
                            ['header' => 'harga_analisa', 'field' => 'unit_price', 'cast' => 'money'],
                        ],
                    ],
                    'komponen' => [
                        'label' => 'Komponen analisa',
                        'role' => 'line',
                        'aliases' => ['item'],
                        'relation' => 'components',
                        // koefisien x harga satuan, the heart of the analysis.
                        'amount' => ['coefficient', 'unit_price'],
                        'columns' => [
                            ['header' => 'uraian', 'field' => 'name', 'required' => true],
                            ['header' => 'satuan', 'field' => 'unit', 'required' => true],
                            ['header' => 'jenis', 'field' => 'component_type', 'required' => true, 'enum' => [
                                // The words the book actually uses, and exactly
                                // what ComponentType::label() prints back.
                                'labor' => ['upah', 'tenaga kerja'],
                                'material' => ['bahan'],
                                'equipment' => ['alat', 'peralatan'],
                            ]],
                            // Optional, and an unknown code refuses the analysis
                            // rather than creating an inventory item: inv_items
                            // .category_id is NOT NULL so auto-creation would have
                            // to invent a category, and "Kawat beton" is an
                            // analysis line name, not a warehouse item. Items are
                            // already importable at Impor Master Data.
                            ['header' => 'item_kode', 'field' => 'item_id', 'lookup' => ['inv_items', 'code']],
                            // cast coefficient, NOT qty: in this column 1.050 is
                            // one-point-nought-five. Reading it as 1050 would
                            // multiply the unit price of every BOQ item using
                            // this analysis by a thousand, and the BOQ would
                            // still foot.
                            ['header' => 'koefisien', 'field' => 'coefficient', 'required' => true, 'cast' => 'coefficient'],
                            ['header' => 'harga_satuan', 'field' => 'unit_price', 'required' => true, 'cast' => 'money'],
                            ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                        ],
                    ],
                ],
                'create' => fn (array $payload): object => app(AhspService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(AhspService::class)->update($target, $payload),
                // $target is handed on because the book-price check has to use the
                // overhead the WRITE will use: a blank overhead_persen cell leaves
                // an existing analysis on its stored rate, so checking against a
                // flat 10% refused correct books and passed incomplete ones.
                'checks' => fn (array $payload, ?object $target): array => [
                    'errors' => app(AhspService::class)->statedPriceBlockers($payload, $target),
                    'warnings' => $target === null ? [] : app(AhspService::class)->inUseWarnings($target),
                ],
                'template_notes' => [
                    'kolom kode adalah kode analisanya sendiri (mis. A.4.3.1.3): kode yang sudah ada DIPERBARUI'
                        .' beserta seluruh komponennya, kode baru DIBUAT. Satu berkas boleh memuat ratusan analisa.',
                    'jenis komponen: upah | bahan | alat.',
                    'koefisien memakai koma sebagai desimal (1,05). Titik di kolom koefisien dibaca sebagai desimal —'
                        .' bukan pemisah ribuan — dan dua titik ditolak.',
                    'item_kode boleh kosong; isi hanya bila komponen memang barang gudang.'
                        .' Impor ini tidak pernah membuat item baru — pakai Impor Master Data untuk itu.',
                    'harga_analisa boleh disalin dari buku analisa. Nilainya TIDAK disimpan, hanya dipakai memeriksa'
                        .' apakah ada baris komponen yang tertinggal; harga satuan analisa dihitung ulang oleh sistem.',
                    'overhead_persen kosong = 10% untuk analisa baru, dan tidak mengubah analisa yang sudah ada.',
                ],
                'template_example' => [
                    ['tipe' => 'analisa', 'kode' => 'A.4.3.1.3', 'uraian' => 'Membuat 1 m3 beton ready mix K-300',
                        'satuan' => 'm3', 'kategori' => 'sipil', 'overhead_persen' => '10', 'harga_analisa' => '1.354.925'],
                    ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Ready Mix K-300', 'satuan' => 'm3',
                        'jenis' => 'bahan', 'item_kode' => 'ITM-0007', 'koefisien' => '1,02',
                        'harga_satuan' => '1.150.000', 'jumlah' => '1.173.000'],
                    ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Tukang cor', 'satuan' => 'OH',
                        'jenis' => 'upah', 'koefisien' => '0,25', 'harga_satuan' => '145.000', 'jumlah' => '36.250'],
                    ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Vibrator beton', 'satuan' => 'jam',
                        'jenis' => 'alat', 'koefisien' => '0,5', 'harga_satuan' => '45.000', 'jumlah' => '22.500'],
                ],
            ],

            // ================================== RAP / cost budget (Estimation) ===
            'cost-budgets' => [
                'label' => 'RAP / Anggaran Pelaksanaan',
                'module' => 'Estimation',
                'permission' => 'est',
                'model' => CostBudget::class,
                'document_type' => 'RAP',
                'group' => 'dokumen',
                'request' => CostBudgetStoreRequest::class,
                'rows' => [
                    'dokumen' => [
                        'label' => 'Kepala RAP',
                        'role' => 'header',
                        'columns' => [
                            // est_cost_budgets.boq_id is a NOT NULL constrained
                            // FK, so a standalone RAP is structurally impossible:
                            // this is required by the schema, not by taste.
                            ['header' => 'boq_kode', 'field' => 'boq_id', 'required' => true, 'lookup' => ['est_boqs', 'code']],
                            ['header' => 'proyek_kode', 'field' => 'project_id', 'lookup' => ['prj_projects', 'code']],
                            ['header' => 'target_margin', 'field' => 'target_margin_pct', 'required' => true, 'cast' => 'percent'],
                            ['header' => 'catatan', 'field' => 'notes'],
                        ],
                    ],
                    'item' => [
                        'label' => 'Baris anggaran',
                        'role' => 'line',
                        'relation' => 'items',
                        'amount' => ['qty', 'unit_price'],
                        'columns' => [
                            // Scoped to this RAP's own BOQ. A.1 means something
                            // different in every bill of quantities in the
                            // system, and est_boq_items.wbs_code is unique to
                            // nothing at the database level — an unscoped lookup
                            // would bind a cost line to another project's work.
                            ['header' => 'item_boq', 'field' => 'boq_item_id', 'required' => true,
                                'lookup' => ['est_boq_items', 'wbs_code'], 'scope' => ['boq_id', 'boq_id']],
                            ['header' => 'kategori', 'field' => 'cost_category', 'required' => true, 'enum' => [
                                'material' => ['bahan'],
                                'labor' => ['upah', 'tenaga kerja'],
                                'subcon' => ['subkon', 'subkontrak'],
                                'equipment' => ['alat', 'peralatan'],
                                'overhead' => ['biaya umum'],
                            ]],
                            // CostBudgetStoreRequest describes the RAP HEADER and
                            // says nothing at all about its lines, so every line
                            // rule has to live here rather than being inherited.
                            ['header' => 'uraian', 'field' => 'description', 'required' => true, 'rules' => ['string', 'max:500']],
                            ['header' => 'volume', 'field' => 'qty', 'required' => true, 'cast' => 'qty', 'rules' => ['numeric', 'min:0.001']],
                            ['header' => 'satuan', 'field' => 'unit', 'required' => true, 'rules' => ['string', 'max:20']],
                            ['header' => 'harga_satuan', 'field' => 'unit_price', 'required' => true, 'cast' => 'money', 'rules' => ['numeric', 'min:0']],
                            ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                        ],
                    ],
                ],
                'create' => function (array $payload): object {
                    $service = app(RapService::class);
                    $budget = $service->create($payload);
                    $service->replaceItems($budget, $payload['items'] ?? []);

                    return $budget;
                },
                'update' => fn (object $target, array $payload): object => app(RapService::class)->update($target, $payload),
                'blockers' => fn (object $target): array => app(RapService::class)->dependencyBlockers($target),
                // A RAP belongs to one BOQ for life. Its lines point at that
                // BOQ's items and prj_baselines resolves the RAP by project, so
                // a mistyped boq_kode on an update row would leave a budget
                // whose every variance is measured against a different bill of
                // quantities. RapService::update will not fill boq_id; this is
                // what tells the operator why instead of ignoring the cell.
                'checks' => function (array $payload, ?object $target): array {
                    $wanted = $payload['boq_id'] ?? null;

                    if ($target === null || $wanted === null || (int) $wanted === (int) $target->boq_id) {
                        return ['errors' => [], 'warnings' => []];
                    }

                    $current = DB::table('est_boqs')->where('id', $target->boq_id)->value('code');

                    return ['errors' => [
                        "boq_kode: RAP {$target->code} milik {$current} dan tidak dapat dipindahkan ke BOQ lain;"
                            .' buat RAP baru untuk BOQ tersebut.',
                    ], 'warnings' => []];
                },
                'template_notes' => [
                    'kolom dokumen: isi nomor RAP yang sudah ada untuk MEMPERBARUI,'
                        .' atau label bebas (mis. RAP-GRAHA) untuk MEMBUAT BARU — nomor RAP diberikan sistem, bukan berkas.',
                    'boq_kode wajib dan tidak dapat diubah saat memperbarui: satu RAP selalu milik satu BOQ.',
                    'item_boq diisi nomor (WBS) baris BOQ yang dianggarkan; beberapa baris RAP boleh menunjuk baris BOQ yang sama.',
                    'proyek_kode kosong = mengikuti proyek BOQ-nya.',
                    'kategori: material | upah | subkon | alat | overhead.',
                ],
                'template_example' => [
                    ['tipe' => 'dokumen', 'dokumen' => 'RAP-GRAHA', 'boq_kode' => 'BOQ/2026/0002',
                        'proyek_kode' => 'PRJ-2026-001', 'target_margin' => '12'],
                    ['tipe' => 'item', 'dokumen' => 'RAP-GRAHA', 'item_boq' => 'A.1', 'kategori' => 'upah',
                        'uraian' => 'Pembersihan lahan - tenaga', 'volume' => '1.500', 'satuan' => 'm2',
                        'harga_satuan' => '4.200', 'jumlah' => '6.300.000'],
                    ['tipe' => 'item', 'dokumen' => 'RAP-GRAHA', 'item_boq' => 'A.1', 'kategori' => 'alat',
                        'uraian' => 'Pembersihan lahan - excavator', 'volume' => '1.500', 'satuan' => 'm2',
                        'harga_satuan' => '2.800', 'jumlah' => '4.200.000'],
                ],
            ],

            // ============================ template inspeksi mutu (Quality) ===
            'inspection-templates' => [
                'label' => 'Template Inspeksi Mutu',
                'module' => 'Quality',
                'permission' => 'qc',
                'model' => InspectionTemplate::class,
                // No document_type — the AHSP exception applies: Q1..Q31 is a
                // catalogue code the quality office owns, not a document number
                // the sequence mints, so an unknown code CREATES rather than
                // being suspected of being a typo.
                'document_type' => null,
                // The group column IS the stored code. One workbook is a whole
                // checklist library; nobody imports one template at a time.
                'group' => 'kode',
                'request' => InspectionTemplateStoreRequest::class,
                // Re-importing the library must not have every template refuse
                // itself on its own code uniqueness — the AHSP move, exactly.
                'update_rules' => function (array $rules, object $target): array {
                    $rules['code'] = ['required', 'string', 'max:20',
                        Rule::unique('qc_inspection_templates', 'code')->ignore($target->id)->whereNull('deleted_at')];

                    return $rules;
                },
                'rows' => [
                    'template' => [
                        'label' => 'Kepala template',
                        'role' => 'header',
                        'aliases' => ['dokumen'],
                        'columns' => [
                            ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text'],
                            ['header' => 'paket', 'field' => 'work_package', 'required' => true],
                            ['header' => 'tahap', 'field' => 'stage', 'required' => true, 'enum' => [
                                'before' => ['sebelum', 'pra', 'persiapan'],
                                'during' => ['saat', 'pelaksanaan', 'proses'],
                                'after' => ['setelah', 'pasca', 'selesai'],
                            ]],
                        ],
                    ],
                    'butir' => [
                        'label' => 'Butir pemeriksaan',
                        'role' => 'line',
                        'aliases' => ['item'],
                        'relation' => 'items',
                        'columns' => [
                            ['header' => 'butir', 'field' => 'check_text', 'required' => true],
                            ['header' => 'kriteria', 'field' => 'acceptance', 'required' => true],
                            ['header' => 'toleransi', 'field' => 'tolerance'],
                        ],
                    ],
                ],
                'create' => fn (array $payload): object => app(InspectionTemplateService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(InspectionTemplateService::class)->update($target, $payload),
                'template_notes' => [
                    'kolom kode adalah nomor katalog checklist-nya sendiri (mis. Q7): kode yang sudah ada DIPERBARUI'
                        .' beserta seluruh butirnya, kode baru DIBUAT. Satu berkas boleh memuat seluruh pustaka checklist.',
                    'tahap: sebelum | saat | setelah (before/during/after) — menentukan titik henti mutunya.',
                    'setiap baris butir menempel pada template terdekat di atasnya; toleransi boleh kosong.',
                ],
                'template_example' => [
                    ['tipe' => 'template', 'kode' => 'Q7', 'paket' => 'Pengecoran kolom struktur', 'tahap' => 'sebelum'],
                    ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Kebersihan bekisting dan tulangan',
                        'kriteria' => 'Bebas kotoran, minyak, dan karat lepas', 'toleransi' => '-'],
                    ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Selimut beton (beton decking)',
                        'kriteria' => 'Sesuai gambar', 'toleransi' => '± 5 mm'],
                    ['tipe' => 'butir', 'kode' => 'Q7', 'butir' => 'Slump beton di lapangan',
                        'kriteria' => '12 ± 2 cm', 'toleransi' => '± 2 cm'],
                ],
            ],

            /*
             * ============================================================
             * EMPAT IMPORTER WARISAN (P8, kriteria #10, D12).
             *
             * Layout korpus XLS pemilik dipetakan ke tata bahasa berkas yang
             * sama dengan entri lain — kolom `tipe`, kolom grup `dokumen` —
             * dan pemetaan kolom lembar ASLI ke kolom template ini
             * didokumentasikan di docs/IMPOR-WARISAN.md, satu bagian per
             * importer.
             *
             * Aturan rumah berlaku dobel untuk data warisan:
             *
             * FORWARD-ONLY. Keempatnya mendarat lewat service modulnya dalam
             * status DRAFT (laporan harian: baris hidup biasa yang memang
             * tidak pernah memposting apa pun), sehingga TIDAK ada jurnal,
             * mutasi stok, atau tagihan yang lahir dari sebuah unggahan.
             * Kartu stok warisan TIDAK memutar ulang baris mutasinya — hanya
             * saldo penutupnya yang menjadi qty hitung sebuah opname draft;
             * lembar Opname/SP3 warisan mendaratkan SP3 Induknya saja —
             * kolom opname kumulatifnya tinggal di kertas, karena opname
             * baru harus disusun atas SP3 hidup yang disetujui.
             *
             * PENANDA SUMBER. source_column menyuruh mesin mencap nama
             * berkas warisan pada dokumen yang mendarat (import_source),
             * jadi enam bulan lagi masih terbaca baris mana yang lahir dari
             * migrasi dan dari berkas apa.
             * ============================================================
             */

            // ================================ laporan harian (Projects) ===
            'daily-reports' => [
                'label' => 'Laporan Harian (warisan)',
                'module' => 'Projects',
                'permission' => 'prj',
                'model' => DailyReport::class,
                'document_type' => 'DRP',
                'group' => 'dokumen',
                'request' => DailyReportStoreRequest::class,
                'source_column' => 'import_source',
                'rows' => [
                    'laporan' => [
                        'label' => 'Kepala laporan harian',
                        'role' => 'header',
                        'aliases' => ['dokumen'],
                        'columns' => [
                            ['header' => 'proyek_kode', 'field' => 'project_id', 'required' => true, 'lookup' => ['prj_projects', 'code']],
                            ['header' => 'tanggal', 'field' => 'report_date', 'required' => true, 'cast' => 'date'],
                            ['header' => 'cuaca_pagi', 'field' => 'weather_am', 'enum' => [
                                'cerah' => ['panas', 'terang'],
                                'mendung' => ['berawan'],
                                'hujan' => ['gerimis', 'hujan deras'],
                            ]],
                            ['header' => 'cuaca_sore', 'field' => 'weather_pm', 'enum' => [
                                'cerah' => ['panas', 'terang'],
                                'mendung' => ['berawan'],
                                'hujan' => ['gerimis', 'hujan deras'],
                            ]],
                            // Jam sebagai teks HH:MM — date_format:H:i milik
                            // request yang menolaknya bila bukan jam.
                            ['header' => 'jam_mulai', 'field' => 'work_start', 'cast' => 'text'],
                            ['header' => 'jam_selesai', 'field' => 'work_end', 'cast' => 'text'],
                            ['header' => 'alasan_jam_hilang', 'field' => 'lost_hours_reason'],
                            // Angka lembar lama TANPA rincian per jabatan.
                            // Begitu baris `tenaga` ikut di berkas, service
                            // MENURUNKAN angkanya dan angka manual yang
                            // menyimpang ditolak 422 — aturan P0-A, tidak
                            // dilonggarkan untuk data warisan.
                            ['header' => 'jumlah_tenaga', 'field' => 'manpower_count', 'cast' => 'int'],
                            ['header' => 'kegiatan', 'field' => 'activities', 'required' => true],
                            ['header' => 'kendala', 'field' => 'obstacles'],
                            ['header' => 'catatan_k3', 'field' => 'safety_notes'],
                        ],
                    ],
                    'tenaga' => [
                        'label' => 'Rincian tenaga kerja per jabatan',
                        'role' => 'line',
                        'relation' => 'manpower',
                        'columns' => [
                            ['header' => 'jabatan', 'field' => 'role_key', 'required' => true, 'enum' => [
                                // Kata-kata pad FM-10-12, persis label enumnya.
                                'project_manager' => ['manajer proyek', 'pm'],
                                'deputy_project_manager' => ['wakil manajer proyek', 'deputy pm'],
                                'engineering' => ['teknik'],
                                'komersial' => ['commercial'],
                                'keuangan' => ['finance'],
                                'danlat' => ['komandan peralatan'],
                                'produksi' => ['production'],
                                'safety_officer' => ['petugas k3', 'hse'],
                                'mandor_sipil' => ['mandor sipil'],
                                'mandor_arsitek' => ['mandor arsitek'],
                                'mandor_mep' => ['mandor me', 'mandor mep'],
                                'subkont' => ['subkon', 'subkontraktor'],
                            ]],
                            ['header' => 'jumlah_orang', 'field' => 'headcount', 'required' => true, 'cast' => 'int'],
                            ['header' => 'keterangan', 'field' => 'notes'],
                        ],
                    ],
                    'alat' => [
                        'label' => 'Alat yang beroperasi',
                        'role' => 'line',
                        'relation' => 'equipment',
                        'columns' => [
                            ['header' => 'uraian', 'field' => 'description', 'required' => true],
                            ['header' => 'jumlah_alat', 'field' => 'qty', 'required' => true, 'cast' => 'int'],
                            ['header' => 'jam_operasi', 'field' => 'hours', 'cast' => 'decimal'],
                        ],
                    ],
                    'material_masuk' => [
                        'label' => 'Material yang masuk hari itu',
                        'role' => 'line',
                        'relation' => 'receipts',
                        'columns' => [
                            ['header' => 'uraian', 'field' => 'description', 'required' => true],
                            ['header' => 'volume_diterima', 'field' => 'qty_received', 'required' => true, 'cast' => 'qty'],
                            ['header' => 'volume_ditolak', 'field' => 'qty_rejected', 'cast' => 'qty'],
                            ['header' => 'satuan', 'field' => 'unit', 'required' => true],
                            ['header' => 'alasan_tolak', 'field' => 'rejection_reason'],
                        ],
                    ],
                    'material_dipakai' => [
                        'label' => 'Material yang dipakai (dari gudang)',
                        'role' => 'line',
                        'relation' => 'materials',
                        'columns' => [
                            // Item gudang dicari per kode dan yang tak dikenal
                            // MENOLAK barisnya — pemakaian material warisan
                            // yang menempel ke item yang salah akan mengotori
                            // varian material selamanya.
                            ['header' => 'item_kode', 'field' => 'item_id', 'required' => true, 'lookup' => ['inv_items', 'code']],
                            ['header' => 'volume', 'field' => 'qty_used', 'required' => true, 'cast' => 'qty'],
                            ['header' => 'satuan', 'field' => 'unit', 'required' => true],
                        ],
                    ],
                ],
                'create' => fn (array $payload): object => app(DailyReportService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(DailyReportService::class)->update($target, $payload),
                // Satu laporan per (proyek, tanggal) — indeks uniknya ada,
                // tetapi jawaban indeks adalah SQLSTATE, dan
                // UniqueDailyReportDate pada request yang di-instantiate
                // telanjang tidak melihat project_id payload. Ini yang membuat
                // penolakannya menyebut laporan yang sudah ada, dengan kode
                // dan tanggalnya.
                'checks' => function (array $payload, ?object $target): array {
                    $projectId = $payload['project_id'] ?? null;
                    $date = $payload['report_date'] ?? null;

                    if ($projectId === null || $date === null) {
                        return ['errors' => [], 'warnings' => []];
                    }

                    $existing = DB::table('prj_daily_reports')
                        ->where('project_id', (int) $projectId)
                        ->whereDate('report_date', (string) $date)
                        ->whereNull('deleted_at')
                        ->when($target !== null, fn ($query) => $query->where('id', '!=', $target->id))
                        ->first(['code', 'report_date']);

                    if ($existing === null) {
                        return ['errors' => [], 'warnings' => []];
                    }

                    return ['errors' => [sprintf(
                        'tanggal: sudah ada laporan harian %s untuk proyek ini pada %s; '
                        .'isi kolom dokumen dengan kode itu bila memang ingin memperbaruinya.',
                        $existing->code,
                        Carbon::parse($existing->report_date)->format('d-m-Y'),
                    )], 'warnings' => []];
                },
                'template_notes' => [
                    'kolom dokumen: isi nomor DRP yang sudah ada untuk MEMPERBARUI, atau label bebas'
                        .' (mis. LH-GRAHA-0301) untuk MEMBUAT BARU — nomor DRP diberikan sistem, bukan berkas.',
                    'jumlah_tenaga hanya untuk lembar lama tanpa rincian per jabatan; begitu ada baris tenaga,'
                        .' angkanya diturunkan dari rincian dan angka manual yang berbeda ditolak.',
                    'material_dipakai menunjuk item gudang per kode (Impor Master Data untuk membuat item);'
                        .' impor ini TIDAK memotong stok — bon gudang tetap dokumennya sendiri.',
                    'pemetaan kolom lembar warisan ke template ini: docs/IMPOR-WARISAN.md §1.',
                ],
                'template_example' => [
                    ['tipe' => 'laporan', 'dokumen' => 'LH-GRAHA-0301', 'proyek_kode' => 'PRJ-2026-001',
                        'tanggal' => '01/03/2026', 'cuaca_pagi' => 'cerah', 'cuaca_sore' => 'hujan',
                        'jam_mulai' => '08:00', 'jam_selesai' => '17:00', 'kegiatan' => 'Pengecoran kolom lantai 2'],
                    ['tipe' => 'tenaga', 'dokumen' => 'LH-GRAHA-0301', 'jabatan' => 'mandor sipil', 'jumlah_orang' => '12'],
                    ['tipe' => 'material_masuk', 'dokumen' => 'LH-GRAHA-0301', 'uraian' => 'Besi beton D16',
                        'volume_diterima' => '2.000', 'volume_ditolak' => '0', 'satuan' => 'kg'],
                ],
            ],

            // ================================== kartu stok (Inventory) ===
            'stock-cards' => [
                'label' => 'Kartu Stok (warisan)',
                'module' => 'Inventory',
                'permission' => 'inv',
                'model' => StockAdjustment::class,
                'document_type' => 'ADJ',
                'group' => 'dokumen',
                'request' => StockAdjustmentStoreRequest::class,
                'source_column' => 'import_source',
                'rows' => [
                    'kartu' => [
                        'label' => 'Kepala opname (satu gudang, satu tanggal)',
                        'role' => 'header',
                        'aliases' => ['dokumen'],
                        'columns' => [
                            ['header' => 'gudang_kode', 'field' => 'warehouse_id', 'required' => true, 'lookup' => ['inv_warehouses', 'code']],
                            ['header' => 'tanggal', 'field' => 'adjustment_date', 'required' => true, 'cast' => 'date'],
                            // Kosong = opname: saldo penutup kartu stok adalah
                            // hitungan fisik menurut kartunya.
                            ['header' => 'alasan', 'field' => 'reason', 'default' => 'opname', 'enum' => [
                                'opname' => ['stock opname', 'hitung fisik'],
                                'damage' => ['rusak', 'barang rusak'],
                                'loss' => ['hilang', 'barang hilang'],
                            ]],
                            ['header' => 'catatan', 'field' => 'notes'],
                        ],
                    ],
                    'saldo' => [
                        'label' => 'Saldo penutup per item',
                        'role' => 'line',
                        'relation' => 'items',
                        'columns' => [
                            ['header' => 'item_kode', 'field' => 'item_id', 'required' => true, 'lookup' => ['inv_items', 'code']],
                            ['header' => 'saldo_akhir', 'field' => 'counted_qty', 'required' => true, 'cast' => 'qty'],
                        ],
                    ],
                ],
                'create' => fn (array $payload): object => app(StockAdjustmentService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(StockAdjustmentService::class)->update($target, $payload),
                'template_notes' => [
                    'yang diimpor adalah SALDO PENUTUP kartu — baris mutasi masuk/keluar kartu lama TIDAK diputar'
                        .' ulang; sejarah pergerakannya tinggal di kertas (forward-only).',
                    'dokumen mendarat sebagai stock opname DRAFT: stok dan jurnal baru bergerak saat opname itu'
                        .' disetujui dan diposting orang dari layarnya sendiri.',
                    'alasan kosong = opname. satu kartu = satu gudang + satu tanggal saldo.',
                    'pemetaan kolom kartu warisan ke template ini: docs/IMPOR-WARISAN.md §2.',
                ],
                'template_example' => [
                    ['tipe' => 'kartu', 'dokumen' => 'KS-GUDANG-UTAMA', 'gudang_kode' => 'WH-01',
                        'tanggal' => '30/06/2026', 'catatan' => 'Saldo penutup kartu stok manual Juni 2026'],
                    ['tipe' => 'saldo', 'dokumen' => 'KS-GUDANG-UTAMA', 'item_kode' => 'ITM-0001', 'saldo_akhir' => '150'],
                    ['tipe' => 'saldo', 'dokumen' => 'KS-GUDANG-UTAMA', 'item_kode' => 'ITM-0002', 'saldo_akhir' => '80,5'],
                ],
            ],

            // ============================ Opname/SP3 mandor (Subcontract) ===
            'sp3' => [
                'label' => 'SP3 Induk — lembar Opname/SP3 (warisan)',
                'module' => 'Subcontract',
                'permission' => 'scm',
                'model' => LaborContract::class,
                'document_type' => 'SP3',
                'group' => 'dokumen',
                'request' => LaborContractStoreRequest::class,
                'source_column' => 'import_source',
                'rows' => [
                    'sp3' => [
                        'label' => 'Kepala SP3 Induk',
                        'role' => 'header',
                        'aliases' => ['dokumen'],
                        'columns' => [
                            ['header' => 'mandor_kode', 'field' => 'vendor_id', 'required' => true, 'lookup' => ['prc_vendors', 'code']],
                            ['header' => 'proyek_kode', 'field' => 'project_id', 'required' => true, 'lookup' => ['prj_projects', 'code']],
                            ['header' => 'judul', 'field' => 'title', 'required' => true],
                            // Kosong = PPh final UMKM (asumsi #3). pph21_ter
                            // valid secara bentuk; service yang menolaknya
                            // "belum diaktifkan" — pintu jujur yang sama
                            // dengan layar.
                            ['header' => 'skema_pph', 'field' => 'pph_scheme', 'default' => 'final_umkm', 'enum' => [
                                'final_umkm' => ['pph final', 'final umkm', 'umkm', 'final'],
                                'pph21_ter' => ['pph 21', 'pph21', 'ter'],
                            ]],
                            ['header' => 'tanggal_mulai', 'field' => 'start_date', 'cast' => 'date'],
                            ['header' => 'tanggal_selesai', 'field' => 'end_date', 'cast' => 'date'],
                            ['header' => 'catatan', 'field' => 'notes'],
                            // Gate prakualifikasi K3L/pakta berlaku juga untuk
                            // impor warisan; kolom ini jalan daruratnya yang
                            // TERCATAT, bukan pintu belakang — alasan kosong
                            // pada mandor yang belum lolos gate menolak
                            // dokumennya dengan kalimat gate itu sendiri.
                            ['header' => 'alasan_override_kualifikasi', 'field' => 'qualification_override_reason'],
                        ],
                    ],
                    'item' => [
                        'label' => 'Baris upah borongan',
                        'role' => 'line',
                        'relation' => 'items',
                        'amount' => ['qty', 'unit_rate'],
                        'columns' => [
                            ['header' => 'uraian', 'field' => 'description', 'required' => true],
                            ['header' => 'wbs', 'field' => 'wbs_code', 'cast' => 'text'],
                            ['header' => 'volume', 'field' => 'qty', 'required' => true, 'cast' => 'qty'],
                            ['header' => 'satuan', 'field' => 'unit'],
                            ['header' => 'tarif_upah', 'field' => 'unit_rate', 'required' => true, 'cast' => 'money'],
                            ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                        ],
                    ],
                ],
                'create' => fn (array $payload): object => app(LaborContractService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(LaborContractService::class)->update($target, $payload),
                'template_notes' => [
                    'lembar warisan "Opname/SP3" memuat dua hal; yang diimpor hanya SP3 INDUKNYA (baris volume x'
                        .' tarif). Kolom opname kumulatif TIDAK diimpor: opname mandor baru disusun di aplikasi atas'
                        .' SP3 yang sudah disetujui, supaya plafon volumenya hidup (forward-only).',
                    'mandor dicari per kode vendor (vendor bertipe mandor); nilai SP3, tarif PPN dan snapshot tarif'
                        .' PPh dihitung service — berkas tidak membawa kolomnya.',
                    'skema_pph kosong = PPh final UMKM (PP 55/2022).',
                    'pemetaan kolom lembar warisan ke template ini: docs/IMPOR-WARISAN.md §3.',
                ],
                'template_example' => [
                    ['tipe' => 'sp3', 'dokumen' => 'SP3-BUDI-01', 'mandor_kode' => 'VND-0001',
                        'proyek_kode' => 'PRJ-2026-001', 'judul' => 'Upah borongan pembesian tower A',
                        'skema_pph' => 'pph final'],
                    ['tipe' => 'item', 'dokumen' => 'SP3-BUDI-01', 'uraian' => 'Pembesian kolom', 'wbs' => 'B.1',
                        'volume' => '120', 'satuan' => 'kg', 'tarif_upah' => '1.500', 'jumlah' => '180.000'],
                ],
            ],

            // ========================= progress pay / opname OPN (Projects) ===
            'progress-pay' => [
                'label' => 'Progress Payment / Opname ke Pemilik (warisan)',
                'module' => 'Projects',
                'permission' => 'prj',
                'model' => ProgressMeasurement::class,
                'document_type' => 'OPN',
                'group' => 'dokumen',
                'request' => ProgressMeasurementStoreRequest::class,
                'source_column' => 'import_source',
                'rows' => [
                    'opname' => [
                        'label' => 'Kepala opname progres',
                        'role' => 'header',
                        'aliases' => ['dokumen'],
                        'columns' => [
                            ['header' => 'proyek_kode', 'field' => 'project_id', 'required' => true, 'lookup' => ['prj_projects', 'code']],
                            // Jangkar scope untuk pencarian item_boq di bawah —
                            // A.1 berarti hal berbeda di setiap BOQ (pelajaran
                            // entri RAP). MeasurementService tetap menegakkan
                            // bahwa item milik BOQ kontrak proyeknya; boq_kode
                            // yang salah ditolak dengan kalimat service.
                            ['header' => 'boq_kode', 'field' => 'boq_id', 'required' => true, 'lookup' => ['est_boqs', 'code']],
                            ['header' => 'periode_mulai', 'field' => 'period_start', 'required' => true, 'cast' => 'date'],
                            ['header' => 'periode_selesai', 'field' => 'period_end', 'required' => true, 'cast' => 'date'],
                            ['header' => 'catatan', 'field' => 'notes'],
                        ],
                    ],
                    'item' => [
                        'label' => 'Volume periode ini per item BOQ',
                        'role' => 'line',
                        'relation' => 'items',
                        'columns' => [
                            ['header' => 'item_boq', 'field' => 'boq_item_id', 'required' => true,
                                'lookup' => ['est_boq_items', 'wbs_code'], 'scope' => ['boq_id', 'boq_id']],
                            ['header' => 'volume_ini', 'field' => 'qty_this', 'required' => true, 'cast' => 'qty'],
                            ['header' => 'keterangan', 'field' => 'notes'],
                        ],
                    ],
                ],
                'create' => fn (array $payload): object => app(MeasurementService::class)->create($payload),
                'update' => fn (object $target, array $payload): object => app(MeasurementService::class)->update($target, $payload),
                'template_notes' => [
                    'lembar Progress Payment warisan dipetakan ke opname progres (OPN): hanya VOLUME PERIODE INI'
                        .' per item BOQ yang dibawa berkas — kumulatif s/d lalu, harga, dan nilai tagihan dihitung'
                        .' service dari riwayat approved dan harga kontrak, bukan dari kertas.',
                    'dokumen mendarat DRAFT; tagihan ke pemilik baru bisa lahir setelah opname disetujui.',
                    'volume periode boleh negatif (opname koreksi); kumulatif tidak boleh menjadi negatif.',
                    'pemetaan kolom lembar warisan ke template ini: docs/IMPOR-WARISAN.md §4.',
                ],
                'template_example' => [
                    ['tipe' => 'opname', 'dokumen' => 'OPN-JUNI', 'proyek_kode' => 'PRJ-2026-001',
                        'boq_kode' => 'BOQ/2026/0001', 'periode_mulai' => '01/06/2026', 'periode_selesai' => '30/06/2026'],
                    ['tipe' => 'item', 'dokumen' => 'OPN-JUNI', 'item_boq' => 'A.1', 'volume_ini' => '250'],
                ],
            ],

        ];
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function definition(string $resource): array
    {
        $all = $this->all();

        if (! isset($all[$resource])) {
            throw new InvalidArgumentException("Jenis dokumen tidak dikenal: {$resource}.");
        }

        return $this->normalise($all[$resource] + ['key' => $resource]);
    }

    /**
     * Reading a template or an export needs the module's VIEW.
     */
    public function mayRead(?Authorizable $user, array $definition): bool
    {
        return (bool) $user?->can("{$definition['permission']}.view");
    }

    /**
     * Importing needs CREATE **and** UPDATE.
     *
     * An import that matches on code updates existing documents as well as
     * creating new ones, so somebody who may only create must not be able to
     * rewrite an estimator's BOQ by uploading a sheet. approve is never required
     * and never granted: nothing this engine does can approve a document.
     */
    public function mayImport(?Authorizable $user, array $definition): bool
    {
        return (bool) $user?->can("{$definition['permission']}.create")
            && (bool) $user->can("{$definition['permission']}.update");
    }

    /**
     * Fill in the optional keys once, so the engine never has to ?? its way
     * through a definition and a malformed entry fails here with its own name
     * rather than as an undefined index three files away.
     */
    private function normalise(array $definition): array
    {
        foreach (['label', 'permission', 'model', 'group', 'rows', 'create', 'update'] as $required) {
            if (! isset($definition[$required])) {
                throw new InvalidArgumentException(
                    "Definisi impor dokumen \"{$definition['key']}\" tidak memiliki \"{$required}\".",
                );
            }
        }

        $definition += [
            'module' => '',
            'code_column' => 'code',
            'document_type' => null,
            'request' => null,
            'update_rules' => null,
            'blockers' => null,
            'checks' => null,
            'source_column' => null,
            'template_notes' => [],
            'template_example' => [],
        ];

        $headers = 0;

        foreach ($definition['rows'] as $type => $row) {
            $row += ['label' => $type, 'aliases' => [], 'relation' => null, 'parent' => null, 'amount' => null, 'columns' => []];

            if (! in_array($row['role'] ?? '', ['header', 'group', 'line'], true)) {
                throw new InvalidArgumentException("Baris \"{$type}\" harus berperan header, group atau line.");
            }

            $headers += $row['role'] === 'header' ? 1 : 0;

            if ($row['role'] !== 'header' && $row['relation'] === null) {
                throw new InvalidArgumentException("Baris \"{$type}\" harus menyebut relation.");
            }

            if ($row['parent'] !== null && ! isset($definition['rows'][$row['parent']])) {
                throw new InvalidArgumentException("Baris \"{$type}\" menunjuk induk \"{$row['parent']}\" yang tidak ada.");
            }

            foreach ($row['columns'] as $column) {
                // A misspelt flag would read as 'keep' and the column would
                // silently stop being clearable — a data rule that fails open is
                // worse than no rule, so it fails here with the column's name.
                if (! in_array($column['blank'] ?? 'keep', ['keep', 'clear'], true)) {
                    throw new InvalidArgumentException(
                        "Kolom \"{$column['header']}\" pada baris \"{$type}\" memakai blank yang tidak dikenal; gunakan keep atau clear.",
                    );
                }
            }

            $definition['rows'][$type] = $row;
        }

        if ($headers !== 1) {
            throw new InvalidArgumentException("Definisi \"{$definition['key']}\" harus punya tepat satu baris berperan header.");
        }

        return $definition;
    }
}
