<?php

namespace Modules\Core\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Services\AssetFormService;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\CrmFormService;
use Modules\Estimation\Enums\ComponentType;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\AhspComponent;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Services\EstimationFormService;
use Modules\Finance\Enums\PaymentDirection;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\TaxObligation;
use Modules\Finance\Services\FinanceFormService;
use Modules\Finance\Services\TaxEqualizationService;
use Modules\HrPayroll\Enums\Department;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Services\HrFormService;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\IssueReturn;
use Modules\Inventory\Models\PurchaseReturn;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\InventoryFormService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorEvaluation;
use Modules\Procurement\Services\ProcurementFormService;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Services\ServiceDeskFormService;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractAddendum;
use Modules\Subcontract\Services\SubcontractFormService;

/**
 * Every document that can be printed as a HOUSE FORM, declared rather than coded.
 *
 * The seven forms already shipped (data-proyek, laporan harian, laporan
 * mingguan, daftar temuan, the three izin) each cost a private composer method
 * in FormPrintService and a Blade of their own. That was right for seven and is
 * impossible for forty: the owner wants a print button on every module, and
 * nearly every house form is the SAME SKELETON — the four-party band, the
 * identity block, one or two bordered body tables, the notes area, the three
 * signature columns, the form code — differing only in its title, its columns
 * and where its rows come from.
 *
 * So those differences live here as data and Resources/views/forms/generic.blade
 * renders them. Adding a printable document is one array entry in the module's
 * own method below. No Blade, no composer method, no route, and — because the
 * catalogue endpoint reads this file — no schema.js edit either.
 *
 * In the taste of ImportableDocuments (instance methods, container-bindable so a
 * test can install a fixture definition without leaving a register() backdoor in
 * production) and AttachableDocuments (slug in, class never crossing the wire).
 *
 * ============================================================================
 * THE HONESTY RULE, which this file makes cheaper to break than the bespoke
 * path did and therefore states louder:
 *
 *   A cell is printed FROM THE DATABASE or printed as a RULED BLANK.
 *
 * A resolver that cannot answer returns null and the sheet rules the cell, the
 * way the owner's own paper leaves it dotted for the site to fill in by hand.
 * It must never return 0 for "unknown", never a plausible default, never the
 * string "null". A stored zero is a fact and prints as 0,00; an absent value is
 * not a zero. These forms are signed by three parties and filed as the project
 * record — a number invented here is a number somebody signs.
 * ============================================================================
 *
 * ------------------------------------------------------------- an entry
 *
 * Keyed by SLUG — the URL segment in /api/core/print/forms/{slug}/{id} and the
 * only thing that crosses the wire. Keys:
 *
 *   resource     REQUIRED. The schema.js RESOURCES key the button belongs to
 *                ('crm/quotations'). This is what lets the SPA draw the button
 *                without a schema.js edit per document.
 *   model        REQUIRED. Eloquent class the {id} is looked up in. findOrFail,
 *                so a missing record is a 404 and never a blank sheet.
 *   permission   REQUIRED. Spatie permission, always the owning module's .view:
 *                printing is reading in another shape.
 *   label        REQUIRED. The button reads "Cetak <label>".
 *   formTitle    REQUIRED. The underlined title on the sheet, upper case. A
 *                plain string nearly always; a slug that serves two
 *                instruments (berita-acara-cco) may pass a VALUE SPEC and
 *                title the sheet off the record.
 *   title        VALUE SPEC for the big centred line above it. Default: the
 *                project's name when one resolves, otherwise the counterparty's
 *                — which is what the owner's paper puts there.
 *   formCode     The code printed at the foot ("Form F/PN"). null = none.
 *   orientation  'portrait' (default) | 'landscape'. Landscape past ~8 columns.
 *   idField      Which field of the SPA row carries the {id}; default 'id'. A
 *                document printed per PROJECT from a line screen says
 *                'project_id', exactly as schema.js printForms does.
 *   params       ['tanggal' => 'report_date'] — query string the button appends,
 *                param name => field of the SPA row. Omit for none.
 *   with         Relations eager-loaded before composing. Name every relation
 *                any resolver touches; a body table of 200 rows that lazy-loads
 *                its item is 200 queries inside one print.
 *
 *                WITHTRASHED ON EVERY belongsTo TO A SOFT-DELETING MODEL:
 *                  'customer' => fn ($query) => $query->withTrashed(),
 *                Nearly every master in this ERP soft-deletes — Customer,
 *                Vendor, Project, Employee, Warehouse, Item, Account, Contract,
 *                every document class. Deleting one does NOT touch the rows
 *                that name it: the foreign key stays, so loaded plainly the
 *                relation comes back null and the sheet rules the NAME while
 *                still printing the MONEY beside it. A rekap gaji row with a
 *                netto and no employee, a surat penawaran for Rp 53 miliar
 *                with no addressee, a jurnal line with an amount and no
 *                account. Nothing is fabricated either way; the difference is
 *                whether we print the name the database still holds — and a
 *                dotted blank next to a figure invites one to be written in
 *                afterwards, on a sheet three parties have signed.
 *                NOT on a hasMany or hasOne of soft-deleting CHILDREN
 *                (a register's own rows, a schedule, a deployment history):
 *                a deleted child was deleted on purpose and reprinting it
 *                would put a withdrawn line back on the paper.
 *
 *                AND THE RULE ONLY REACHES THE ARRAYS ON THIS PAGE. It is
 *                stated absolutely above, and every entry defers to it, but a
 *                `with` array can only constrain the relations IT names. The
 *                sheet's own header is not built from one: the project's
 *                customer, contract, projectManager and siteManager are loaded
 *                by the SHARED house header on behalf of every project-backed
 *                sheet at once, in TWO places that must stay in step —
 *                  Modules\Core\Services\FormPrintService::header()   (loadMissing)
 *                  Modules\Core\Services\FormPrintService::project()  (the
 *                    bespoke forms' own loader; loadMissing is a no-op on a
 *                    relation already resolved, so an unconstrained load there
 *                    silently disarmed the constraint in header())
 *                — and that is exactly where this rule was found broken. A
 *                soft-deleted customer emptied the PEMILIK box of the band, and
 *                a soft-deleted contract ruled NO. SPK / KONTRAK and TANGGAL
 *                SPK and blanked the PEKERJAAN line, on all 22 project-backed
 *                sheets at once, with no entry here to blame and nothing on
 *                this page that could have fixed it. A relation loaded outside
 *                this file obeys the rule where it is loaded or nowhere.
 *   header       Which band the sheet carries. See HEADER below.
 *   date         VALUE SPEC for the form's own date — what TANGGAL says and,
 *                when the document hangs off a project, the day HARI KE and
 *                SISA HARI are counted to. Absent = the URL's ?tanggal=, else
 *                today. A dated document (a bill, a report) should name its own
 *                date column here: a sheet re-printed in September must not
 *                answer a different question from the one printed in June.
 *   period       VALUE SPEC for the PERIODE line. Absent = the form's date.
 *   pekerjaan    VALUE SPEC for the PEKERJAAN line. Absent and no project = a
 *                ruled blank, which is what the pad has.
 *   identity     Ordered ['LABEL' => VALUE SPEC] pairs, the document's own
 *                lines. Rendered two columns wide in the order written.
 *
 *                Two things the plain pair does not show:
 *
 *                A spec may carry its own 'label' RESOLVER beside its value,
 *                and then the array key is only the line's NAME in declaration
 *                order while the printed caption comes off the record:
 *                  'PENERIMA' => [
 *                      'label' => fn ($payment) => …,   // caption, resolved
 *                      'value' => fn ($payment) => …,
 *                  ]
 *                Reach for it only where one table holds two DOCUMENTS and the
 *                caption is a claim about which. Two do: a bukti pembayaran
 *                serves both directions, and on a RECEIPT the counterparty did
 *                not receive the money, they handed it over, so the line has to
 *                read DITERIMA DARI or the filed voucher states the opposite of
 *                what happened; an opname subkon is also the uang-muka sheet,
 *                which has no work period to head two lines PERIODE with. A
 *                label that resolves to null (or is not given) falls back to
 *                the key, so the ordinary case stays a plain pair.
 *
 *                An identity VALUE closure is also handed the sheet's composed
 *                DATE as its second argument — fn ($record, Carbon $date). That
 *                date is the document's OWN 'date' column when it declares one,
 *                else the ?tanggal= the caller asked for, else today — the
 *                registry's column WINS, and the order is that way round on
 *                purpose: an invoice dated 12 Juli is dated 12 Juli whenever it
 *                is reprinted, and a URL that could re-date it would make every
 *                reprint a different document (FormPrintService::registryHeader,
 *                asserted by test_the_documents_own_date_column_beats_the_url).
 *                A line that answers "as at when?" must answer it as at the date
 *                PRINTED ON THE SHEET: SISA MASA BERLAKU on a kontrak layanan
 *                headed 01 Januari 2026 was counting the days left from the day
 *                somebody pressed print, for a period that had not begun on the
 *                date the sheet claimed.
 *
 *                THE LABEL RESOLVER GETS NO DATE — only the record. A 'label'
 *                goes through FormPrintService::caption(), which is the one
 *                resolver shared by every caption on the sheet: identity
 *                captions, a totals row's label, docControl judul and all four
 *                signature strings. None of those has a sheet date in scope,
 *                and passing one to the identity caption alone would make the
 *                same closure signature mean two things depending on where it
 *                was written — which is exactly the sort of difference that is
 *                invisible until it prints. It is also not a gap worth closing:
 *                a caption is a claim about WHICH document the line is, not
 *                about when. Both entries that resolve an identity caption say
 *                so — bukti-pembayaran's PENERIMA / DITERIMA DARI turns on the
 *                payment's direction, opname-subkon's PERIODE pair on the
 *                claim's is_advance — and each reads a column of the record it
 *                was already handed. A caption that genuinely had to vary with
 *                the sheet's date would have to be composed into the VALUE.
 *   identityHouse  true prepends the ten contract lines (NO. SPK … SISA HARI);
 *                false suppresses them. Default: true when the header resolves
 *                a Project, false otherwise.
 *   body         Zero or more TABLES. See BODY below.
 *   notes        The "Catatan :" block. ['text' => VALUE SPEC, 'lines' => int,
 *                'weather' => …, 'hours' => bool]; null omits the block
 *                entirely. Default: three ruled lines.
 *   signatures   'house' (default) or exactly three columns. See SIGNATURES.
 *   docControl   The WIKA IK strip. true = judul from the label, no. dok. from
 *                the record's code, tanggal from the form's date, no. rev.
 *                blank (nothing in this ERP issues one). An array of four VALUE
 *                SPECs ['judul','no_dok','no_rev','tanggal'] for full control.
 *                null (default) = no strip.
 *
 * ------------------------------------------------------------ a VALUE SPEC
 *
 * The one thing this file resolves, used by identity, columns, totals, notes
 * and docControl alike. Three forms:
 *
 *   'customer.name'                  dotted path off the subject (data_get)
 *   fn ($subject) => …               closure; row closures also get
 *                                    ($row, $index, $record), and an identity
 *                                    VALUE closure also gets the sheet's date
 *                                    (a LABEL closure does not — see identity)
 *   ['value' => …, 'cast' => 'money']  either of the above, plus a cast
 *
 * Casts: text (default) | date | money | rupiah | qty | percent | int.
 * text auto-formats what it can prove: a date column prints as "18 Desember
 * 2025", a backed enum prints its label(). Everything else is trimmed to a
 * string, and an empty string is a BLANK — not the word "empty", not a dash.
 *
 * NO RAW SQL IN A RESOLVER. A closure here is a read off the record it was
 * given or a call into the owning module's service. A query written inside a
 * registry row is a query nobody profiles and nobody tests.
 *
 * ------------------------------------------------ a LABEL is not a VALUE SPEC
 *
 * The one thing to get right. A LABEL is text printed on the paper and is used
 * exactly as written; a VALUE SPEC is read off the record. Getting it backwards
 * prints an empty cell, not an error — a signature block of three unlabelled
 * rules — so the two lists are spelled out:
 *
 *   LABELS (literal text)   signature heading / subheading / role, a totals
 *                           row's label, docControl judul, a column's label,
 *                           a table's title, and every key of `identity`
 *   VALUE SPECS (resolved)  every `value`, signature party and name, notes
 *                           text, date, period, pekerjaan, title, a table's
 *                           `rows`, docControl no_dok / no_rev / tanggal
 *
 * A label that genuinely varies with the record — "PPN 11%", a rate stored per
 * document and never to be hard-coded into a template — passes a closure or a
 * ['value' => …] spec there instead, and is then resolved like any value.
 *
 * WHERE THAT IS ACTUALLY POSSIBLE, because it is not everywhere and the
 * difference is invisible until it prints:
 *
 *   resolvable   signature heading / subheading / role, a totals row's label,
 *                docControl judul, and an `identity` line's caption — but only
 *                through the spec's own 'label' key, never by writing a closure
 *                as the array KEY (PHP has no such key)
 *   literal only a column's label and a table's title. Both are handed to the
 *                Blade unresolved, so a closure there is printed, not called —
 *                which is a fatal "Object of class Closure could not be
 *                converted to string" on the sheet, not a quiet blank. A
 *                caption that must vary with the record belongs in an identity
 *                line or a totals row, where it can be.
 *
 * ---------------------------------------------------------------- HEADER
 *
 *   ['kind' => 'project'|'contract'|'customer'|'vendor'|'employee'|'none',
 *    'source' => VALUE SPEC resolving the counterparty MODEL (default: the
 *                kind's own name as a relation — 'customer' for customer, …),
 *    'project' => VALUE SPEC resolving a Project for the PROYEK box, on kinds
 *                that are not themselves a project]
 *
 * 'project' | 'contract' get the four-box band this codebase already ships and
 * the day arithmetic that goes with it. The others get a band of their own,
 * because a purchase order's counterparty is a VENDOR and not the owner of a
 * job, and a payslip's is an EMPLOYEE. 'none' prints the contractor box alone.
 *
 * ------------------------------------------------------------------ BODY
 *
 * A list of tables; two declared tables render as two bordered tables. Each:
 *
 *   id        optional html id, so a test can address one table of two
 *   title     optional grouped header row spanning every column
 *   when      optional VALUE SPEC; resolved falsy, the whole table is left off
 *             THIS record's sheet — the layout branch for a slug that prints
 *             two instruments (F/BATK: money tables on a tambah-kurang, the
 *             time table on an addendum waktu). A skipped table leaves no
 *             empty grid behind. Absent = always printed.
 *   rows      VALUE SPEC resolving an iterable (a relation name, usually)
 *   columns   [ ['label' => 'URAIAN', 'value' => …, 'cast' => …,
 *               'align' => 'left'|'center'|'right', 'width' => '22mm'] ]
 *   totals    [ ['label' => 'Jumlah', 'value' => …, 'cast' => 'money'] ]
 *             resolved against the RECORD, printed in the LAST column with the
 *             label spanning the rest
 *   minRows   pad to this many rows with RULED BLANKS — for a form the site
 *             fills in by hand. Never pad with zeros.
 *   empty     the sentence printed when there are no rows at all
 *
 * ------------------------------------------------------------ SIGNATURES
 *
 * 'house' (the default) is the block already shipped: Mengetahui (owner) /
 * Menyetujui-menolak (MK) / place-date + Kontraktor Pelaksana, with only OUR
 * column named — nothing in this ERP records who signs for the owner or the MK,
 * and a name printed there is a forged signature line. Without a project it
 * falls back to the WIKA triple, Dibuat / Diperiksa / Mengetahui, all three
 * ruled and unnamed.
 *
 * Or declare exactly three columns:
 *   ['heading' => 'Menyetujui,', 'subheading' => null,
 *    'party' => VALUE SPEC, 'name' => VALUE SPEC, 'role' => VALUE SPEC]
 * Fill 'name' only from a column that really records who signs.
 */
class PrintableDocuments
{
    /** Keys every entry must carry; a half-written row is refused, not printed. */
    private const REQUIRED = ['resource', 'model', 'permission', 'label', 'formTitle'];

    /**
     * Every printable document, by slug.
     *
     * Composed from one method per module so that adding a document touches
     * that module's method and nothing else — forty entries in one literal
     * would be forty teams editing the same forty lines.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->crm()
            + $this->estimation()
            + $this->projects()
            + $this->procurement()
            + $this->inventory()
            + $this->subcontract()
            + $this->finance()
            + $this->hr()
            + $this->serviceDesk()
            + $this->assets();
    }

    // ===================================================== Crm ==============

    /** @return array<string, array<string, mixed>> */
    protected function crm(): array
    {
        return [
            /*
             * PENAWARAN — the worked example the rest of the modules copy.
             *
             * Chosen for the engine's proof because it is the awkward case, not
             * the easy one: a penawaran has NO project (that is the point of a
             * penawaran), no SPK, no site and no konsultan MK, so it exercises
             * the counterparty band, the suppressed day arithmetic and the
             * document's own identity block all at once.
             */
            'penawaran' => [
                'resource' => 'crm/quotations',
                'model' => Quotation::class,
                'permission' => 'crm.view',
                'label' => 'Penawaran',
                'formTitle' => 'SURAT PENAWARAN HARGA',
                'formCode' => 'Form F/PN',
                // withTrashed on the customer, and this is the sheet that
                // shows why the rule is worth a paragraph in the class
                // docblock: crm_customers soft-deletes, the quotation keeps its
                // customer_id for ever, and loaded plainly a surat penawaran
                // for Rp 53.835.000.000,00 printed with KEPADA and ALAMAT
                // ruled — an offer of fifty-three billion rupiah addressed to
                // nobody, over three signature rules.
                'with' => [
                    'customer' => fn ($query) => $query->withTrashed(),
                    'items',
                ],
                'header' => ['kind' => 'customer', 'source' => 'customer'],
                /*
                 * THE SHEET'S ONE DATE IS THE LETTER DATE, AND IT IS
                 * DELIBERATELY NOT IN THE IDENTITY BLOCK.
                 *
                 * 'date' drives exactly one printed line here: "Jakarta Timur,
                 * 26 Juli 2026" above the third signature column, the
                 * conventional Indonesian place-and-date of a letter, which
                 * says when the sheet was drawn up. Naming created_at rather
                 * than letting it default to today is what stops a reprint in
                 * September re-dating a letter that went out in July.
                 *
                 * An identity line captioned TANGGAL PENAWARAN would be a
                 * different and stronger claim — the day the OFFER was made —
                 * and crm_quotations records no such column. This one carried
                 * created_at and the demo showed what that is worth: every
                 * seeded quotation holds the insert timestamp 26 Juli 2026
                 * while QTN/2026/I/0001 expires 15 Februari 2026, so the block
                 * printed "TANGGAL PENAWARAN : 26 Juli 2026" directly above
                 * "BERLAKU S/D : 15 Februari 2026" — a signed sheet stating the
                 * offer was raised five months after it lapsed. The line is
                 * gone; the letter date stays where a letter's date belongs.
                 * Adding a quotation_date column and backfilling it would be
                 * worse than either: retro-stamping a date nobody recorded onto
                 * offers already sent.
                 */
                'date' => 'created_at',
                'pekerjaan' => 'title',
                'identity' => [
                    'NO. PENAWARAN' => 'code',
                    'REVISI KE' => ['value' => 'revision', 'cast' => 'int'],
                    'KEPADA' => 'customer.name',
                    'ALAMAT' => 'customer.billing_address',
                    'LINGKUP PEKERJAAN' => 'scope_type',
                    'BERLAKU S/D' => ['value' => 'valid_until', 'cast' => 'date'],
                ],
                'body' => [
                    [
                        'id' => 'rincian-penawaran',
                        'title' => 'RINCIAN PENAWARAN',
                        'rows' => 'items',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'URAIAN PEKERJAAN', 'value' => 'description'],
                            ['label' => 'QTY', 'align' => 'right', 'width' => '16mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'unit'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        // Every one of these is a stored column, so every one is
                        // printed — including a discount of 0,00, which is a
                        // fact the customer is entitled to see stated.
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => 'subtotal', 'cast' => 'money'],
                            ['label' => 'Diskon', 'value' => 'discount_amount', 'cast' => 'money'],
                            ['label' => 'Dasar Pengenaan Pajak (DPP)', 'value' => 'dpp', 'cast' => 'money'],
                            [
                                'label' => fn (Quotation $quotation): string => 'PPN '.rtrim(rtrim(
                                    number_format((float) $quotation->ppn_rate, 2, ',', '.'), '0'), ','
                                ).'%',
                                'value' => 'ppn_amount',
                                'cast' => 'money',
                            ],
                            ['label' => 'TOTAL PENAWARAN', 'value' => 'total', 'cast' => 'money'],
                        ],
                        'empty' => 'Penawaran ini belum memiliki rincian.',
                    ],
                    /*
                     * TERBILANG — the stored total, spelled. Not a second
                     * opinion about the amount and not a computation: half the
                     * bagian pengadaan this company sells to refuse a surat
                     * penawaran whose figure is not written out, and a clerk
                     * typing it by hand is exactly how a penawaran goes out
                     * saying one number in digits and another in words.
                     */
                    [
                        'id' => 'terbilang-penawaran',
                        'rows' => fn (Quotation $quotation): array => [
                            ['kata' => Terbilang::rupiah($quotation->total)],
                        ],
                        'columns' => [
                            ['label' => 'TERBILANG', 'value' => 'kata'],
                        ],
                    ],
                    /*
                     * SYARAT & KETENTUAN — ruled, and that is the honest answer
                     * rather than a shortcut.
                     *
                     * crm_quotations records no terms of sale: not a column, not
                     * a table, nothing. The tempting fill is the customer's
                     * payment_term_days off the CRM master — but that is the
                     * term we have with that customer, not a term THIS offer
                     * makes, and "pembayaran 30 hari" printed under our own
                     * letterhead is an offer nobody made. quotations.notes is
                     * already printed in the Catatan block below; the conditions
                     * of the offer are written on these four lines by whoever
                     * signs it.
                     */
                    [
                        'id' => 'syarat-penawaran',
                        'columns' => [
                            ['label' => 'SYARAT & KETENTUAN'],
                        ],
                        'minRows' => 4,
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                /*
                 * Two named columns and one blank, and the blank is the point.
                 * crm_quotations records no approver name and no customer
                 * signatory — core_approvals knows who pressed Setujui in this
                 * application, which is not the same claim as "this person
                 * signed the offer" — so those rules are left for the pen.
                 */
                'signatures' => [
                    [
                        'heading' => 'Menyetujui,',
                        'subheading' => 'Pemberi Kerja',
                        'party' => 'customer.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Pemasaran',
                    ],
                    // The third column's heading is filled by the composer with
                    // "<kota>, <tanggal>" exactly as the house block does it,
                    // and its NULL party with our own legal name — 'ours' read
                    // like a keyword and was in fact a dotted path resolving to
                    // nothing, reaching the same output down the fallback that
                    // handles an unwritten column.
                    [
                        'heading' => null,
                        'subheading' => 'Hormat kami,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (Crm) — tambahkan dokumen baru tepat di bawah baris ini.

            /*
             * RINGKASAN KONTRAK — the one-page answer to "what did we sign, and
             * what is still to be billed".
             *
             * The two totals under the schedule are the whole point: a jadwal
             * termin that does not add up to the contract is the ordinary way
             * revenue goes missing (a maintenance quarter nobody invoiced, a
             * retensi termin left off the schedule), and it is invisible on a
             * screen that shows one row at a time. Printed side by side, the gap
             * is arithmetic anybody can do.
             */
            'kontrak-ringkas' => [
                'resource' => 'crm/contracts',
                'model' => Contract::class,
                'permission' => 'crm.view',
                'label' => 'Ringkasan Kontrak',
                'formTitle' => 'RINGKASAN KONTRAK',
                'formCode' => 'Form F/RK',
                // withTrashed on the customer — see the `with` note in the
                // class docblock. NOT on `project`: that one is a hasOne, and a
                // deleted project is a job somebody withdrew, not a dangling
                // name. Nor on `termins`, which are this document's own rows.
                'with' => [
                    'customer' => fn ($query) => $query->withTrashed(),
                    'termins', 'project',
                ],
                'header' => ['kind' => 'contract', 'source' => 'customer', 'project' => 'project'],
                'pekerjaan' => 'title',
                /*
                 * The house identity block counts a PROJECT's days from the
                 * project's own dates. This sheet answers the same questions —
                 * when does the job run, what is it worth — from the CONTRACT's
                 * columns, and prj_projects.start_date need not equal
                 * crm_contracts.start_date. Two answers on one page is one too
                 * many, so the contract's own lines are the only ones printed.
                 */
                'identityHouse' => false,
                'identity' => [
                    'NO. KONTRAK' => 'code',
                    // What the CUSTOMER files the job under — the number their
                    // finance department will quote back at us.
                    'NO. SPK / PO PELANGGAN' => 'contract_number_customer',
                    'TANGGAL TANDA TANGAN' => ['value' => 'sign_date', 'cast' => 'date'],
                    'MULAI PELAKSANAAN' => ['value' => 'start_date', 'cast' => 'date'],
                    'SELESAI PELAKSANAAN' => ['value' => 'end_date', 'cast' => 'date'],
                    'LINGKUP PEKERJAAN' => 'scope_type',
                    'NILAI KONTRAK (DPP)' => ['value' => 'value', 'cast' => 'rupiah'],
                    'PPN' => ['value' => 'ppn_amount', 'cast' => 'rupiah'],
                    'NILAI TERMASUK PPN' => ['value' => 'total_with_ppn', 'cast' => 'rupiah'],
                    'RETENSI' => ['value' => 'retention_pct', 'cast' => 'percent'],
                    // Contract::retentionAmount(), never a second multiplication
                    // written here: the retention this sheet states is the one
                    // the AR side withholds.
                    'NILAI RETENSI' => [
                        'value' => fn (Contract $contract): float => $contract->retentionAmount(),
                        'cast' => 'rupiah',
                    ],
                    // warranty_months is NOT NULL, so "0 bulan" is a fact — this
                    // contract carries no maintenance period — and prints as one.
                    'MASA PEMELIHARAAN' => fn (Contract $contract): string => ((int) $contract->warranty_months).' bulan',
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'jadwal-termin',
                        'title' => 'JADWAL TERMIN PENAGIHAN',
                        'rows' => 'termins',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => 'termin_no', 'cast' => 'int'],
                            ['label' => 'NAMA TERMIN', 'width' => '32mm', 'value' => 'name'],
                            ['label' => 'PERSEN', 'align' => 'right', 'width' => '16mm',
                                'value' => 'percent', 'cast' => 'percent'],
                            ['label' => 'SYARAT PENAGIHAN', 'value' => 'billing_condition'],
                            ['label' => 'RENCANA TAGIH', 'align' => 'center', 'width' => '24mm',
                                'value' => 'due_date', 'cast' => 'date'],
                            // A termin nobody has billed leaves this RULED. Not
                            // "belum", not a dash: the blank is what the clerk
                            // writes the invoice date on, and a word there would
                            // be a status this table does not store.
                            ['label' => 'TGL. DITAGIH', 'align' => 'center', 'width' => '24mm',
                                'value' => 'billed_at', 'cast' => 'date'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'Jumlah nilai termin terjadwal',
                                'value' => fn (Contract $contract): float => round((float) $contract->termins->sum('amount'), 2),
                                'cast' => 'money',
                            ],
                            ['label' => 'Nilai kontrak (DPP)', 'value' => 'value', 'cast' => 'money'],
                        ],
                        'empty' => 'Kontrak ini belum memiliki jadwal termin.',
                    ],
                ],
                'signatures' => [
                    [
                        'heading' => 'Mengetahui,',
                        'subheading' => 'Pemberi Kerja',
                        'party' => 'customer.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Bagian Administrasi Kontrak',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Kontraktor Pelaksana',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * BERITA ACARA CCO, Form F/BATK — the paper the three parties sign
             * before the contract moves. ONE slug, TWO instruments, and the
             * sheet must never dress one as the other:
             *
             *   tambah-kurang / eskalasi — the contract's VALUE moves. THE ONE
             *   DECISION is what "nilai sesudah" may say, and it is made in
             *   CrmFormService::changeOrderValues() where a test can address
             *   it: crm_contracts.value moves only on APPROVAL, so an approved
             *   amendment quotes the contract row and an unapproved one says
             *   plainly that it has not been agreed. The projection
             *   value + value_change is never printed — with two change orders
             *   pending, each sheet's total would ignore the other and both
             *   would look authoritative.
             *
             *   waktu (P0-B) — the contract's END DATE moves and no rupiah
             *   does, so the sheet prints the time table
             *   (CrmFormService::changeOrderTimeValues, the same quoted-record
             *   rule applied to dates) and NEITHER money table: an itemisation
             *   pad with HARGA SATUAN columns on a time addendum is an
             *   invitation to write a rupiah figure onto an instrument that
             *   moves none. The branch is the tables' own 'when' key, and the
             *   title follows the instrument.
             */
            'berita-acara-cco' => [
                'resource' => 'crm/contract-change-orders',
                'model' => ContractChangeOrder::class,
                'permission' => 'crm.view',
                'label' => 'Berita Acara Tambah-Kurang',
                'formTitle' => fn (ContractChangeOrder $order): string => $order->isTimeAddendum()
                    ? 'BERITA ACARA ADDENDUM WAKTU'
                    : 'BERITA ACARA PEKERJAAN TAMBAH / KURANG',
                'formCode' => 'Form F/BATK',
                // withTrashed down the contract path — see the class docblock.
                // A CCO that lost its contract would print an addendum to a
                // nameless agreement, with the change in rupiah intact.
                'with' => [
                    'contract' => fn ($query) => $query->withTrashed(),
                    'contract.customer' => fn ($query) => $query->withTrashed(),
                    'contract.project', 'termin',
                ],
                'header' => [
                    'kind' => 'contract',
                    'source' => 'contract.customer',
                    'project' => 'contract.project',
                ],
                // Dated by the CHANGE, never by the day somebody pressed print:
                // "hari ke 128" on this sheet is which day of the contract the
                // extra work was agreed, and a reprint must not re-answer it.
                'date' => 'change_date',
                'pekerjaan' => 'title',
                'identity' => [
                    'NO. BERITA ACARA' => 'code',
                    'TANGGAL PERUBAHAN' => ['value' => 'change_date', 'cast' => 'date'],
                    'NO. KONTRAK' => 'contract.code',
                    'JENIS PERUBAHAN' => 'change_type',
                    'ALASAN' => fn (ContractChangeOrder $order): ?string => app(CrmFormService::class)->changeOrderReason($order),
                    'REF. PELANGGAN' => 'customer_ref',
                    'STATUS' => 'status',
                    // Blank until the added value has been scheduled — which is
                    // exactly the question the site asks about a CCO six weeks
                    // later ("has anybody billed this yet?").
                    'TERMIN PENAGIHAN' => 'termin.name',
                ],
                'body' => [
                    /*
                     * A PAD, and it has to be. crm_contract_change_orders stores
                     * ONE lump-sum value_change and no line items at all, so the
                     * itemisation the three parties argue over at the site
                     * meeting is written here by hand — the same convention the
                     * three izin forms print on. Value instruments only: an
                     * addendum waktu itemises nothing in rupiah.
                     */
                    [
                        'id' => 'rincian-perubahan',
                        'title' => 'RINCIAN PEKERJAAN TAMBAH / KURANG',
                        'when' => fn (ContractChangeOrder $order): bool => ! $order->isTimeAddendum(),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm'],
                            ['label' => 'URAIAN PEKERJAAN'],
                            ['label' => 'VOLUME', 'align' => 'right', 'width' => '20mm'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '30mm'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm'],
                        ],
                        'minRows' => 5,
                    ],
                    [
                        'id' => 'nilai-perubahan',
                        'title' => 'NILAI KONTRAK',
                        'when' => fn (ContractChangeOrder $order): bool => ! $order->isTimeAddendum(),
                        'rows' => fn (ContractChangeOrder $order): array => app(CrmFormService::class)->changeOrderValues($order),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '40mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                    ],
                    /*
                     * The waktu branch (P0-B): three time lines from
                     * CrmFormService::changeOrderTimeValues — the signed end
                     * date, the signed day change, and either the stamped
                     * new_end_date (approved) or the CURRENT date labelled
                     * "belum disetujui". Pre-formatted text, deliberately NOT
                     * the money cast: "+14 hari" and "31 Juli 2027" are the
                     * cells, and a null keterangan is the ruled blank.
                     */
                    [
                        'id' => 'perubahan-waktu',
                        'title' => 'PERUBAHAN WAKTU PELAKSANAAN',
                        'when' => fn (ContractChangeOrder $order): bool => $order->isTimeAddendum(),
                        'rows' => fn (ContractChangeOrder $order): array => app(CrmFormService::class)->changeOrderTimeValues($order),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'KETERANGAN', 'align' => 'center', 'width' => '48mm',
                                'value' => 'keterangan'],
                        ],
                    ],
                ],
                'notes' => ['text' => 'description', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Menyetujui,',
                        'subheading' => 'Pemberi Kerja',
                        'party' => 'contract.customer.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    // The consultant NAME is a stored project fact and prints;
                    // who signs for them is not, and stays a blank rule.
                    [
                        'heading' => 'Mengetahui,',
                        'subheading' => 'Konsultan MK / Pengawas',
                        'party' => 'contract.project.consultant_name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Kontraktor Pelaksana',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * REGISTER JAMINAN & ASURANSI — every security a contract stands on,
             * with the day each one lapses.
             *
             * A REGISTER IS A LIST and the endpoint hands this one row's id, so
             * the sheet printed from any bond is the register of the CONTRACT
             * that bond belongs to (CrmFormService::guaranteeRegister). That is
             * the sheet somebody files: bonds lapse one at a time, and what the
             * project manager needs in front of him is all of them at once. A
             * bid bond has no contract — it is raised against the tender — and
             * then the register is the single row we can prove, rather than
             * dragging in bonds it has nothing to do with.
             *
             * Landscape: ten columns do not fit across a portrait page.
             */
            'register-jaminan' => [
                'resource' => 'crm/guarantees',
                'model' => Guarantee::class,
                'permission' => 'crm.view',
                'label' => 'Register Jaminan',
                'formTitle' => 'REGISTER JAMINAN & ASURANSI',
                'formCode' => 'Form F/RJ',
                'orientation' => 'landscape',
                // withTrashed on both parent paths — see the class docblock.
                // NOT on contract.guarantees: that hasMany IS the register this
                // sheet prints, and a guarantee somebody deleted must not come
                // back onto it under a new total.
                'with' => [
                    'contract' => fn ($query) => $query->withTrashed(),
                    'contract.customer' => fn ($query) => $query->withTrashed(),
                    'quotation' => fn ($query) => $query->withTrashed(),
                    'quotation.customer' => fn ($query) => $query->withTrashed(),
                    'contract.project', 'contract.guarantees',
                ],
                'header' => [
                    'kind' => 'customer',
                    // The obligee: whoever we issued the security in favour of,
                    // through the contract or — on a bid bond — the tender.
                    'source' => fn (Guarantee $guarantee): ?object => $guarantee->contract?->customer
                        ?? $guarantee->quotation?->customer,
                    'project' => 'contract.project',
                ],
                'pekerjaan' => fn (Guarantee $guarantee): ?string => $guarantee->contract?->title
                    ?? $guarantee->quotation?->title,
                // The bond's own dates are what this sheet is read for; the
                // job's day count would only compete with them.
                'identityHouse' => false,
                'identity' => [
                    'NO. KONTRAK' => 'contract.code',
                    'NO. SPK / PO PELANGGAN' => 'contract.contract_number_customer',
                    'NILAI KONTRAK (DPP)' => ['value' => 'contract.value', 'cast' => 'rupiah'],
                    'NO. PENAWARAN' => 'quotation.code',
                    'JUMLAH JAMINAN TERCATAT' => [
                        'value' => fn (Guarantee $guarantee): int => app(CrmFormService::class)
                            ->guaranteeRegister($guarantee)->count(),
                        'cast' => 'int',
                    ],
                ],
                'body' => [
                    [
                        'id' => 'daftar-jaminan',
                        'title' => 'DAFTAR JAMINAN & ASURANSI',
                        'rows' => fn (Guarantee $guarantee) => app(CrmFormService::class)->guaranteeRegister($guarantee),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            // 26mm and 32mm trimmed off these two: the nine
                            // fixed widths totalled 255mm of the 281mm a
                            // landscape sheet has after the layout's 8mm
                            // margins, leaving ~26mm for LOKASI DOKUMEN FISIK,
                            // where "Brankas kantor pusat" wrapped to three
                            // lines. Nothing overflowed and nothing was lost —
                            // the column just read badly.
                            ['label' => 'JENIS JAMINAN', 'width' => '26mm', 'value' => 'guarantee_type'],
                            ['label' => 'PENERBIT', 'width' => '32mm', 'value' => 'issuer'],
                            ['label' => 'NOMOR', 'width' => '34mm', 'value' => 'number'],
                            ['label' => 'BERLAKU DARI', 'align' => 'center', 'width' => '26mm',
                                'value' => 'start_date', 'cast' => 'date'],
                            ['label' => 'S/D', 'align' => 'center', 'width' => '26mm',
                                'value' => 'end_date', 'cast' => 'date'],
                            // Counted in the model and worded as the house forms
                            // word it: a bond past its date says how far past,
                            // and one already returned or claimed is ruled —
                            // "sisa 176 hari" against it would describe cover
                            // that no longer exists.
                            ['label' => 'SISA HARI', 'align' => 'right', 'width' => '26mm',
                                'value' => fn (Guarantee $row): ?string => app(CrmFormService::class)->guaranteeRemaining($row)],
                            ['label' => 'STATUS', 'align' => 'center', 'width' => '24mm', 'value' => 'status'],
                            ['label' => 'LOKASI DOKUMEN FISIK', 'value' => 'document_location'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'value', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'Jumlah nilai jaminan tercatat',
                                'value' => fn (Guarantee $guarantee): float => app(CrmFormService::class)
                                    ->guaranteeRegisterTotal($guarantee),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Belum ada jaminan tercatat untuk kontrak ini.',
                    ],
                ],
            ],
        ];
    }

    // ===================================================== the other modules =
    //
    // One method per module, empty until that module's lane fills it. They exist
    // already so a lane adds its documents inside its OWN method and never edits
    // a line another lane is also editing.

    /** @return array<string, array<string, mixed>> */
    protected function estimation(): array
    {
        // PRINTABLE REGISTRY (Estimation) — tambahkan dokumen baru di sini.
        return [
            /*
             * RAB — the bill of quantities as the owner's paper has it.
             *
             * TWO TABLES, NOT ONE GROUPED TABLE, and that is a decision rather
             * than a convenience. The house sheet renders a cell it cannot
             * answer as a RULED BLANK, so a "bagian" heading row inside the
             * item table would print dotted rules across VOLUME, SAT and HARGA
             * SATUAN — an invitation to write in columns the estimate already
             * answers. A rekapitulasi per bagian followed by the flat rincian
             * says the same thing and is what a RAB looks like anyway.
             */
            'rab' => [
                'resource' => 'estimation/boqs',
                'model' => Boq::class,
                'permission' => 'est.view',
                'label' => 'RAB / BOQ',
                'formTitle' => 'RENCANA ANGGARAN BIAYA (RAB)',
                'formCode' => 'Form F/RAB',
                // withTrashed on the three parents — see the class docblock.
                'with' => [
                    'project' => fn ($query) => $query->withTrashed(),
                    'quotation' => fn ($query) => $query->withTrashed(),
                    'contract' => fn ($query) => $query->withTrashed(),
                    'sections.items',
                ],
                // No PEMILIK box: an estimate exists before the job is won, and
                // an empty box captioned PEMILIK asserts a customer we may not
                // have. The PROYEK box appears when the BOQ really names one.
                'header' => ['kind' => 'none', 'project' => 'project'],
                // est_boqs has no document date; created_at IS the day the
                // estimate was raised, and naming it here is what keeps a
                // reprint in November dated as the estimate people quoted from.
                'date' => 'created_at',
                'title' => fn (Boq $boq): string => (string) ($boq->project?->name ?: $boq->title),
                // With no project the BOQ title is already the centred line
                // above, and a second copy of the same sentence on the PEKERJAAN
                // line is worth less than the ruled blank it replaces.
                'pekerjaan' => fn (Boq $boq): ?string => $boq->project === null ? null : $boq->title,
                'identityHouse' => false,
                'identity' => [
                    'NO. BOQ / RAB' => 'code',
                    'VERSI' => ['value' => 'version', 'cast' => 'int'],
                    'STATUS' => 'status',
                    'DIBUAT TANGGAL' => ['value' => 'created_at', 'cast' => 'date'],
                    // The traceability an estimator otherwise writes by hand:
                    // which penawaran and which kontrak this version was priced
                    // for. Blank on an estimate that has neither yet.
                    'NO. PENAWARAN' => 'quotation.code',
                    'NO. KONTRAK' => 'contract.code',
                ],
                'body' => [
                    [
                        'id' => 'rekapitulasi-rab',
                        'title' => 'REKAPITULASI',
                        'rows' => 'sections',
                        'columns' => [
                            ['label' => 'BAGIAN', 'align' => 'center', 'width' => '18mm', 'value' => 'section_no'],
                            ['label' => 'URAIAN PEKERJAAN', 'value' => 'name'],
                            // est_boq_sections.subtotal, cached by
                            // BoqService::recalcTotals — the module's own answer,
                            // not a second sum taken at print time.
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '40mm',
                                'value' => 'subtotal', 'cast' => 'money'],
                        ],
                        'totals' => [
                            ['label' => 'JUMLAH TOTAL (Rp)', 'value' => 'total', 'cast' => 'money'],
                        ],
                        'empty' => 'BOQ ini belum memiliki bagian pekerjaan.',
                    ],
                    [
                        'id' => 'rincian-rab',
                        'title' => 'RINCIAN',
                        'rows' => fn (Boq $boq): array => app(EstimationFormService::class)->rabRows($boq),
                        'columns' => [
                            ['label' => 'BAGIAN', 'align' => 'center', 'width' => '14mm', 'value' => 'bagian'],
                            ['label' => 'KODE', 'width' => '18mm', 'value' => 'kode'],
                            ['label' => 'URAIAN PEKERJAAN', 'value' => 'uraian'],
                            ['label' => 'VOLUME', 'align' => 'right', 'width' => '20mm',
                                'value' => 'volume', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'satuan'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'harga_satuan', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'jumlah', 'cast' => 'money'],
                        ],
                        'totals' => [
                            ['label' => 'JUMLAH TOTAL (Rp)', 'value' => 'total', 'cast' => 'money'],
                        ],
                        'empty' => 'BOQ ini belum memiliki item pekerjaan.',
                    ],
                    [
                        'id' => 'terbilang-rab',
                        'rows' => fn (Boq $boq): array => [['kata' => Terbilang::rupiah($boq->total)]],
                        'columns' => [
                            ['label' => 'TERBILANG', 'value' => 'kata'],
                        ],
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 2],
            ],

            /*
             * AHSP — one analysis, in the shape of SE Dirjen Bina Konstruksi
             * 182/2025 that the owner's own spreadsheet follows: A tenaga kerja,
             * B bahan, C peralatan, then D = A+B+C, E overhead & profit, F harga
             * satuan pekerjaan.
             *
             * The three groups are three declared tables because they are three
             * bordered blocks on the paper, each footing to its own jumlah. The
             * D/E/F block is assembled by EstimationFormService::ahspRecap(),
             * where the rounding that makes the column close is explained.
             */
            'ahsp' => [
                'resource' => 'estimation/ahsp',
                'model' => Ahsp::class,
                'permission' => 'est.view',
                'label' => 'AHSP',
                'formTitle' => 'ANALISA HARGA SATUAN PEKERJAAN',
                'formCode' => 'Form F/AHSP',
                'with' => ['components'],
                // An analysis belongs to no customer and no job — it is master
                // data priced once and used by every BOQ — so the band is our
                // own letterhead and nothing else.
                'header' => ['kind' => 'none'],
                'title' => 'name',
                // PEKERJAAN is left RULED on purpose: this sheet is printed to
                // be attached to a particular job's estimate, and which job that
                // is, is written on it by the estimator. The analysis itself
                // does not know.
                'identity' => [
                    'KODE ANALISA' => 'code',
                    'SATUAN' => 'unit',
                    'KATEGORI' => 'category',
                    'OVERHEAD & PROFIT' => ['value' => 'overhead_pct', 'cast' => 'percent'],
                    // Through the module's own formula, so the figure at the top
                    // of the sheet and the F line at the bottom cannot disagree.
                    'HARGA SATUAN' => [
                        'value' => fn (Ahsp $ahsp): float => app(EstimationFormService::class)->ahspUnitPrice($ahsp),
                        'cast' => 'rupiah',
                    ],
                ],
                'body' => [
                    [
                        'id' => 'ahsp-tenaga',
                        'title' => 'A. TENAGA KERJA',
                        'rows' => fn (Ahsp $ahsp) => app(EstimationFormService::class)
                            ->ahspComponents($ahsp, ComponentType::Labor),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'URAIAN', 'value' => 'name'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '18mm', 'value' => 'unit'],
                            ['label' => 'KOEFISIEN', 'align' => 'right', 'width' => '24mm',
                                'value' => 'coefficient', 'cast' => 'qty'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => fn (AhspComponent $row): float => $row->subtotal(), 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'A. Jumlah harga tenaga kerja',
                                'value' => fn (Ahsp $ahsp): float => app(EstimationFormService::class)
                                    ->ahspGroupTotal($ahsp, ComponentType::Labor),
                                'cast' => 'money',
                            ],
                        ],
                        // A sentence, not a row of zeros: an analysis with no
                        // labour has none, and "0,00" under a KOEFISIEN column
                        // reads as a component costed at nothing.
                        'empty' => 'Tidak ada komponen tenaga kerja pada analisa ini.',
                    ],
                    [
                        'id' => 'ahsp-bahan',
                        'title' => 'B. BAHAN',
                        'rows' => fn (Ahsp $ahsp) => app(EstimationFormService::class)
                            ->ahspComponents($ahsp, ComponentType::Material),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'URAIAN', 'value' => 'name'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '18mm', 'value' => 'unit'],
                            ['label' => 'KOEFISIEN', 'align' => 'right', 'width' => '24mm',
                                'value' => 'coefficient', 'cast' => 'qty'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => fn (AhspComponent $row): float => $row->subtotal(), 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'B. Jumlah harga bahan',
                                'value' => fn (Ahsp $ahsp): float => app(EstimationFormService::class)
                                    ->ahspGroupTotal($ahsp, ComponentType::Material),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Tidak ada komponen bahan pada analisa ini.',
                    ],
                    [
                        'id' => 'ahsp-alat',
                        'title' => 'C. PERALATAN',
                        'rows' => fn (Ahsp $ahsp) => app(EstimationFormService::class)
                            ->ahspComponents($ahsp, ComponentType::Equipment),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'URAIAN', 'value' => 'name'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '18mm', 'value' => 'unit'],
                            ['label' => 'KOEFISIEN', 'align' => 'right', 'width' => '24mm',
                                'value' => 'coefficient', 'cast' => 'qty'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => fn (AhspComponent $row): float => $row->subtotal(), 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'C. Jumlah harga peralatan',
                                'value' => fn (Ahsp $ahsp): float => app(EstimationFormService::class)
                                    ->ahspGroupTotal($ahsp, ComponentType::Equipment),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Tidak ada komponen peralatan pada analisa ini.',
                    ],
                    [
                        'id' => 'ahsp-rekap',
                        'title' => 'REKAPITULASI HARGA SATUAN',
                        'rows' => fn (Ahsp $ahsp): array => app(EstimationFormService::class)->ahspRecap($ahsp),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '40mm',
                                'value' => 'jumlah', 'cast' => 'money'],
                        ],
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 2],
            ],

            /*
             * RAP — what the job is budgeted to COST, against what the RAB sells
             * it for.
             *
             * The internal counterpart of the RAB and the only sheet in this
             * module that names a margin, which is why the two totals and both
             * margin lines sit in the identity block where they are read first.
             * A margin percentage that cannot be computed is left RULED; see
             * EstimationFormService::rapMarginPct().
             */
            'rap' => [
                'resource' => 'estimation/cost-budgets',
                'model' => CostBudget::class,
                'permission' => 'est.view',
                'label' => 'RAP',
                'formTitle' => 'RENCANA ANGGARAN PELAKSANAAN (RAP)',
                'formCode' => 'Form F/RAP',
                // withTrashed on the two parents — see the class docblock.
                'with' => [
                    'boq' => fn ($query) => $query->withTrashed(),
                    'project' => fn ($query) => $query->withTrashed(),
                    'items.boqItem',
                ],
                'header' => ['kind' => 'none', 'project' => 'project'],
                'date' => 'created_at',
                'title' => fn (CostBudget $budget): string => (string) (
                    $budget->project?->name ?: $budget->boq?->title ?: $budget->code
                ),
                'pekerjaan' => fn (CostBudget $budget): ?string => $budget->project === null
                    ? null
                    : $budget->boq?->title,
                'identityHouse' => false,
                'identity' => [
                    'NO. RAP' => 'code',
                    'DARI BOQ' => 'boq.code',
                    'STATUS' => 'status',
                    'NILAI BOQ (RAB)' => ['value' => 'boq.total', 'cast' => 'rupiah'],
                    'TOTAL ANGGARAN (RAP)' => ['value' => 'total_budget', 'cast' => 'rupiah'],
                    'TARGET MARGIN' => ['value' => 'target_margin_pct', 'cast' => 'percent'],
                    'MARGIN RENCANA' => [
                        'value' => fn (CostBudget $budget): ?float => app(EstimationFormService::class)
                            ->rapMarginAmount($budget),
                        'cast' => 'rupiah',
                    ],
                    'MARGIN RENCANA (%)' => [
                        'value' => fn (CostBudget $budget): ?float => app(EstimationFormService::class)
                            ->rapMarginPct($budget),
                        'cast' => 'percent',
                    ],
                ],
                'body' => [
                    [
                        'id' => 'rekap-kategori',
                        'title' => 'REKAPITULASI ANGGARAN PER KATEGORI',
                        'rows' => fn (CostBudget $budget): array => app(EstimationFormService::class)
                            ->rapCategories($budget),
                        'columns' => [
                            ['label' => 'KATEGORI BIAYA', 'value' => 'kategori'],
                            ['label' => 'PORSI', 'align' => 'right', 'width' => '24mm',
                                'value' => 'porsi', 'cast' => 'percent'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '40mm',
                                'value' => 'jumlah', 'cast' => 'money'],
                        ],
                        'totals' => [
                            ['label' => 'TOTAL ANGGARAN (Rp)', 'value' => 'total_budget', 'cast' => 'money'],
                        ],
                        'empty' => 'RAP ini belum memiliki rincian anggaran.',
                    ],
                    [
                        'id' => 'rincian-rap',
                        'title' => 'RINCIAN ANGGARAN',
                        'rows' => 'items',
                        'columns' => [
                            ['label' => 'KODE BOQ', 'width' => '20mm', 'value' => 'boqItem.wbs_code'],
                            ['label' => 'URAIAN', 'value' => 'description'],
                            ['label' => 'KATEGORI', 'width' => '24mm', 'value' => 'cost_category'],
                            ['label' => 'VOLUME', 'align' => 'right', 'width' => '20mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'unit'],
                            ['label' => 'BIAYA SATUAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            // amount is the authoritative column — the one
                            // recalcTotals sums into total_budget — so the
                            // rincian and the rekap can never disagree.
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        'totals' => [
                            ['label' => 'TOTAL ANGGARAN (Rp)', 'value' => 'total_budget', 'cast' => 'money'],
                        ],
                        'empty' => 'RAP ini belum memiliki rincian anggaran.',
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 2],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected function projects(): array
    {
        // PRINTABLE REGISTRY (Projects) — tambahkan dokumen baru di sini.
        // The seven forms already shipped are NOT here: they keep their bespoke
        // composers in FormPrintService::FORMS. See the lookup order documented
        // on FormPrintService::definition().
        return [];
    }

    /**
     * PENGADAAN — permintaan, pesanan, banding penawaran, evaluasi vendor.
     *
     * Four internal documents, and NONE of them carries the house identity
     * block. `identityHouse => false` on every entry, for one reason worth
     * stating once: the ten lines that block prints (no. SPK, waktu
     * pelaksanaan, perpanjangan waktu I & II, minggu ke, hari ke, sisa hari)
     * are the SITE FILE's identity, counted against the customer's contract. A
     * purchase order that printed "HARI KE : 52" would be answering a question
     * nobody asked of it, and — worse — a ruled "PERPANJANGAN WAKTU I : ......"
     * on a supplier's order invites somebody to write a contract extension
     * onto a document that cannot grant one. The band still names the project
     * when there is one; that is the part a reader actually needs.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function procurement(): array
    {
        return [
            /*
             * PERMINTAAN PEMBELIAN — the internal request, signed by the
             * requester and approved before it can become a PO.
             *
             * Header kind 'none' rather than 'project': a requisition is
             * raised BY us TO us, and the four-party band's PEMILIK /
             * KONSULTAN MK boxes would name two parties who have nothing to do
             * with it. The PROYEK box appears when the requisition names a
             * project and is simply absent when it does not, which is what an
             * office purchase looks like.
             */
            'permintaan-pembelian' => [
                'resource' => 'procurement/purchase-requisitions',
                'model' => PurchaseRequisition::class,
                'permission' => 'prc.view',
                'label' => 'Permintaan Pembelian',
                'formTitle' => 'PERMINTAAN PEMBELIAN (PURCHASE REQUISITION)',
                'formCode' => 'Form F/PP',
                // withTrashed on the project — see the class docblock. The
                // requester is a User, which does not soft-delete.
                'with' => [
                    'project' => fn ($query) => $query->withTrashed(),
                    'items', 'requester',
                ],
                'header' => ['kind' => 'none', 'project' => 'project'],
                // prc_purchase_requisitions has no request-date column:
                // created_at IS the day the requisition was raised. Naming it
                // here rather than letting the sheet default to today is what
                // keeps a reprint next quarter saying when it was asked for.
                'date' => 'created_at',
                'pekerjaan' => 'purpose',
                'identityHouse' => false,
                'identity' => [
                    'NO. PERMINTAAN' => 'code',
                    'TANGGAL PERMINTAAN' => ['value' => 'created_at', 'cast' => 'date'],
                    'PEMOHON' => 'requester.name',
                    'PROYEK' => 'project.name',
                    'DIBUTUHKAN TANGGAL' => ['value' => 'needed_date', 'cast' => 'date'],
                    // Printed so a draft sheet cannot be passed around as an
                    // approved one — the status is a stored fact and the
                    // difference between the two is money.
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-permintaan',
                        'title' => 'RINCIAN BARANG / JASA YANG DIMINTA',
                        // Through the module's own read model: a stock line's
                        // name lives in inv_items and the estimate's zero is a
                        // column default, not a price. See
                        // ProcurementFormService::requisitionLines.
                        'rows' => fn (PurchaseRequisition $requisition): array => app(ProcurementFormService::class)
                            ->requisitionLines($requisition),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'URAIAN BARANG / JASA', 'value' => 'description'],
                            ['label' => 'VOLUME', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'unit'],
                            ['label' => 'ESTIMASI HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'estimated_price', 'cast' => 'money'],
                            ['label' => 'ESTIMASI JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'estimated_amount', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'ESTIMASI NILAI PERMINTAAN',
                                // Ruled the moment one line is unpriced: a
                                // total that treats unknowns as zeros is
                                // always too small, on the very sheet an
                                // approver reads to decide who must sign it.
                                'value' => fn (PurchaseRequisition $requisition): ?float => app(ProcurementFormService::class)
                                    ->estimatedTotal($requisition),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Permintaan ini belum memiliki baris barang.',
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                /*
                 * Only the pemohon is named, and only because requested_by
                 * genuinely records who raised it. The other two rules stay
                 * blank: core_approvals knows who pressed Setujui in this
                 * application, which is not the same claim as "this person
                 * signed the requisition".
                 */
                'signatures' => [
                    [
                        'heading' => 'Diajukan,',
                        'subheading' => null,
                        'party' => null,
                        'name' => 'requester.name',
                        'role' => 'Pemohon',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Pengadaan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Menyetujui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek / Direktur',
                    ],
                ],
            ],

            /*
             * ORDER PEMBELIAN — the house sheet, printed BESIDE the dompdf PO
             * that DocumentPdfService already produces and that stays exactly
             * where it is. Two artefacts, two purposes: the dompdf one is the
             * commercial order sent to the supplier, this one is the sheet the
             * project file keeps, on the same paper as every other form in the
             * folder.
             */
            'order-pembelian' => [
                'resource' => 'procurement/purchase-orders',
                'model' => PurchaseOrder::class,
                'permission' => 'prc.view',
                'label' => 'Pesanan Pembelian (Formulir Rumah)',
                'formTitle' => 'SURAT PESANAN PEMBELIAN (PURCHASE ORDER)',
                'formCode' => 'Form F/PO',
                // withTrashed on every parent — see the class docblock. A PO
                // whose vendor row was deleted printed an order for goods,
                // priced to the rupiah, addressed to nobody.
                'with' => [
                    'vendor' => fn ($query) => $query->withTrashed(),
                    'project' => fn ($query) => $query->withTrashed(),
                    'purchaseRequisition' => fn ($query) => $query->withTrashed(),
                    'rfq' => fn ($query) => $query->withTrashed(),
                    'items',
                ],
                'header' => ['kind' => 'vendor', 'source' => 'vendor', 'project' => 'project'],
                'date' => 'order_date',
                'identityHouse' => false,
                'identity' => [
                    'NO. PESANAN' => 'code',
                    'TANGGAL PESANAN' => ['value' => 'order_date', 'cast' => 'date'],
                    'KEPADA' => 'vendor.name',
                    'ALAMAT' => 'vendor.address',
                    'PIC VENDOR' => 'vendor.pic_name',
                    'TELEPON' => 'vendor.phone',
                    'DASAR PERMINTAAN' => 'purchaseRequisition.code',
                    // The banding this price came from — the whole point of
                    // prc_purchase_orders.rfq_id (temuan #34 tahap 3) is that
                    // "harga PO adalah harga pemenang RFQ" is provable from
                    // the document itself.
                    'DASAR BANDING HARGA' => 'rfq.code',
                    'ALAMAT PENGIRIMAN' => 'delivery_address',
                    'TANGGAL JANJI KIRIM' => ['value' => 'expected_date', 'cast' => 'date'],
                    // A stored 0 here is a real term (bayar tunai), so it
                    // prints as "0 hari" rather than being ruled.
                    'TERMIN PEMBAYARAN' => fn (PurchaseOrder $order): string => (int) $order->payment_term_days.' hari',
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-pesanan',
                        'title' => 'RINCIAN PESANAN',
                        'rows' => 'items',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'URAIAN BARANG / JASA', 'value' => 'description'],
                            ['label' => 'QTY', 'align' => 'right', 'width' => '16mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'unit'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        // Every one of these is a stored column that PoService
                        // computed, so every one prints — including a discount
                        // of 0,00, which is a fact the vendor is entitled to
                        // see stated rather than left to infer.
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => 'subtotal', 'cast' => 'money'],
                            ['label' => 'Diskon', 'value' => 'discount_amount', 'cast' => 'money'],
                            ['label' => 'Dasar Pengenaan Pajak (DPP)', 'value' => 'dpp', 'cast' => 'money'],
                            [
                                // The rate is read off the order — it is 0 for
                                // a non-PKP vendor — and never typed into a
                                // template.
                                'label' => fn (PurchaseOrder $order): string => 'PPN '.rtrim(rtrim(
                                    number_format((float) $order->ppn_rate, 2, ',', '.'), '0'), ','
                                ).'%',
                                'value' => 'ppn_amount',
                                'cast' => 'money',
                            ],
                            ['label' => 'TOTAL PESANAN', 'value' => 'total', 'cast' => 'money'],
                        ],
                        'empty' => 'Pesanan ini belum memiliki baris barang.',
                    ],
                    [
                        'id' => 'terbilang-pesanan',
                        'rows' => fn (PurchaseOrder $order): array => app(ProcurementFormService::class)
                            ->orderTerbilangRow($order),
                        'columns' => [
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '42mm',
                                'value' => 'amount', 'cast' => 'money'],
                            ['label' => 'TERBILANG', 'value' => 'terbilang'],
                        ],
                    ],
                ],
                // Carries the override-prakualifikasi reason when there is
                // one, and rules itself when there is not — see
                // ProcurementFormService::orderNotes for why it lives here
                // rather than in an identity line.
                'notes' => [
                    'text' => fn (PurchaseOrder $order): ?string => app(ProcurementFormService::class)
                        ->orderNotes($order),
                    'lines' => 3,
                ],
                'signatures' => [
                    [
                        'heading' => 'Menyetujui,',
                        'subheading' => 'Pemasok / Vendor',
                        'party' => 'vendor.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Pengadaan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Hormat kami,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * BANDING PENAWARAN — the tabulation that used to live in a
             * procurement clerk's private spreadsheet (temuan #34 tahap 3).
             *
             * LANDSCAPE and the widest sheet in this lane, because it carries
             * two tables that are read together: who was asked and what each
             * of them offered in total, then every quoted cell line by line.
             *
             * The vendors are ROWS rather than columns. The owner's own
             * tabulation puts them across the top, and this sheet cannot: the
             * generic Blade's columns are declared once for the document, and
             * an RFQ's vendor count is a property of the record — three
             * columns would silently drop a fourth bidder, which on a
             * comparison sheet is the one failure that cannot be allowed.
             * Rows lose the side-by-side glance and keep every offer.
             */
            'banding-penawaran' => [
                'resource' => 'procurement/rfqs',
                'model' => Rfq::class,
                'permission' => 'prc.view',
                'label' => 'Tabulasi Banding Penawaran',
                'formTitle' => 'TABULASI BANDING PENAWARAN',
                // F/TBP, bukan F/BP: kode itu sudah milik Bukti Pembayaran /
                // Penerimaan, dan dua lembar berbeda dengan satu kode formulir
                // mengalahkan tujuan kode itu ada — arsip tidak bisa lagi
                // menunjuk lembar lewat kodenya.
                'formCode' => 'Form F/TBP',
                'orientation' => 'landscape',
                /*
                 * withTrashed on both vendor paths. prc_vendors soft-deletes,
                 * and a supplier deleted after the tabulation was priced left
                 * the WINNING row with a dotted blank under VENDOR beside a
                 * real 217.500.000,00 and a real "Ya" — while recommendation()
                 * three methods away still named it "Vendor #2". A ruled cell
                 * in the vendor column of a winning line is the hazard the
                 * PEMENANG column was made Ya/Tidak to avoid: it invites a
                 * name to be written in after the sheet is signed.
                 */
                'with' => [
                    'purchaseRequisition' => fn ($query) => $query->withTrashed(),
                    'project' => fn ($query) => $query->withTrashed(),
                    'items.quotes.vendor' => fn ($query) => $query->withTrashed(),
                    'vendors.vendor' => fn ($query) => $query->withTrashed(),
                ],
                'header' => ['kind' => 'none', 'project' => 'project'],
                'date' => 'rfq_date',
                'identityHouse' => false,
                'identity' => [
                    'NO. RFQ' => 'code',
                    'TANGGAL RFQ' => ['value' => 'rfq_date', 'cast' => 'date'],
                    'DASAR PERMINTAAN' => 'purchaseRequisition.code',
                    'PROYEK' => 'project.name',
                    'BATAS PEMASUKAN' => ['value' => 'due_date', 'cast' => 'date'],
                    // Ruled while nobody has decided. The recommendation is
                    // the decision the signature block records, and a sheet
                    // that pre-filled it with the cheapest column would be
                    // making that decision on the way to the printer.
                    'REKOMENDASI PEMENANG' => fn (Rfq $rfq): ?string => app(ProcurementFormService::class)
                        ->recommendation($rfq),
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rekap-vendor',
                        'title' => 'REKAPITULASI PENAWARAN PER VENDOR',
                        'rows' => fn (Rfq $rfq): array => app(ProcurementFormService::class)->vendorRecap($rfq),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'VENDOR', 'value' => 'vendor'],
                            ['label' => 'KODE', 'align' => 'center', 'width' => '22mm', 'value' => 'code'],
                            // Travels beside every total, not only the
                            // incomplete ones: two figures are not comparable
                            // when one of them prices half the scope, and the
                            // reader has to be told so in the same glance.
                            ['label' => 'KELENGKAPAN', 'width' => '40mm', 'value' => 'coverage'],
                            ['label' => 'JUMLAH PENAWARAN (Rp)', 'align' => 'right', 'width' => '34mm',
                                'value' => 'offer', 'cast' => 'money'],
                            ['label' => 'BARIS DIMENANGKAN', 'align' => 'center', 'width' => '24mm',
                                'value' => 'won_lines', 'cast' => 'int'],
                            ['label' => 'NILAI DIMENANGKAN (Rp)', 'align' => 'right', 'width' => '34mm',
                                'value' => 'won_value', 'cast' => 'money'],
                        ],
                        'empty' => 'Belum ada vendor yang diundang pada lembar banding ini.',
                    ],
                    [
                        'id' => 'tabulasi-harga',
                        'title' => 'TABULASI HARGA PER BARIS',
                        'rows' => fn (Rfq $rfq): array => app(ProcurementFormService::class)->quoteRows($rfq),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'line_no'],
                            ['label' => 'URAIAN BARANG / JASA', 'value' => 'description'],
                            ['label' => 'VOLUME', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'unit'],
                            ['label' => 'VENDOR', 'width' => '48mm', 'value' => 'vendor'],
                            // Ya / Tidak rather than a tick and a ruled blank:
                            // a marker column of dotted lines invites somebody
                            // to nominate a winner with a pen after the sheet
                            // has been signed.
                            //
                            // Declared BEFORE the two money columns on purpose.
                            // The generic sheet prints a totals figure in the
                            // LAST column — "under the column it totals, which
                            // is where the eye already is" — so with PEMENANG
                            // last, NILAI REKOMENDASI landed a 14-character
                            // rupiah figure in a 20mm Ya/Tidak column. Moving
                            // the flag left puts the total back under JUMLAH.
                            ['label' => 'PEMENANG', 'align' => 'center', 'width' => '20mm', 'value' => 'is_winner'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'NILAI REKOMENDASI (baris pemenang)',
                                'value' => fn (Rfq $rfq): ?float => app(ProcurementFormService::class)
                                    ->recommendedValue($rfq),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Lembar banding ini belum memiliki baris barang.',
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Disusun,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Staf Pengadaan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Pengadaan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Menyetujui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * EVALUASI VENDOR — the scoring sheet, printed as it was recorded.
             *
             * The delivery score's provenance sentence
             * (VendorEvaluationService::create writes it into notes when it
             * derives the score from GRN history) prints in the Catatan block
             * and is NEVER recomputed here. The GRN history behind it keeps
             * moving; a sheet that recomputed would contradict, a year later,
             * the number somebody signed. An evaluator who typed the score
             * leaves no provenance and gets ruled lines, not a manufactured
             * explanation.
             */
            /*
             * PERSYARATAN K3L UNTUK VENDOR — templat yang ditandatangani
             * subkontraktor sebelum SPK-nya bisa diajukan (gerbang P0-E).
             *
             * ISINYA SENGAJA BERGARIS KOSONG: pemilik tidak menitipkan teks
             * klausul K3L di repo ini, dan panduan yang MENGARANG klausul
             * keselamatan akan dipercaya orang persis di saat yang salah.
             * Lembar ini memberi kop, identitas vendor, ruang klausul
             * bergaris, dan blok tanda tangan — klausulnya milik HSE, ditulis
             * tangan atau dilampirkan.
             */
            'persyaratan-k3l-vendor' => [
                'resource' => 'procurement/vendors',
                'model' => Vendor::class,
                'permission' => 'prc.view',
                'label' => 'Persyaratan K3L Vendor',
                'formTitle' => 'PERSYARATAN K3L UNTUK VENDOR',
                'formCode' => 'Form F/K3V',
                'with' => [],
                'header' => ['kind' => 'vendor', 'source' => fn (Vendor $vendor): Vendor => $vendor],
                'date' => 'created_at',
                'identityHouse' => false,
                'title' => 'name',
                'identity' => [
                    'VENDOR' => 'name',
                    'KODE VENDOR' => 'code',
                    'NPWP' => 'npwp',
                    'ALAMAT' => 'address',
                    'KLASIFIKASI' => 'classification',
                    'STATUS SUBKONTRAKTOR' => ['value' => 'is_subcontractor', 'cast' => 'text'],
                ],
                'body' => [],
                'notes' => ['text' => null, 'lines' => 14],
                'signatures' => [
                    [
                        'heading' => 'Menyetujui dan menyanggupi,',
                        'subheading' => 'Vendor / Subkontraktor',
                        'party' => 'name',
                        'name' => null,
                        'role' => 'Nama, Jabatan & Stempel',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Safety Officer / HSE',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Pengadaan',
                    ],
                ],
            ],

            'evaluasi-vendor' => [
                'resource' => 'procurement/vendor-evaluations',
                'model' => VendorEvaluation::class,
                'permission' => 'prc.view',
                'label' => 'Evaluasi Vendor',
                'formTitle' => 'LEMBAR EVALUASI KINERJA VENDOR',
                'formCode' => 'Form F/EV',
                // withTrashed on the vendor and the project — see the class
                // docblock. An evaluation whose vendor row was deleted printed
                // a score out of 100 against nobody. The evaluator is a User,
                // which does not soft-delete.
                'with' => [
                    'vendor' => fn ($query) => $query->withTrashed(),
                    'project' => fn ($query) => $query->withTrashed(),
                    'evaluator',
                ],
                'header' => ['kind' => 'vendor', 'source' => 'vendor', 'project' => 'project'],
                'date' => 'created_at',
                'identityHouse' => false,
                'identity' => [
                    'VENDOR' => 'vendor.name',
                    'KODE VENDOR' => 'vendor.code',
                    'KLASIFIKASI' => 'vendor.classification',
                    'PERIODE EVALUASI' => 'period',
                    'PROYEK' => 'project.name',
                    'DINILAI OLEH' => 'evaluator.name',
                    'TANGGAL EVALUASI' => ['value' => 'created_at', 'cast' => 'date'],
                    // Labelled "berjalan" because it is not a property of
                    // this evaluation: prc_vendors.rating is the rolling
                    // average of every evaluation, kept to the column's one
                    // decimal, and it moves the next time one is filed. It can
                    // therefore differ from this sheet's own NILAI AKHIR — 4,3
                    // beside 4,25 — which is not a rounding bug but two
                    // different questions, and the label is what says so.
                    'RATING VENDOR BERJALAN' => ['value' => 'vendor.rating', 'cast' => 'qty'],
                ],
                'body' => [
                    [
                        'id' => 'kriteria-evaluasi',
                        'title' => 'PENILAIAN PER KRITERIA',
                        'rows' => fn (VendorEvaluation $evaluation): array => app(ProcurementFormService::class)
                            ->evaluationCriteria($evaluation),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'KRITERIA PENILAIAN', 'value' => 'criterion'],
                            ['label' => 'BOBOT', 'align' => 'center', 'width' => '20mm',
                                'value' => 'weight', 'cast' => 'percent'],
                            ['label' => 'SKOR (1-5)', 'align' => 'center', 'width' => '22mm', 'value' => 'score'],
                        ],
                        'totals' => [
                            ['label' => 'NILAI AKHIR (rata-rata empat kriteria)',
                                'value' => 'total_score', 'cast' => 'qty'],
                        ],
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Dinilai oleh,',
                        'subheading' => null,
                        'party' => null,
                        // evaluated_by genuinely records who scored the
                        // vendor, so this one rule carries a name.
                        'name' => 'evaluator.name',
                        'role' => 'Penilai',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Pengadaan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (Procurement) — tambahkan dokumen baru di sini.
        ];
    }

    /**
     * PERSEDIAAN — the seven gudang documents.
     *
     * THE ONE THING TO KNOW BEFORE EDITING THIS METHOD, because it is the trap
     * this module sets and no other module does: in five of these seven
     * documents a stored unit_cost of 0 means "nobody has valued this yet".
     * TransferService, IssueService, StockAdjustmentService and both retur
     * services all write 0 and leave the real figure to the posting step, each
     * with the same comment beside the zero. Printed as "0,00" that zero
     * becomes a valuation on a sheet somebody signs — the surat jalan an
     * insurer is shown after a hijacking, the berita acara opname two people
     * sign to accept a shortfall. So every money cell on those five sheets
     * comes through Modules\Inventory\Services\InventoryFormService, which
     * rules it until the document itself says it has been valued, and
     * tests/Feature/Inventory/InventoryPrintTest pins each one.
     *
     * The GRN is the deliberate exception: its unit_cost is typed by the
     * receiving clerk and a zero-cost line must be confirmed explicitly
     * (temuan #72), so a zero there is an assertion and prints.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function inventory(): array
    {
        return [
            /*
             * BUKTI PENERIMAAN BARANG — what the gudang signs when a truck
             * arrives, filed against the surat jalan the driver brought.
             *
             * The band names the PEMASOK, not the owner of a job: a delivery
             * is a transaction with a supplier, and PEMILIK / KONSULTAN MK
             * across the top of it would name two parties with nothing to do
             * with it. `identityHouse => false` for the reason the Pengadaan
             * lane states at length — a ruled "PERPANJANGAN WAKTU I : ......"
             * on a supplier's document invites somebody to write a contract
             * extension onto a paper that cannot grant one.
             *
             * The PROYEK box comes from the WAREHOUSE, which is the only
             * project this document itself records. The order's project is a
             * different fact with a different answer (a central warehouse
             * receives goods for jobs it does not belong to), and the traceable
             * half of it — the PO number — is an identity line instead.
             */
            'penerimaan-barang' => [
                'resource' => 'inventory/goods-receipts',
                'model' => GoodsReceipt::class,
                'permission' => 'inv.view',
                'label' => 'Bukti Penerimaan Barang',
                'formTitle' => 'BUKTI PENERIMAAN BARANG',
                /*
                 * THE JOB FIRST, THE SUPPLIER SECOND, THE WAREHOUSE LAST — in
                 * that order, and the order is the whole point.
                 *
                 * This line exists to fill ONE hole: a receipt with no vendor
                 * printed an EMPTY centred heading, a blank bold line and its
                 * margins where the subject belongs, which in the live data is
                 * every receipt there is.
                 *
                 * The heading names the SUBJECT of the sheet — what the filed
                 * copy is looked up by — and for a delivery into a site
                 * warehouse that is the job, not the supplier. So the fallthrough
                 * is deliberate rather than merely defensive, and the repetition
                 * it produces is deliberate with it: on a receipt whose
                 * warehouse belongs to a project the JOB is now named three
                 * times (this heading, the PROYEK band box, the PROYEK identity
                 * line) and the SUPPLIER twice (the PEMASOK / VENDOR band box
                 * and the PEMASOK line) — the counts the plain vendor heading
                 * had exactly the other way round. Three is what every
                 * project-backed house form in this codebase already does with
                 * its subject: the four-party band repeats PROYEK directly under
                 * the centred project title on all 22 of them, because a sheet
                 * that is filed by job has to be findable by job from the top of
                 * the page, from the band and from the block. The counterparty
                 * is named where a counterparty belongs — once in the band, once
                 * on its own line — and is not the thing the sheet is about.
                 *
                 * So it falls through: the project the receiving warehouse
                 * belongs to, else the supplier, else the warehouse itself.
                 * Every branch is a stored name; none of them invents one, and
                 * the last two exist because a central-warehouse receipt has no
                 * job to be about.
                 */
                'title' => fn (GoodsReceipt $receipt): ?string => $receipt->warehouse?->project?->name
                    ?: $receipt->vendor?->name
                    ?: $receipt->warehouse?->name,
                'formCode' => 'Form F/BPB',
                /*
                 * withTrashed on the item, here and on every other sheet in
                 * this method that prints line items.
                 *
                 * An item may be deleted once its stock reaches zero
                 * (DeletedItemGuardsTest), and the row it was named on stays
                 * in the database for ever. Loaded plainly the relation comes
                 * back null and the sheet rules KODE, URAIAN and SAT beside a
                 * real quantity and a real rupiah figure — a signed receipt
                 * for goods it cannot name, and a dotted blank next to money
                 * invites a name to be written in afterwards. Nothing is
                 * fabricated either way; the difference is whether we print
                 * the name the database still holds. InventoryFormService's
                 * stock-balance and in-transit queries already take this
                 * position, in those words.
                 */
                'with' => [
                    'vendor' => fn ($query) => $query->withTrashed(),
                    'purchaseOrder' => fn ($query) => $query->withTrashed(),
                    'warehouse' => fn ($query) => $query->withTrashed(),
                    'warehouse.project' => fn ($query) => $query->withTrashed(),
                    'items.item' => fn ($query) => $query->withTrashed(),
                    'receiver',
                ],
                'header' => ['kind' => 'vendor', 'source' => 'vendor', 'project' => 'warehouse.project'],
                'date' => 'receipt_date',
                'identityHouse' => false,
                'identity' => [
                    'NO. BUKTI' => 'code',
                    'TANGGAL TERIMA' => ['value' => 'receipt_date', 'cast' => 'date'],
                    'PEMASOK' => 'vendor.name',
                    // The vendor's own number for the delivery. Blank on a
                    // receipt raised without one, which is what a transfer from
                    // another site or a found-stock receipt looks like.
                    'NO. SURAT JALAN' => 'delivery_note_no',
                    'NO. PESANAN (PO)' => 'purchaseOrder.code',
                    'GUDANG' => 'warehouse.name',
                    'PROYEK' => 'warehouse.project.name',
                    'DITERIMA OLEH' => 'receiver.name',
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-penerimaan',
                        'title' => 'RINCIAN BARANG DITERIMA',
                        'rows' => 'items',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'KODE', 'width' => '22mm', 'value' => 'item.code'],
                            ['label' => 'URAIAN BARANG', 'value' => 'item.name'],
                            ['label' => 'QTY', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'item.unit'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'unit_cost', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'amount', 'cast' => 'money'],
                            /*
                             * RULED ON EVERY LINE, and the notes block says
                             * why. There is no qty_rejected anywhere in this
                             * ERP and no rejection document — goods actually
                             * sent back become a retur pembelian afterwards —
                             * so a partial rejection is written here in pen and
                             * initialled. A column of "Baik" printed by default
                             * would be the sheet accepting the delivery on the
                             * storeman's behalf.
                             */
                            ['label' => 'KONDISI / KETERANGAN', 'width' => '34mm'],
                        ],
                        'totals' => [
                            [
                                'label' => 'JUMLAH NILAI PENERIMAAN (Rp)',
                                'value' => fn (GoodsReceipt $grn): float => app(InventoryFormService::class)->receiptTotal($grn),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Penerimaan ini belum memiliki baris barang.',
                    ],
                ],
                'notes' => [
                    'text' => fn (GoodsReceipt $grn): string => app(InventoryFormService::class)->receiptNotes($grn),
                    'lines' => 3,
                ],
                'signatures' => [
                    // received_by really does record who took delivery, so this
                    // one rule carries a name. The other two do not.
                    [
                        'heading' => 'Diterima,',
                        'subheading' => null,
                        'party' => null,
                        'name' => 'receiver.name',
                        'role' => 'Petugas Gudang',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Pengawas Lapangan / QC',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Logistik',
                    ],
                ],
            ],

            /*
             * BON PENGELUARAN BARANG — material leaving the gudang for a job.
             *
             * The ONE document in this lane that keeps the house identity
             * block, and it keeps it on purpose: a bon is filed in the SITE
             * folder beside the laporan harian it explains, and "hari ke 74" is
             * the number a project manager reads it against. The four-party
             * band belongs for the same reason.
             */
            'bon-material' => [
                'resource' => 'inventory/issues',
                'model' => Issue::class,
                'permission' => 'inv.view',
                'label' => 'Bon Pengeluaran Barang',
                'formTitle' => 'BON PENGELUARAN BARANG',
                // The job, or the warehouse it came out of on an office bon.
                // See penerimaan-barang above for the blank heading this
                // avoids.
                'title' => fn (Issue $issue): ?string => $issue->project?->name
                    ?: $issue->warehouse?->name,
                'formCode' => 'Form F/BM',
                // withTrashed on the item and on every soft-deleting parent —
                // see penerimaan-barang above and the class docblock. NOT on
                // `project`: Issue::project() already declares withTrashed of
                // its own, for the same reason, and a second one here would be
                // two places to keep in step.
                'with' => [
                    'warehouse' => fn ($query) => $query->withTrashed(),
                    'project.customer' => fn ($query) => $query->withTrashed(),
                    'project.contract' => fn ($query) => $query->withTrashed(),
                    'items.item' => fn ($query) => $query->withTrashed(),
                    'wbsTask', 'items.wbsTask', 'issuer',
                ],
                'header' => ['kind' => 'project', 'source' => 'project'],
                'date' => 'issue_date',
                'identity' => [
                    'NO. BON' => 'code',
                    'TANGGAL BON' => ['value' => 'issue_date', 'cast' => 'date'],
                    'GUDANG' => 'warehouse.name',
                    // The header's work package, which one bon need not have at
                    // all: temuan 13 put the real attribution on the LINE, and
                    // the body column below carries it.
                    'WBS' => 'wbsTask.wbs_code',
                    'TUJUAN PEMAKAIAN' => 'purpose',
                    'DIKELUARKAN OLEH' => 'issuer.name',
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-bon',
                        'title' => 'RINCIAN BARANG DIKELUARKAN',
                        'rows' => fn (Issue $issue): array => app(InventoryFormService::class)->issueLines($issue),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'KODE', 'width' => '22mm', 'value' => 'kode'],
                            ['label' => 'URAIAN BARANG', 'value' => 'uraian'],
                            ['label' => 'WBS', 'align' => 'center', 'width' => '18mm', 'value' => 'wbs'],
                            ['label' => 'QTY', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'satuan'],
                            // Ruled on a draft: the warehouse average is frozen
                            // at posting and 0 until then.
                            ['label' => 'HPP SATUAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'hpp', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'jumlah', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'TOTAL NILAI PENGELUARAN (Rp)',
                                'value' => fn (Issue $issue): ?float => app(InventoryFormService::class)->issueTotal($issue),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Bon ini belum memiliki baris barang.',
                    ],
                ],
                // Carries the cancellation reason on a cancelled bon and rules
                // itself on a live one. A cancelled bon handed over as a live
                // one is material off the shelf against a document the ledger
                // has already reversed.
                'notes' => [
                    'text' => fn (Issue $issue): ?string => app(InventoryFormService::class)->issueNotes($issue),
                    'lines' => 3,
                ],
                'signatures' => [
                    [
                        'heading' => 'Diserahkan,',
                        'subheading' => null,
                        'party' => null,
                        'name' => 'issuer.name',
                        'role' => 'Petugas Gudang',
                    ],
                    [
                        'heading' => 'Diterima,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Penerima di Lapangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek',
                    ],
                ],
            ],

            /*
             * SURAT JALAN ANTAR GUDANG — the paper that rides with the driver.
             *
             * NO PROYEK BOX, and that is a decision. A transfer has two
             * warehouses and can therefore have two projects; one box would
             * pick a side and the reader could not tell which. Both jobs are
             * named as identity lines instead, beside the warehouse each
             * belongs to.
             */
            'surat-jalan-transfer' => [
                'resource' => 'inventory/transfers',
                'model' => Transfer::class,
                'permission' => 'inv.view',
                'label' => 'Surat Jalan Antar Gudang',
                'formTitle' => 'SURAT JALAN ANTAR GUDANG',
                /*
                 * Both ends, because a transfer that named only its origin
                 * would be half the document. One end resolving prints that end
                 * alone with no dangling arrow; neither resolving leaves the
                 * heading off the sheet entirely rather than printing an empty
                 * bold line — see penerimaan-barang above.
                 *
                 * THE ARROW IS JOINED, NEVER TRIMMED OFF AGAIN. The first
                 * version built "A → B" unconditionally and then trimmed the
                 * arrow away with trim($s, " \t\n\r\0\x0B→"). trim()'s
                 * character list is a list of BYTES and '→' is three of them
                 * (E2 86 92), so it stripped those bytes individually from both
                 * ends of the whole string: a warehouse whose name begins with
                 * any U+2xxx character — an em dash, an ellipsis, the curly
                 * quotes a keyboard produces by itself — lost its leading E2
                 * and printed as mojibake. "“Gudang Transit” Cakung" came out
                 * as "\x80\x9CGudang Transit” Cakung", which is not even valid
                 * UTF-8, at the top of a signed surat jalan. Each name is
                 * trimmed on its own with the default whitespace list (all
                 * ASCII), and the arrow is only ever put BETWEEN two names that
                 * survived.
                 */
                'title' => function (Transfer $transfer): ?string {
                    $ends = array_filter([
                        trim((string) $transfer->fromWarehouse?->name),
                        trim((string) $transfer->toWarehouse?->name),
                    ], fn (string $name): bool => $name !== '');

                    return $ends === [] ? null : implode(' → ', $ends);
                },
                'formCode' => 'Form F/SJ',
                // withTrashed on the item and on both warehouses and their
                // projects — see penerimaan-barang above and the class
                // docblock. Both ends are the heading of this sheet.
                'with' => [
                    'fromWarehouse' => fn ($query) => $query->withTrashed(),
                    'fromWarehouse.project' => fn ($query) => $query->withTrashed(),
                    'toWarehouse' => fn ($query) => $query->withTrashed(),
                    'toWarehouse.project' => fn ($query) => $query->withTrashed(),
                    'items.item' => fn ($query) => $query->withTrashed(),
                ],
                'header' => ['kind' => 'none'],
                'date' => 'transfer_date',
                'identity' => [
                    'NO. SURAT JALAN' => 'code',
                    'TANGGAL KIRIM' => ['value' => 'transfer_date', 'cast' => 'date'],
                    'GUDANG ASAL' => 'fromWarehouse.name',
                    'PROYEK ASAL' => 'fromWarehouse.project.name',
                    'GUDANG TUJUAN' => 'toWarehouse.name',
                    'PROYEK TUJUAN' => 'toWarehouse.project.name',
                    // Ruled while the goods are on the road and filled by
                    // receiveTransfer — which is the whole question this sheet
                    // is pulled out of the file to answer.
                    'TANGGAL TERIMA' => ['value' => 'received_date', 'cast' => 'date'],
                    'STATUS' => 'status',
                    // Nothing in this ERP records a truck, a plate or a driver.
                    // Ruled for the pen, never guessed.
                    'KENDARAAN' => null,
                    'NO. POLISI' => null,
                ],
                'body' => [
                    [
                        'id' => 'rincian-surat-jalan',
                        'title' => 'RINCIAN BARANG DIKIRIM',
                        'rows' => fn (Transfer $transfer): array => app(InventoryFormService::class)->transferLines($transfer),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'KODE', 'width' => '22mm', 'value' => 'kode'],
                            ['label' => 'URAIAN BARANG', 'value' => 'uraian'],
                            ['label' => 'QTY KIRIM', 'align' => 'right', 'width' => '20mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'satuan'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'harga', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'jumlah', 'cast' => 'money'],
                            // For the receiving storeman's pen. inv_transfers
                            // records ONE quantity, posted unchanged on both
                            // legs, so a shortfall on arrival has nowhere in
                            // this ERP to be written — it is written here, and
                            // becomes an opname at the destination.
                            ['label' => 'QTY DITERIMA', 'align' => 'right', 'width' => '24mm'],
                        ],
                        'totals' => [
                            [
                                'label' => 'NILAI BARANG DIKIRIM (Rp)',
                                'value' => fn (Transfer $transfer): ?float => app(InventoryFormService::class)->transferTotal($transfer),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Surat jalan ini belum memiliki baris barang.',
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Dikirim,',
                        'subheading' => 'Petugas Gudang Asal',
                        'party' => null,
                        'name' => null,
                        'role' => 'Nama & Tanggal',
                    ],
                    [
                        'heading' => 'Diterima,',
                        'subheading' => 'Petugas Gudang Tujuan',
                        'party' => null,
                        'name' => null,
                        'role' => 'Nama & Tanggal',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Logistik',
                    ],
                ],
            ],

            /*
             * BERITA ACARA STOCK OPNAME — the count, the difference and what
             * the difference is worth.
             *
             * LANDSCAPE, because ten columns do not fit across a portrait page
             * and the three quantity columns are the point of the sheet: a
             * berita acara that squeezed QTY SISTEM and QTY FISIK into one
             * column would be a berita acara nobody can check.
             */
            'berita-acara-opname' => [
                'resource' => 'inventory/stock-adjustments',
                'model' => StockAdjustment::class,
                'permission' => 'inv.view',
                'label' => 'Berita Acara Stock Opname',
                'formTitle' => 'BERITA ACARA STOCK OPNAME',
                /*
                 * The job the counted warehouse belongs to, else the warehouse
                 * itself — the same fall-through, and for the same reason, as
                 * penerimaan-barang above.
                 *
                 * A CENTRAL warehouse belongs to no project, so the heading was
                 * blank on those sheets and that is the hole this fills. A SITE
                 * warehouse does belong to one, and header.project below
                 * borrows it for the PROYEK box; heading the sheet with the
                 * warehouse unconditionally took the job off the top of an
                 * opname of that job's own store and replaced it with the name
                 * of the shed. The warehouse is never lost either way — GUDANG
                 * is an identity line four rows down.
                 */
                'title' => fn (StockAdjustment $adjustment): ?string => $adjustment->warehouse?->project?->name
                    ?: $adjustment->warehouse?->name,
                'formCode' => 'Form F/BAO',
                'orientation' => 'landscape',
                // withTrashed on the item and on the counted warehouse — see
                // penerimaan-barang above and the class docblock. The warehouse
                // is the subject of this sheet twice over: the heading falls
                // back to it and GUDANG is an identity line.
                'with' => [
                    'warehouse' => fn ($query) => $query->withTrashed(),
                    'warehouse.project' => fn ($query) => $query->withTrashed(),
                    'items.item' => fn ($query) => $query->withTrashed(),
                ],
                'header' => ['kind' => 'none', 'project' => 'warehouse.project'],
                'date' => 'adjustment_date',
                // An opname counts a warehouse; it is not a milestone of the
                // job that warehouse serves, and the contract day count would
                // only compete with the count date the sheet is read for.
                'identityHouse' => false,
                'identity' => [
                    'NO. BERITA ACARA' => 'code',
                    'TANGGAL OPNAME' => ['value' => 'adjustment_date', 'cast' => 'date'],
                    'GUDANG' => 'warehouse.name',
                    'PROYEK' => 'warehouse.project.name',
                    'ALASAN PENYESUAIAN' => 'reason',
                    'STATUS' => 'status',
                    // Ruled until the difference has actually been written off.
                    // The gap between counting and posting is where an opname
                    // sits waiting for a second signature, and the sheet has to
                    // show which side of it this copy came from.
                    'TANGGAL POSTING' => ['value' => 'posted_at', 'cast' => 'date'],
                ],
                'body' => [
                    [
                        'id' => 'rincian-opname',
                        'title' => 'HASIL PERHITUNGAN FISIK',
                        'rows' => fn (StockAdjustment $adjustment): array => app(InventoryFormService::class)->opnameLines($adjustment),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'KODE', 'width' => '22mm', 'value' => 'kode'],
                            ['label' => 'URAIAN BARANG', 'value' => 'uraian'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'satuan'],
                            ['label' => 'QTY SISTEM', 'align' => 'right', 'width' => '22mm',
                                'value' => 'sistem', 'cast' => 'qty'],
                            ['label' => 'QTY FISIK', 'align' => 'right', 'width' => '22mm',
                                'value' => 'fisik', 'cast' => 'qty'],
                            ['label' => 'SELISIH', 'align' => 'right', 'width' => '22mm',
                                'value' => 'selisih', 'cast' => 'qty'],
                            ['label' => 'HPP SATUAN (Rp)', 'align' => 'right', 'width' => '26mm',
                                'value' => 'hpp', 'cast' => 'money'],
                            ['label' => 'NILAI SELISIH (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'nilai', 'cast' => 'money'],
                            // The document stores ONE reason for the whole
                            // count; why a particular item is four zak short is
                            // written on its own line by the people counting.
                            ['label' => 'ALASAN SELISIH', 'width' => '40mm'],
                        ],
                        'totals' => [
                            [
                                'label' => 'NILAI SELISIH BERSIH (Rp)',
                                'value' => fn (StockAdjustment $adjustment): ?float => app(InventoryFormService::class)->opnameTotal($adjustment),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Berita acara ini belum memiliki baris perhitungan.',
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Dihitung oleh,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Petugas Penghitung',
                    ],
                    [
                        'heading' => 'Disaksikan,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Pengawas / Bagian Keuangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Disetujui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Logistik',
                    ],
                ],
            ],

            /*
             * DAFTAR SALDO STOK PER GUDANG — the stock-take sheet.
             *
             * THE RESOURCE KEY IS 'inventory/warehouses', AND THAT IS THE
             * DECISION ON THIS ENTRY. The obvious candidate was the Saldo Stok
             * screen itself, but inv_stock_balances is a LINE table: one row is
             * one item in one warehouse, with no code, no document identity and
             * nothing to sign — printing "the balance row's id" would produce a
             * one-line sheet. What the gudang actually carries round the racks
             * is a WAREHOUSE's whole stock, so the button belongs on the
             * warehouse row and the {id} is a warehouse.
             *
             * Landscape: eight columns, and the item names are long.
             */
            'saldo-stok' => [
                'resource' => 'inventory/warehouses',
                'model' => Warehouse::class,
                'permission' => 'inv.view',
                'label' => 'Daftar Saldo Stok',
                'formTitle' => 'DAFTAR SALDO STOK PER GUDANG',
                'formCode' => 'Form F/SS',
                'orientation' => 'landscape',
                // withTrashed on the project and the keeper — see the class
                // docblock. hr_employees soft-deletes, and PENANGGUNG JAWAB is
                // the line that says who answers for the stock listed below it.
                'with' => [
                    'project' => fn ($query) => $query->withTrashed(),
                    'keeper' => fn ($query) => $query->withTrashed(),
                ],
                'header' => ['kind' => 'none', 'project' => 'project'],
                /*
                 * DATED BY THE PRINTER, and this is the honesty decision that
                 * matters most on this sheet. inv_stock_balances keeps NO
                 * history: qty and avg_cost are today's, rewritten by every
                 * movement, and nothing in this ERP can reconstruct what they
                 * were in June. Because a declared date wins over ?tanggal=,
                 * naming it here is what stops a URL heading a live listing
                 * with a month whose figures nobody kept.
                 */
                'date' => fn (Warehouse $warehouse): Carbon => app(InventoryFormService::class)->printedOn(),
                // The centred line is the WAREHOUSE, not the job it serves: a
                // central gudang serves every job and none.
                'title' => 'name',
                'identityHouse' => false,
                'identity' => [
                    'KODE GUDANG' => 'code',
                    'NAMA GUDANG' => 'name',
                    'PROYEK' => 'project.name',
                    'ALAMAT' => 'address',
                    'PENANGGUNG JAWAB' => 'keeper.name',
                    'STATUS GUDANG' => fn (Warehouse $warehouse): string => $warehouse->is_active ? 'Aktif' : 'Nonaktif',
                    'SALDO PER TANGGAL' => [
                        'value' => fn (Warehouse $warehouse): Carbon => app(InventoryFormService::class)->printedOn(),
                        'cast' => 'date',
                    ],
                    'JENIS ITEM TERCATAT' => [
                        'value' => fn (Warehouse $warehouse): int => count(app(InventoryFormService::class)->warehouseBalances($warehouse)),
                        'cast' => 'int',
                    ],
                    'NILAI PERSEDIAAN' => [
                        'value' => fn (Warehouse $warehouse): float => app(InventoryFormService::class)->warehouseStockValue($warehouse),
                        'cast' => 'rupiah',
                    ],
                ],
                'body' => [
                    [
                        'id' => 'saldo-gudang',
                        'title' => 'SALDO STOK',
                        'rows' => fn (Warehouse $warehouse): array => app(InventoryFormService::class)->warehouseBalances($warehouse),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'KODE ITEM', 'width' => '24mm', 'value' => 'item.code'],
                            ['label' => 'NAMA ITEM', 'value' => 'item.name'],
                            ['label' => 'KATEGORI', 'width' => '36mm', 'value' => 'item.category.name'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'item.unit'],
                            ['label' => 'QTY', 'align' => 'right', 'width' => '24mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'HPP RATA-RATA (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'avg_cost', 'cast' => 'money'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '34mm',
                                'value' => fn (StockBalance $row): float => app(InventoryFormService::class)->balanceValue($row),
                                'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'NILAI PERSEDIAAN DI GUDANG INI (Rp)',
                                'value' => fn (Warehouse $warehouse): float => app(InventoryFormService::class)->warehouseStockValue($warehouse),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Gudang ini belum memiliki kartu saldo untuk item apa pun.',
                    ],
                    /*
                     * The second table exists because of T28/T29. Goods that
                     * have left one warehouse and not arrived at the other sit
                     * in NEITHER balance for the whole transit window, so a
                     * count that does not know about them reports a shortfall
                     * at one end and a surplus at the other — and the two are
                     * never reconciled, because nobody knows they are the same
                     * twenty zak. Both directions are listed: what went out is
                     * already off this sheet's saldo, and what is coming in may
                     * be standing in the yard when the counters walk past it.
                     */
                    [
                        'id' => 'barang-dalam-perjalanan',
                        'title' => 'BARANG DALAM PERJALANAN — TIDAK TERCATAT DI SALDO GUDANG MANA PUN',
                        'rows' => fn (Warehouse $warehouse): array => app(InventoryFormService::class)->inTransitLines($warehouse),
                        'columns' => [
                            ['label' => 'ARAH', 'align' => 'center', 'width' => '18mm', 'value' => 'arah'],
                            ['label' => 'GUDANG LAWAN', 'width' => '34mm', 'value' => 'lawan'],
                            ['label' => 'NO. TRANSFER', 'width' => '26mm', 'value' => 'transfer'],
                            ['label' => 'TANGGAL KIRIM', 'align' => 'center', 'width' => '24mm',
                                'value' => 'tanggal', 'cast' => 'date'],
                            ['label' => 'KODE ITEM', 'width' => '22mm', 'value' => 'kode'],
                            ['label' => 'NAMA ITEM', 'value' => 'uraian'],
                            ['label' => 'QTY', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '12mm', 'value' => 'satuan'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '26mm',
                                'value' => 'harga', 'cast' => 'money'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'Nilai keluar dari gudang ini — sudah tidak ada di saldo di atas',
                                'value' => fn (Warehouse $warehouse): float => app(InventoryFormService::class)->inTransitOutValue($warehouse),
                                'cast' => 'money',
                            ],
                            [
                                'label' => 'Nilai menuju gudang ini — belum masuk saldo di atas',
                                'value' => fn (Warehouse $warehouse): float => app(InventoryFormService::class)->inTransitInValue($warehouse),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Tidak ada barang dalam perjalanan dari atau ke gudang ini.',
                    ],
                ],
                'signatures' => [
                    [
                        'heading' => 'Disusun,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Petugas Gudang',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Kepala Gudang',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Logistik',
                    ],
                ],
            ],

            /*
             * BUKTI RETUR PEMBELIAN — goods going back to the supplier against
             * one receipt.
             *
             * The mirror column is the point: QTY DITERIMA beside QTY DIRETUR,
             * read off the receipt line this return names. Without it the sheet
             * says ten batang are going back and not whether that is the whole
             * delivery or a tenth of it — which is the first thing the vendor's
             * driver argues about.
             */
            'retur-pembelian' => [
                'resource' => 'inventory/purchase-returns',
                'model' => PurchaseReturn::class,
                'permission' => 'inv.view',
                'label' => 'Bukti Retur Pembelian',
                'formTitle' => 'BUKTI RETUR PEMBELIAN',
                'formCode' => 'Form F/RPB',
                // withTrashed on the item and on every soft-deleting parent —
                // see penerimaan-barang above and the class docblock.
                'with' => [
                    'vendor' => fn ($query) => $query->withTrashed(),
                    'warehouse' => fn ($query) => $query->withTrashed(),
                    'warehouse.project' => fn ($query) => $query->withTrashed(),
                    'goodsReceipt' => fn ($query) => $query->withTrashed(),
                    'goodsReceipt.purchaseOrder' => fn ($query) => $query->withTrashed(),
                    'items.item' => fn ($query) => $query->withTrashed(),
                    'items.receiptItem', 'returner',
                ],
                'header' => ['kind' => 'vendor', 'source' => 'vendor', 'project' => 'warehouse.project'],
                'date' => 'return_date',
                'identityHouse' => false,
                'identity' => [
                    'NO. RETUR' => 'code',
                    'TANGGAL RETUR' => ['value' => 'return_date', 'cast' => 'date'],
                    'PEMASOK' => 'vendor.name',
                    'ALAMAT' => 'vendor.address',
                    'NO. BUKTI PENERIMAAN' => 'goodsReceipt.code',
                    'TANGGAL TERIMA' => ['value' => 'goodsReceipt.receipt_date', 'cast' => 'date'],
                    'NO. SURAT JALAN' => 'goodsReceipt.delivery_note_no',
                    'NO. PESANAN (PO)' => 'goodsReceipt.purchaseOrder.code',
                    'GUDANG' => 'warehouse.name',
                    'DIRETUR OLEH' => 'returner.name',
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-retur-pembelian',
                        'title' => 'RINCIAN BARANG DIRETUR',
                        'rows' => fn (PurchaseReturn $return): array => app(InventoryFormService::class)->purchaseReturnLines($return),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'KODE', 'width' => '22mm', 'value' => 'kode'],
                            ['label' => 'URAIAN BARANG', 'value' => 'uraian'],
                            ['label' => 'QTY DITERIMA', 'align' => 'right', 'width' => '22mm',
                                'value' => 'qty_asal', 'cast' => 'qty'],
                            ['label' => 'QTY DIRETUR', 'align' => 'right', 'width' => '22mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'satuan'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'harga', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'jumlah', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'NILAI RETUR (Rp)',
                                'value' => fn (PurchaseReturn $return): ?float => app(InventoryFormService::class)->purchaseReturnTotal($return),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Retur ini belum memiliki baris barang.',
                    ],
                ],
                // inv_purchase_returns.reason is a mandatory 500-character
                // sentence, not a code: it belongs in the catatan block where
                // it can be read, not squeezed into an identity value.
                'notes' => ['text' => 'reason', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Diserahkan,',
                        'subheading' => null,
                        'party' => null,
                        'name' => 'returner.name',
                        'role' => 'Petugas Gudang',
                    ],
                    [
                        'heading' => 'Diterima,',
                        'subheading' => 'Pemasok / Vendor',
                        'party' => 'vendor.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Logistik',
                    ],
                ],
            ],

            /*
             * BUKTI RETUR MATERIAL DARI PROYEK — the partial way back for a
             * posted bon (temuan 37).
             *
             * A site document like the bon it mirrors, so it keeps the house
             * identity block: the two sheets are filed together, and the day
             * count on both has to be the same number.
             */
            'retur-material' => [
                'resource' => 'inventory/issue-returns',
                'model' => IssueReturn::class,
                'permission' => 'inv.view',
                'label' => 'Bukti Retur Material',
                'formTitle' => 'BUKTI RETUR MATERIAL DARI PROYEK',
                'formCode' => 'Form F/RTM',
                // withTrashed on the item and on every soft-deleting parent —
                // see penerimaan-barang above and the class docblock. The
                // project itself needs none: Issue::project() declares its own.
                'with' => [
                    'issue' => fn ($query) => $query->withTrashed(),
                    'issue.project.customer' => fn ($query) => $query->withTrashed(),
                    'issue.project.contract' => fn ($query) => $query->withTrashed(),
                    'warehouse' => fn ($query) => $query->withTrashed(),
                    'items.item' => fn ($query) => $query->withTrashed(),
                    'items.issueItem', 'returner',
                ],
                'header' => ['kind' => 'project', 'source' => 'issue.project'],
                'date' => 'return_date',
                'identity' => [
                    'NO. RETUR' => 'code',
                    'TANGGAL RETUR' => ['value' => 'return_date', 'cast' => 'date'],
                    'NO. BON' => 'issue.code',
                    'TANGGAL BON' => ['value' => 'issue.issue_date', 'cast' => 'date'],
                    'GUDANG PENERIMA' => 'warehouse.name',
                    'TUJUAN PEMAKAIAN AWAL' => 'issue.purpose',
                    'DIRETUR OLEH' => 'returner.name',
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-retur-material',
                        'title' => 'RINCIAN MATERIAL DIKEMBALIKAN',
                        'rows' => fn (IssueReturn $return): array => app(InventoryFormService::class)->issueReturnLines($return),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'KODE', 'width' => '22mm', 'value' => 'kode'],
                            ['label' => 'URAIAN BARANG', 'value' => 'uraian'],
                            ['label' => 'QTY BON', 'align' => 'right', 'width' => '22mm',
                                'value' => 'qty_asal', 'cast' => 'qty'],
                            ['label' => 'QTY RETUR', 'align' => 'right', 'width' => '22mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'satuan'],
                            ['label' => 'HPP SATUAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'harga', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'jumlah', 'cast' => 'money'],
                        ],
                        'totals' => [
                            [
                                'label' => 'NILAI RETUR (Rp)',
                                'value' => fn (IssueReturn $return): ?float => app(InventoryFormService::class)->issueReturnTotal($return),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Retur ini belum memiliki baris material.',
                    ],
                ],
                'notes' => ['text' => 'reason', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Diserahkan,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Pelaksana Lapangan',
                    ],
                    [
                        'heading' => 'Diterima,',
                        'subheading' => null,
                        'party' => null,
                        'name' => 'returner.name',
                        'role' => 'Petugas Gudang',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (Inventory) — tambahkan dokumen baru di sini.
        ];
    }

    /**
     * SUBKONTRAK — SPK, addendum, berita acara opname.
     *
     * The counterparty of all three is the SUBCONTRACTOR, so all three carry
     * the vendor band rather than the four-party project band: a sheet that
     * printed PEMILIK / KONSULTAN MK across the top of an SPK would name the
     * two parties who are NOT party to it and leave the one who signs it off
     * the letterhead. The PROYEK box still appears, because a subcontract
     * belongs to a job and the site file is where it is kept.
     *
     * `identityHouse => false` throughout, and here for a sharper reason than
     * in Pengadaan: the house block's first line is "NO. SPK / KONTRAK", which
     * on these sheets means the CUSTOMER's contract number. Beside a subcon
     * SPK number it reads as the same thing and is not, and two documents
     * called SPK on one page is exactly how the wrong number gets copied onto
     * a payment.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function subcontract(): array
    {
        return [
            /*
             * SPK SUBKON — the work order the subcontractor signs.
             *
             * Every commercial term on it is a stored column except one:
             * scm_subcontracts records no payment schedule of any kind, so
             * TERMIN PEMBAYARAN is ruled for the pen. prc_vendors
             * .payment_term_days is a master-data default for invoicing that
             * vendor and is NOT a term of this SPK; printing it here would put
             * a contract term nobody agreed to onto a signed sheet.
             */
            'spk-subkon' => [
                'resource' => 'subcontract/subcontracts',
                'model' => Subcontract::class,
                'permission' => 'scm.view',
                'label' => 'SPK Subkontraktor',
                'formTitle' => 'SURAT PERINTAH KERJA (SPK) SUBKONTRAKTOR',
                'formCode' => 'Form F/SP',
                // withTrashed on the subcontractor and the job — see the class
                // docblock. An SPK is a contract: printing its scope and its
                // value with the counterparty ruled would be a signed agreement
                // between us and a blank.
                'with' => [
                    'vendor' => fn ($query) => $query->withTrashed(),
                    'project' => fn ($query) => $query->withTrashed(),
                    'items',
                ],
                'header' => ['kind' => 'vendor', 'source' => 'vendor', 'project' => 'project'],
                // scm_subcontracts has no signing-date column: created_at is
                // the day the SPK was raised in this ERP. Named here rather
                // than left to default to today, so a reprint two years later
                // still says when it was issued.
                'date' => 'created_at',
                'pekerjaan' => 'title',
                'identityHouse' => false,
                'identity' => [
                    'NO. SPK' => 'code',
                    'TANGGAL SPK' => ['value' => 'created_at', 'cast' => 'date'],
                    'SUBKONTRAKTOR' => 'vendor.name',
                    'ALAMAT' => 'vendor.address',
                    'NPWP' => 'vendor.npwp',
                    'PROYEK' => 'project.name',
                    'TANGGAL MULAI' => ['value' => 'start_date', 'cast' => 'date'],
                    'TANGGAL SELESAI' => ['value' => 'end_date', 'cast' => 'date'],
                    // The date the retention guarantee may be released
                    // (temuan #75). Ruled on an SPK that never recorded one,
                    // which is also exactly when RetentionService refuses to
                    // release without a stated override.
                    'MASA PEMELIHARAAN S/D' => ['value' => 'defect_liability_until', 'cast' => 'date'],
                    // value is addendum-adjusted: AddendumService moves it on
                    // approval, and the amendment trail is its own sheet.
                    'NILAI SPK (DPP)' => ['value' => 'value', 'cast' => 'rupiah'],
                    // ppn_rate 0 means the subcontractor is not PKP, which is
                    // a fact and not a gap — see SubcontractFormService.
                    'PPN' => fn (Subcontract $spk): string => app(SubcontractFormService::class)->ppnLine($spk),
                    'RETENSI' => ['value' => 'retention_pct', 'cast' => 'percent'],
                    'NILAI RETENSI' => [
                        'value' => fn (Subcontract $spk): float => app(SubcontractFormService::class)
                            ->retentionAmount($spk),
                        'cast' => 'rupiah',
                    ],
                    'SKEMA PPh FINAL' => 'pph_scheme',
                    // The rate SNAPSHOTTED at creation, never today's config:
                    // the SPK is taxed at the rate it was signed under.
                    'TARIF PPh FINAL' => ['value' => 'pph_rate', 'cast' => 'percent'],
                    'TERMIN PEMBAYARAN' => null,
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'lingkup-pekerjaan',
                        'title' => 'LINGKUP PEKERJAAN',
                        'rows' => 'items',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'WBS', 'align' => 'center', 'width' => '16mm', 'value' => 'wbs_code'],
                            ['label' => 'URAIAN PEKERJAAN', 'value' => 'description'],
                            ['label' => 'VOLUME', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'unit'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        'totals' => [
                            ['label' => 'JUMLAH (DPP)', 'value' => 'value', 'cast' => 'money'],
                            [
                                'label' => fn (Subcontract $spk): string => app(SubcontractFormService::class)
                                    ->ppnLabel($spk),
                                'value' => fn (Subcontract $spk): float => app(SubcontractFormService::class)
                                    ->ppnAmount($spk),
                                'cast' => 'money',
                            ],
                            [
                                'label' => 'TOTAL NILAI SPK',
                                'value' => fn (Subcontract $spk): float => app(SubcontractFormService::class)
                                    ->totalWithPpn($spk),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'SPK ini belum memiliki baris pekerjaan.',
                    ],
                ],
                // Scope narrative, notes, and the override-prakualifikasi
                // reason when there is one — ruled when there is nothing.
                'notes' => [
                    'text' => fn (Subcontract $spk): ?string => app(SubcontractFormService::class)->spkNotes($spk),
                    'lines' => 3,
                ],
                'signatures' => [
                    [
                        'heading' => 'Menyetujui,',
                        'subheading' => 'Subkontraktor',
                        'party' => 'vendor.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Kontraktor Pelaksana',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * ADDENDUM SPK — pekerjaan tambah-kurang (temuan #48), the subcon
             * mirror of the customer-side berita acara CCO.
             *
             * The two value lines are the interesting cells: see
             * SubcontractFormService::addendumValues for when a before/after
             * pair can be proven from the columns and when both are ruled
             * instead of being arithmetic dressed as a fact.
             */
            'addendum-spk' => [
                'resource' => 'subcontract/addenda',
                'model' => SubcontractAddendum::class,
                'permission' => 'scm.view',
                'label' => 'Addendum SPK',
                'formTitle' => 'BERITA ACARA ADDENDUM SPK',
                'formCode' => 'Form F/AS',
                // withTrashed down the SPK path — see the class docblock.
                'with' => [
                    'subcontract' => fn ($query) => $query->withTrashed(),
                    'subcontract.vendor' => fn ($query) => $query->withTrashed(),
                    'subcontract.project' => fn ($query) => $query->withTrashed(),
                    'items',
                ],
                'header' => [
                    'kind' => 'vendor',
                    'source' => 'subcontract.vendor',
                    'project' => 'subcontract.project',
                ],
                'date' => 'addendum_date',
                'pekerjaan' => 'subcontract.title',
                'identityHouse' => false,
                'identity' => [
                    'NO. ADDENDUM' => 'code',
                    'TANGGAL ADDENDUM' => ['value' => 'addendum_date', 'cast' => 'date'],
                    'PERIHAL' => 'title',
                    'NO. SPK INDUK' => 'subcontract.code',
                    'SUBKONTRAKTOR' => 'subcontract.vendor.name',
                    // Shipped from day one so an escalation never has to
                    // masquerade as added work (the retrofit Crm needed).
                    'JENIS PERUBAHAN' => 'change_type',
                    'ALASAN' => fn (SubcontractAddendum $addendum): ?string => app(SubcontractFormService::class)
                        ->addendumReason($addendum),
                    // Signed: a reduction prints with its minus sign, which is
                    // the only honest way to show pekerjaan kurang.
                    'NILAI PERUBAHAN' => ['value' => 'value_change', 'cast' => 'rupiah'],
                    'NILAI SPK SEBELUM' => [
                        'value' => fn (SubcontractAddendum $addendum): ?float => app(SubcontractFormService::class)
                            ->addendumValues($addendum)['before'],
                        'cast' => 'rupiah',
                    ],
                    'NILAI SPK SETELAH' => [
                        'value' => fn (SubcontractAddendum $addendum): ?float => app(SubcontractFormService::class)
                            ->addendumValues($addendum)['after'],
                        'cast' => 'rupiah',
                    ],
                    // Printed next to those two on purpose: whether the SPK
                    // value has already moved is exactly what the status says.
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'pekerjaan-tambah',
                        'title' => 'BARIS PEKERJAAN YANG DITAMBAHKAN',
                        'rows' => 'items',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'WBS', 'align' => 'center', 'width' => '16mm', 'value' => 'wbs_code'],
                            ['label' => 'URAIAN PEKERJAAN', 'value' => 'description'],
                            ['label' => 'VOLUME', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '14mm', 'value' => 'unit'],
                            ['label' => 'HARGA SATUAN (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'unit_price', 'cast' => 'money'],
                            ['label' => 'JUMLAH (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        // No totals row: AddendumService::assertLinesMatchChange
                        // already ties the lines to value_change, which the
                        // identity block prints. A second copy of the same
                        // figure is a second thing to keep in agreement.
                        'empty' => 'Addendum ini tidak membawa baris pekerjaan baru.',
                    ],
                ],
                'notes' => ['text' => 'description', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Menyetujui,',
                        'subheading' => 'Subkontraktor',
                        'party' => 'subcontract.vendor.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Kontraktor Pelaksana',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * OPNAME SUBKON — the berita acara the site signs and Finance pays
             * against.
             *
             * One slug for both documents scm_progress_claims holds, because
             * they ARE one table and one lifecycle: an opname and a klaim uang
             * muka. The identity block names which kind this is, and the body
             * table says out loud that a DP has no progress lines rather than
             * printing an opname whose every percentage is zero.
             *
             * Every figure below the table is a stored column that
             * ClaimService (or AdvanceService, for a DP) computed and saved —
             * retensi, PPN, PPh final, potongan uang muka, netto dibayar. None
             * is recomputed here: the sheet and the AP bill that follows it
             * must agree to the rupiah, and the only way to guarantee that is
             * to read the same columns.
             */
            'opname-subkon' => [
                'resource' => 'subcontract/progress-claims',
                'model' => ProgressClaim::class,
                'permission' => 'scm.view',
                'label' => 'Berita Acara Opname',
                'formTitle' => 'BERITA ACARA OPNAME DAN PEMBAYARAN SUBKONTRAKTOR',
                'formCode' => 'Form F/BO',
                // Landscape: nine body columns, and the registry's own rule a
                // few hundred lines up is "landscape past ~8". In portrait the
                // eight fixed widths left ~52mm for URAIAN PEKERJAAN, so a
                // 66-character scope line wrapped to four or five rows apiece
                // on the sheet three parties read volumes off.
                'orientation' => 'landscape',
                // withTrashed down the SPK path — see the class docblock. The
                // whole identity block of this sheet hangs off it: NO. SPK,
                // SUBKONTRAKTOR, PROYEK and NILAI SPK BERJALAN all come through
                // `subcontract`, so one deleted SPK row rules four lines and
                // leaves JUMLAH DIBAYARKAN standing alone.
                'with' => [
                    'subcontract' => fn ($query) => $query->withTrashed(),
                    'subcontract.vendor' => fn ($query) => $query->withTrashed(),
                    'subcontract.project' => fn ($query) => $query->withTrashed(),
                    'items.subcontractItem',
                ],
                'header' => [
                    'kind' => 'vendor',
                    'source' => 'subcontract.vendor',
                    'project' => 'subcontract.project',
                ],
                // Dated by the period it covers, never by the day somebody
                // pressed print: a reprint in December must still be the
                // opname for March.
                'date' => 'period_end',
                'pekerjaan' => 'subcontract.title',
                'identityHouse' => false,
                'identity' => [
                    'NO. BERITA ACARA' => 'code',
                    'JENIS KLAIM' => fn (ProgressClaim $claim): string => app(SubcontractFormService::class)
                        ->claimKind($claim),
                    'KLAIM KE' => ['value' => 'claim_no', 'cast' => 'int'],
                    /*
                     * THE PERIOD PAIR, AND WHAT A DP IS ALLOWED TO SAY THERE.
                     *
                     * scm_progress_claims.period_start / period_end are NOT
                     * NULL, so an uang-muka claim has to put something in them,
                     * and AdvanceService writes the SAME claim date into both —
                     * "A DP has no work period; both bounds carry the claim
                     * date so the NOT NULL columns stay honest instead of
                     * inventing one", in its own words. Printed under the two
                     * captions written for an opname that produced a sheet
                     * saying "PERIODE DARI : 10 Februari 2026 / PERIODE S/D :
                     * 10 Februari 2026" — one stored date twice, under two
                     * headings that both promise a period of WORK, two lines
                     * under JENIS KLAIM : Uang muka (DP) and above a body table
                     * that says in a sentence there are no progress lines.
                     *
                     * So on a DP the captions move (the engine resolves an
                     * identity 'label' the same way it resolves a value — see
                     * FormPrintService::registryIdentity): the stored date is
                     * printed ONCE as the day the uang muka was claimed, which
                     * is what AdvanceService put there, and the second line
                     * states in words that there is no work period rather than
                     * ruling a blank somebody could write one into.
                     */
                    'PERIODE DARI' => [
                        'label' => fn (ProgressClaim $claim): string => $claim->is_advance
                            ? 'TANGGAL KLAIM'
                            : 'PERIODE DARI',
                        'value' => 'period_start',
                        'cast' => 'date',
                    ],
                    'PERIODE S/D' => [
                        'label' => fn (ProgressClaim $claim): string => $claim->is_advance
                            ? 'PERIODE PEKERJAAN'
                            : 'PERIODE S/D',
                        // No 'date' cast, because this one line answers with a
                        // date on an opname and with a sentence on a DP. The
                        // default text cast renders the model's Carbon exactly
                        // as the date cast would — FormPrintService::text()
                        // formats a DateTimeInterface through the same
                        // date() — and leaves the sentence alone, where a date
                        // cast would hand "Tidak ada" to Carbon::parse.
                        'value' => fn (ProgressClaim $claim): mixed => $claim->is_advance
                            ? 'Tidak ada'
                            : $claim->period_end,
                    ],
                    'NO. SPK' => 'subcontract.code',
                    'SUBKONTRAKTOR' => 'subcontract.vendor.name',
                    'PROYEK' => 'subcontract.project.name',
                    /*
                     * "BERJALAN", because this is the LIVE contract value, not
                     * the one that stood when this opname was signed.
                     *
                     * scm_progress_claims records no contract-value snapshot,
                     * and addenda move scm_subcontracts.value: a berita acara
                     * for March reprinted after an August addendum was reading
                     * Rp 2.250.000.000 where the three signatures had been put
                     * under Rp 2.100.000.000 — on the one sheet otherwise
                     * pinned to its period ('date' => 'period_end' above).
                     * Naming the figure as the running value is honest about
                     * what we can actually answer. Inventing a snapshot column
                     * and backfilling it would be worse: retro-stamping a
                     * value nobody recorded, on documents already signed.
                     * Same wording as RATING VENDOR BERJALAN.
                     */
                    'NILAI SPK BERJALAN (DPP)' => ['value' => 'subcontract.value', 'cast' => 'rupiah'],
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'opname-pekerjaan',
                        'title' => 'OPNAME PEKERJAAN PERIODE INI',
                        'rows' => 'items',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '8mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'URAIAN PEKERJAAN', 'value' => 'subcontractItem.description'],
                            ['label' => 'VOL KONTRAK', 'align' => 'right', 'width' => '17mm',
                                'value' => 'subcontractItem.qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '12mm',
                                'value' => 'subcontractItem.unit'],
                            ['label' => 'NILAI KONTRAK (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'subcontractItem.amount', 'cast' => 'money'],
                            ['label' => 'S/D LALU', 'align' => 'right', 'width' => '15mm',
                                'value' => 'prev_progress_pct', 'cast' => 'percent'],
                            ['label' => 'PERIODE INI', 'align' => 'right', 'width' => '16mm',
                                'value' => 'period_progress_pct', 'cast' => 'percent'],
                            ['label' => 'KUMULATIF', 'align' => 'right', 'width' => '16mm',
                                'value' => 'current_progress_pct', 'cast' => 'percent'],
                            ['label' => 'NILAI PERIODE INI (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'amount', 'cast' => 'money'],
                        ],
                        'totals' => [
                            /*
                             * The first figure is the one an uang-muka sheet
                             * was shouting a falsehood about, and the loudest
                             * of the four: gross_amount on a DP is the ADVANCE,
                             * not work done. Labelled "Jumlah pekerjaan periode
                             * ini (DPP)" unconditionally, the sheet stated that
                             * Rp 100.000.000 of work was performed in a period
                             * — contradicted two lines above by JENIS KLAIM :
                             * Uang muka (DP) and by the body table's own "Klaim
                             * ini tidak memiliki baris progres pekerjaan"
                             * immediately over it. Same claim-kind treatment as
                             * the retensi, PPN and PPh labels below.
                             */
                            [
                                'label' => fn (ProgressClaim $claim): string => $claim->is_advance
                                    ? 'Jumlah uang muka (DPP)'
                                    : 'Jumlah pekerjaan periode ini (DPP)',
                                'value' => 'gross_amount',
                                'cast' => 'money',
                            ],
                            [
                                'label' => fn (ProgressClaim $claim): string => app(SubcontractFormService::class)
                                    ->retentionLabel($claim),
                                'value' => 'retention_amount',
                                'cast' => 'money',
                            ],
                            ['label' => 'Netto sebelum pajak', 'value' => 'net_before_tax', 'cast' => 'money'],
                            [
                                'label' => fn (ProgressClaim $claim): string => app(SubcontractFormService::class)
                                    ->claimPpnLabel($claim),
                                'value' => 'ppn_amount',
                                'cast' => 'money',
                            ],
                            [
                                'label' => fn (ProgressClaim $claim): string => app(SubcontractFormService::class)
                                    ->claimPphLabel($claim),
                                'value' => 'pph_amount',
                                'cast' => 'money',
                            ],
                            ['label' => 'Potongan uang muka', 'value' => 'advance_recovery_amount', 'cast' => 'money'],
                            ['label' => 'JUMLAH DIBAYARKAN', 'value' => 'net_payable', 'cast' => 'money'],
                        ],
                        'empty' => 'Klaim ini tidak memiliki baris progres pekerjaan.',
                    ],
                    [
                        'id' => 'terbilang-opname',
                        'rows' => fn (ProgressClaim $claim): array => app(SubcontractFormService::class)
                            ->terbilangRow($claim),
                        'columns' => [
                            ['label' => 'JUMLAH DIBAYARKAN (Rp)', 'align' => 'right', 'width' => '42mm',
                                'value' => 'amount', 'cast' => 'money'],
                            ['label' => 'TERBILANG', 'value' => 'terbilang'],
                        ],
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Diajukan,',
                        'subheading' => 'Subkontraktor',
                        'party' => 'subcontract.vendor.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Pengawas Lapangan / QS',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Menyetujui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (Subcontract) — tambahkan dokumen baru di sini.
        ];
    }

    /**
     * KEUANGAN — lembar verifikasi tagihan, bukti pembayaran, voucher jurnal,
     * register kewajiban pajak.
     *
     * `identityHouse => false` on all four, for the reason Pengadaan states
     * once and these repeat: the ten lines that block prints (no. SPK, waktu
     * pelaksanaan, perpanjangan waktu I & II, minggu ke, hari ke, sisa hari)
     * are the SITE FILE's identity, counted against the customer's contract.
     * A payment voucher carrying "PERPANJANGAN WAKTU I : ......" invites
     * somebody to write a contract extension onto a document that cannot grant
     * one, and "HARI KE : 52" on a masa register answers a question nobody
     * asked of it.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function finance(): array
    {
        /*
         * EKUALISASI PAJAK — the row mapper and column set shared by the four
         * worksheet tables of the 'ekualisasi-pajak' entry at the bottom of
         * this method, used by nothing else. Defined once because the four
         * tables are the SAME rendering of four service payloads: a copy per
         * table would be four places for the dash-vs-zero rule below to drift
         * apart.
         *
         * THE DASH IS DELIBERATE AND LOCAL TO THIS DOCUMENT. Everywhere else
         * on these forms a null cell is RULED — dotted, "the site fills this
         * in by hand". On an ekualisasi nothing is for the pen: every figure
         * is derived, and a dotted rule under MENURUT SPT would invite a
         * number to be written onto a working paper whose whole value is that
         * the machine computed it. So a null amount prints '—' (the column
         * does not apply to that row) while a stored 0 prints 0,00 — the
         * difference between "tidak berlaku" and "nol yang teruji" is the
         * product.
         */
        $ekualisasiRows = static function (string $worksheet): \Closure {
            return static function (TaxObligation $anchor) use ($worksheet): array {
                // The string IS the public method name on the service —
                // ppnKeluaran / ppnMasukan / pph21 / pphWithholding — so a
                // grep for either finds this call site.
                $sheet = app(TaxEqualizationService::class)
                    ->{$worksheet}((int) $anchor->masa_year);

                // number_format(…, 2, ',', '.') IS the 'money' cast's own
                // format (FormPrintService::money) — pre-rendered here only
                // because the dash above needs the null branch that the cast
                // pipeline reserves for the ruled blank.
                $uang = static fn (?float $value): string => $value === null
                    ? '—'
                    : number_format($value, 2, ',', '.');

                $residualRow = static fn (array $residual): array => [
                    // Uppercase is this sheet's loudness — the generic table
                    // has no bold row class, and the residual must shout on
                    // paper exactly as it does on screen. Amount 0 prints as
                    // 0,00: the tested zero, never a suppressed row.
                    'uraian' => mb_strtoupper($residual['label']),
                    'buku' => '—',
                    'spt' => '—',
                    'selisih' => $uang($residual['amount']),
                ];

                $rows = [];
                $residualPrinted = false;

                foreach ($sheet['rows'] as $row) {
                    // Panel A of pph_dipotong owns the residual: it prints
                    // where panel A's arithmetic ends, BEFORE the soft
                    // panel-B comparison — split on the payload's own panel
                    // key, never by parsing labels.
                    if (! $residualPrinted
                        && $sheet['residual'] !== null
                        && ($row['panel'] ?? null) === 'dipotong_pelanggan') {
                        $rows[] = $residualRow($sheet['residual']);
                        $residualPrinted = true;
                    }

                    $rows[] = [
                        // Section rows are the sub-headings of the two-panel
                        // sheet; caps is what separates them from data rows.
                        'uraian' => $row['kind'] === 'section'
                            ? mb_strtoupper($row['label'])
                            : $row['label'],
                        'buku' => $uang($row['buku']),
                        'spt' => $uang($row['spt']),
                        'selisih' => $uang($row['selisih']),
                    ];
                }

                if (! $residualPrinted && $sheet['residual'] !== null) {
                    $rows[] = $residualRow($sheet['residual']);
                }

                // The worksheet's warnings reach the paper as rows — including
                // the "Tidak ada … untuk tahun N" of an empty year, which is
                // what keeps an empty sheet from printing as a bare table a
                // reader could mistake for "nothing to reconcile".
                foreach ($sheet['warnings'] as $warning) {
                    $rows[] = [
                        'uraian' => 'PERHATIAN — '.$warning,
                        'buku' => '—',
                        'spt' => '—',
                        'selisih' => '—',
                    ];
                }

                return $rows;
            };
        };

        $ekualisasiColumns = [
            ['label' => 'URAIAN', 'value' => 'uraian'],
            ['label' => 'MENURUT BUKU (Rp)', 'align' => 'right', 'width' => '42mm', 'value' => 'buku'],
            ['label' => 'MENURUT SPT/FAKTUR (Rp)', 'align' => 'right', 'width' => '42mm', 'value' => 'spt'],
            ['label' => 'SELISIH (Rp)', 'align' => 'right', 'width' => '42mm', 'value' => 'selisih'],
        ];

        return [
            /*
             * LEMBAR VERIFIKASI TAGIHAN — the sheet a verifier signs before
             * anybody releases money against a vendor's invoice.
             *
             * Not the vendor's own invoice and not a copy of it: this is OUR
             * check of it, which is why the body is the amount column
             * (FinanceFormService::billAmountRows, where the arithmetic is
             * explained) followed by a ruled kelengkapan checklist. Every
             * figure is a stored column ApBillService computed, so the sheet
             * and the journal that follows it agree to the rupiah.
             */
            'tagihan-vendor' => [
                'resource' => 'finance/ap-bills',
                'model' => ApBill::class,
                'permission' => 'fin.view',
                'label' => 'Lembar Verifikasi Tagihan',
                'formTitle' => 'LEMBAR VERIFIKASI TAGIHAN VENDOR',
                'formCode' => 'Form F/VT',
                // withTrashed on every parent — see the class docblock. This
                // sheet is the one Finance signs BEFORE paying: a bill whose
                // vendor row was deleted printed a total, a PPN and a bank
                // instruction with VENDOR and NPWP VENDOR ruled.
                'with' => [
                    'vendor' => fn ($query) => $query->withTrashed(),
                    'project' => fn ($query) => $query->withTrashed(),
                    'purchaseOrder' => fn ($query) => $query->withTrashed(),
                    'subcontractClaim' => fn ($query) => $query->withTrashed(),
                    'pphTax' => fn ($query) => $query->withTrashed(),
                    'goodsReceipt' => fn ($query) => $query->withTrashed(),
                    'billedReceipts.goodsReceipt' => fn ($query) => $query->withTrashed(),
                ],
                'header' => ['kind' => 'vendor', 'source' => 'vendor', 'project' => 'project'],
                // Dated by the BILL, never by the day somebody pressed print: a
                // reprint during an audit two years later must still be the
                // verification of the invoice dated 3 Agustus.
                'date' => 'bill_date',
                'pekerjaan' => 'description',
                'identityHouse' => false,
                'identity' => [
                    'NO. TAGIHAN' => 'code',
                    'TANGGAL TAGIHAN' => ['value' => 'bill_date', 'cast' => 'date'],
                    'JATUH TEMPO' => ['value' => 'due_date', 'cast' => 'date'],
                    'VENDOR' => 'vendor.name',
                    'NPWP VENDOR' => 'vendor.npwp',
                    'NO. FAKTUR VENDOR' => 'vendor_invoice_no',
                    // Ruled on a non-PKP vendor's bill, which is correct and
                    // not a gap: a vendor who cannot issue a faktur pajak has
                    // no number to write, and ApBillService refuses the PPN
                    // that would have gone with it.
                    'NO. FAKTUR PAJAK' => 'faktur_pajak_no',
                    'JENIS PPh DIPOTONG' => 'pphTax.name',
                    'NO. BUKTI POTONG' => 'bupot_no',
                    'DASAR PESANAN (PO)' => 'purchaseOrder.code',
                    'DASAR PENERIMAAN (GRN)' => fn (ApBill $bill): ?string => app(FinanceFormService::class)
                        ->billReceiptCodes($bill),
                    'DASAR OPNAME SUBKON' => 'subcontractClaim.code',
                    'PROYEK' => 'project.name',
                    // STATED or ruled — never re-derived. fin_ap_bills
                    // .cost_category is nullable and a null means "derive it
                    // from the source document", a derivation private to
                    // ApBillService and applied when the journal is posted. A
                    // second implementation here could disagree with the
                    // journal that actually carries the cost.
                    'KATEGORI BIAYA' => 'cost_category',
                    // Printed so an unapproved bill cannot be passed round as
                    // a verified one — the difference between the two is money.
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-tagihan',
                        'title' => 'RINCIAN NILAI TAGIHAN',
                        'rows' => fn (ApBill $bill): array => app(FinanceFormService::class)->billAmountRows($bill),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '44mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                    ],
                    [
                        'id' => 'terbilang-tagihan',
                        'rows' => fn (ApBill $bill): array => app(FinanceFormService::class)->billTerbilangRow($bill),
                        'columns' => [
                            ['label' => 'NETTO DIBAYARKAN (Rp)', 'align' => 'right', 'width' => '42mm',
                                'value' => 'amount', 'cast' => 'money'],
                            ['label' => 'TERBILANG', 'value' => 'terbilang'],
                        ],
                    ],
                    /*
                     * KELENGKAPAN DOKUMEN — the reason the sheet is called a
                     * verification. Nothing in this ERP records which physical
                     * papers arrived with an invoice, so every answer cell is
                     * RULED and only the row labels are printed: that is the
                     * owner's own paper, and a pre-ticked checklist would be a
                     * verification nobody performed.
                     */
                    [
                        'id' => 'kelengkapan-tagihan',
                        'title' => 'KELENGKAPAN DOKUMEN (diisi verifikator)',
                        'rows' => fn (): array => [
                            ['dokumen' => 'Invoice / kuitansi asli bermeterai'],
                            ['dokumen' => 'Faktur pajak (bila vendor PKP)'],
                            ['dokumen' => 'Surat jalan / berita acara serah terima barang'],
                            ['dokumen' => 'Salinan PO / SPK'],
                            ['dokumen' => 'Berita acara opname (bila pekerjaan jasa)'],
                            ['dokumen' => 'Rekening tujuan transfer atas nama vendor'],
                        ],
                        'columns' => [
                            ['label' => 'DOKUMEN YANG DIPERIKSA', 'value' => 'dokumen'],
                            ['label' => 'ADA', 'align' => 'center', 'width' => '18mm'],
                            ['label' => 'TIDAK', 'align' => 'center', 'width' => '18mm'],
                            ['label' => 'CATATAN VERIFIKATOR', 'width' => '58mm'],
                        ],
                    ],
                ],
                // A RULED pad, not the bill's description: that sentence is
                // already the PEKERJAAN line at the top of the sheet, and a
                // second copy of it under "Catatan" costs the verifier the
                // three lines he writes his findings on.
                'notes' => ['text' => null, 'lines' => 3],
                /*
                 * Three unnamed rules. core_approvals knows who pressed
                 * Setujui in this application; that is not the same claim as
                 * "this person verified the invoice and signed for it", and a
                 * name printed here would be a forged signature line on the
                 * document that releases the money.
                 */
                'signatures' => [
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Verifikator / Staf Keuangan',
                    ],
                    [
                        'heading' => 'Disetujui,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Keuangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Dibayar,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * BUKTI PEMBAYARAN / PENERIMAAN — the voucher the bank slip is
             * stapled to.
             *
             * ONE SHEET FOR BOTH DIRECTIONS, and the title says so rather than
             * saying "KAS KELUAR". fin_payments holds a receipt (RCV) and a
             * payment (PAY) in one table and one lifecycle, and the button is
             * drawn on every row of finance/payments: a sheet titled BUKTI
             * PEMBAYARAN printed over a customer's receipt would be a document
             * whose heading contradicts its own figures. The direction is the
             * FIRST line of the identity block instead, read off the column
             * that stores it.
             *
             * The PENERIMA line is the one the whole document turns on — see
             * FinanceFormService::paymentRecipient, and the class docblock
             * above it, for why a payroll transfer leaves it ruled.
             */
            'bukti-pembayaran' => [
                'resource' => 'finance/payments',
                'model' => Payment::class,
                'permission' => 'fin.view',
                'label' => 'Bukti Pembayaran / Penerimaan',
                'formTitle' => 'BUKTI PEMBAYARAN / PENERIMAAN KAS & BANK',
                'formCode' => 'Form F/BP',
                // withTrashed on the bank account and the petty-cash fund — see
                // the class docblock. A closed-and-deleted account still moved
                // this money, which is the position bankBalances() already
                // takes; MELALUI BANK ruled beside a stated amount is a voucher
                // that cannot say where the cash went. reversedBy is a User,
                // which does not soft-delete; allocations and withholdings are
                // this voucher's own rows.
                'with' => [
                    'bankAccount' => fn ($query) => $query->withTrashed(),
                    'pettyCashFund' => fn ($query) => $query->withTrashed(),
                    'allocations', 'withholdings', 'reversedBy',
                ],
                // No counterparty box: the recipient of a payment varies with
                // what it settled and is frequently unknowable (see PENERIMA
                // below). A box captioned PEMASOK / VENDOR over a customer's
                // receipt, or over a payroll transfer, would be a letterhead
                // that names the wrong party — so the band is our own
                // letterhead and the party is stated where it can be ruled.
                'header' => ['kind' => 'none'],
                'date' => 'payment_date',
                // PEKERJAAN is left RULED and there is no honest alternative:
                // fin_payments has no project column at all, one transfer can
                // settle bills across three jobs, and which job a voucher is
                // filed under is written on it by whoever files it.
                'identityHouse' => false,
                'identity' => [
                    'NO. BUKTI' => 'code',
                    'JENIS TRANSAKSI' => 'direction',
                    'TANGGAL' => ['value' => 'payment_date', 'cast' => 'date'],
                    // The caption turns on the direction. On a PAYMENT the
                    // counterparty received the money; on a RECEIPT they handed
                    // it over, and calling them the penerima would make a filed
                    // voucher say the customer took cash they had just paid us.
                    // The name itself is right either way — only the label
                    // moves. See FormPrintService::registryIdentity.
                    'PENERIMA' => [
                        'label' => fn (Payment $payment): string => $payment->direction === PaymentDirection::In
                            ? 'DITERIMA DARI'
                            : 'PENERIMA',
                        'value' => fn (Payment $payment): ?string => app(FinanceFormService::class)
                            ->paymentRecipient($payment),
                    ],
                    // "Cara bayar" is not a column anywhere in fin_payments:
                    // every row moves money through a bank account, and which
                    // account it was IS the method. The reference is the
                    // transfer number the operator typed off the bank slip.
                    'MELALUI BANK' => fn (Payment $payment): ?string => app(FinanceFormService::class)
                        ->paymentBankLine($payment),
                    'NO. REFERENSI / TRANSFER' => 'reference',
                    'DANA KAS KECIL' => 'pettyCashFund.name',
                    'STATUS' => 'status',
                    // A reversed voucher must say so on its face, with the
                    // reason: the cash it records was given back, and a
                    // reprint that looked like a live payment is exactly how a
                    // reversed transfer gets paid twice.
                    'DIBALIK PADA' => ['value' => 'reversed_at', 'cast' => 'date'],
                    'ALASAN PEMBALIKAN' => 'reversal_reason',
                ],
                'body' => [
                    [
                        'id' => 'alokasi-pembayaran',
                        'title' => 'ALOKASI KE DOKUMEN',
                        'rows' => fn (Payment $payment): array => app(FinanceFormService::class)
                            ->paymentAllocationRows($payment),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'JENIS', 'width' => '38mm', 'value' => 'jenis'],
                            ['label' => 'NO. DOKUMEN / AKUN', 'width' => '32mm', 'value' => 'dokumen'],
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'KETERANGAN', 'width' => '32mm', 'value' => 'keterangan'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '32mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                        'empty' => 'Pembayaran ini belum dialokasikan ke dokumen mana pun.',
                    ],
                    [
                        'id' => 'potongan-pembayaran',
                        'title' => 'POTONGAN (TIDAK MELALUI BANK)',
                        'rows' => fn (Payment $payment): array => app(FinanceFormService::class)
                            ->paymentDeductionRows($payment),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'JENIS POTONGAN', 'value' => 'jenis'],
                            ['label' => 'NO. BUKTI POTONG', 'width' => '32mm', 'value' => 'bukti'],
                            ['label' => 'TANGGAL BUKTI', 'align' => 'center', 'width' => '26mm',
                                'value' => 'tanggal', 'cast' => 'date'],
                            // Mandatory on a potongan lain-lain and printed on
                            // every row: a denda taken off a termin with no
                            // reason on the paper is a difference nobody can
                            // explain a year later.
                            ['label' => 'ALASAN', 'width' => '46mm', 'value' => 'alasan'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                        'empty' => 'Tidak ada potongan pada transaksi ini.',
                    ],
                    [
                        'id' => 'rekap-pembayaran',
                        'rows' => fn (Payment $payment): array => app(FinanceFormService::class)
                            ->paymentSummaryRows($payment),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '44mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                    ],
                    [
                        'id' => 'terbilang-pembayaran',
                        'rows' => fn (Payment $payment): array => app(FinanceFormService::class)
                            ->paymentTerbilangRow($payment),
                        'columns' => [
                            ['label' => 'NILAI KAS / BANK (Rp)', 'align' => 'right', 'width' => '42mm',
                                'value' => 'amount', 'cast' => 'money'],
                            ['label' => 'TERBILANG', 'value' => 'terbilang'],
                        ],
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                'signatures' => [
                    [
                        // Same inversion as the PENERIMA line above, and here
                        // it matters more: this rule is SIGNED. Asking the
                        // customer who paid us to sign under "Diterima," makes
                        // the sheet a receipt issued to the wrong party.
                        'heading' => fn (Payment $payment): string => $payment->direction === PaymentDirection::In
                            ? 'Disetorkan oleh,'
                            : 'Diterima,',
                        'subheading' => null,
                        // Named only when the settled documents prove who the
                        // counterparty is; ruled otherwise, which is the whole
                        // argument of paymentRecipient().
                        'party' => fn (Payment $payment): ?string => app(FinanceFormService::class)
                            ->paymentRecipient($payment),
                        'name' => null,
                        'role' => fn (Payment $payment): string => $payment->direction === PaymentDirection::In
                            ? 'Nama & Jabatan Penyetor'
                            : 'Nama & Jabatan Penerima',
                    ],
                    [
                        'heading' => 'Dibuat,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Kasir / Staf Keuangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Menyetujui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Keuangan',
                    ],
                ],
            ],

            /*
             * VOUCHER JURNAL — the paper behind a hand-keyed journal entry.
             *
             * No PROYEK box even though a journal LINE can carry a project:
             * one voucher can spread across several jobs, and a letterhead
             * naming the first of them would misfile the whole document. The
             * project is a column of the line table instead, where it belongs.
             *
             * The footing is a table of its own — see
             * FinanceFormService::journalRecapRows for why a totals row could
             * not carry it honestly.
             */
            'voucher-jurnal' => [
                'resource' => 'finance/journals',
                'model' => Journal::class,
                'permission' => 'fin.view',
                'label' => 'Voucher Jurnal',
                'formTitle' => 'VOUCHER JURNAL (JOURNAL VOUCHER)',
                'formCode' => 'Form F/VJ',
                // withTrashed on the account and the project a line carries —
                // see the class docblock. fin_accounts soft-deletes and a
                // journal line keeps its account_id for ever: loaded plainly a
                // posted voucher printed KODE and NAMA AKUN ruled beside a
                // debit of 1.250.000.000,00, which is a ledger entry nobody can
                // check and a blank somebody can fill in. The two users do not
                // soft-delete.
                'with' => [
                    'lines.account' => fn ($query) => $query->withTrashed(),
                    'lines.project' => fn ($query) => $query->withTrashed(),
                    'createdBy', 'postedBy',
                ],
                'header' => ['kind' => 'none'],
                'date' => 'journal_date',
                // No 'pekerjaan' and no notes text: the description is the
                // KETERANGAN line below, and printing the same sentence as the
                // subtitle, again as an identity line and again under
                // "Catatan" would crowd out the two ruled lines the reviewer
                // writes his query on.
                'identityHouse' => false,
                'identity' => [
                    'NO. VOUCHER' => 'code',
                    'TANGGAL JURNAL' => ['value' => 'journal_date', 'cast' => 'date'],
                    'KETERANGAN' => 'description',
                    // What minted it, when something did. A journal
                    // autoPost() raised carries its source document here and a
                    // hand-keyed one rules the line — which is exactly the
                    // difference an auditor is looking for on this sheet.
                    'DOKUMEN SUMBER' => fn (Journal $journal): ?string => app(FinanceFormService::class)
                        ->journalReference($journal),
                    'STATUS' => 'status',
                    // Facts, stated as facts and labelled as what they are.
                    // They are NOT printed under a signature rule: posting a
                    // journal in this application is not the same claim as
                    // signing the voucher, and the block below leaves those
                    // two rules for the pen.
                    'DIPOSTING OLEH' => 'postedBy.name',
                    'DIPOSTING PADA' => ['value' => 'posted_at', 'cast' => 'date'],
                ],
                'body' => [
                    [
                        'id' => 'baris-jurnal',
                        'title' => 'BARIS JURNAL',
                        'rows' => 'lines',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'KODE AKUN', 'width' => '22mm', 'value' => 'account.code'],
                            ['label' => 'NAMA AKUN', 'width' => '52mm', 'value' => 'account.name'],
                            ['label' => 'KETERANGAN', 'value' => 'description'],
                            ['label' => 'PROYEK', 'width' => '34mm', 'value' => 'project.name'],
                            ['label' => 'DEBIT (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'debit', 'cast' => 'money'],
                            ['label' => 'KREDIT (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'credit', 'cast' => 'money'],
                        ],
                        'empty' => 'Voucher ini belum memiliki baris jurnal.',
                    ],
                    [
                        'id' => 'rekap-jurnal',
                        'title' => 'REKAPITULASI',
                        'rows' => fn (Journal $journal): array => app(FinanceFormService::class)
                            ->journalRecapRows($journal),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '44mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                    ],
                ],
                'notes' => ['text' => null, 'lines' => 2],
                'signatures' => [
                    [
                        'heading' => 'Dibuat,',
                        'subheading' => null,
                        'party' => null,
                        // fin_journals.created_by is the MAKER of a hand-keyed
                        // voucher — the one column in this document that really
                        // does record whose entry it is. Null on a journal
                        // autoPost() minted, which then rules the line.
                        'name' => 'createdBy.name',
                        'role' => 'Penyusun',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Bagian Akuntansi',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Menyetujui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Keuangan',
                    ],
                ],
            ],

            /*
             * REGISTER KEWAJIBAN PAJAK MASA — the kalender pajak on paper
             * (temuan 25).
             *
             * NO SPA RESOURCE KEY EXISTS FOR IT, and that is stated here
             * rather than worked around: the screen is views/kalenderpajak.js,
             * a custom route ('kalenderpajak') with no schema.js RESOURCES
             * entry, so printcatalog.js has nothing to hang a button on. The
             * resource declared below is the API path the screen already
             * reads — the key this resource WOULD have if it were list-driven,
             * which is the convention every other RESOURCES key follows — so
             * the catalogue answer is right the moment that screen calls
             * printFormsFor('finance/tax-obligations'). Until it does, the
             * document is reachable by URL and by no button. See the lane
             * report's seam for kalenderpajak.js.
             *
             * A REGISTER IS A LIST and the endpoint hands one row's id, so the
             * sheet printed from any masa is the register of that masa's YEAR
             * — the same shape as the Crm guarantee register, and the sheet
             * somebody actually files.
             *
             * Landscape: twelve columns do not fit across a portrait page.
             */
            'kewajiban-pajak' => [
                'resource' => 'finance/tax-obligations',
                'model' => TaxObligation::class,
                'permission' => 'fin.view',
                'label' => 'Register Kewajiban Pajak Masa',
                'formTitle' => 'REGISTER KEWAJIBAN PAJAK MASA',
                'formCode' => 'Form F/KP',
                'orientation' => 'landscape',
                /*
                 * NO `with` AT ALL, and that is the honest declaration.
                 *
                 * Nothing on this sheet reads the anchor row: the band is our
                 * own letterhead ('none'), the identity lines are counts, and
                 * every printed cell — REF. JURNAL included — comes from
                 * FinanceFormService::taxRegister, which re-queries the whole
                 * tax year and carries the withTrashed on fin_journals there.
                 * The rule the class docblock states is obeyed at that load,
                 * which is the one that reaches the paper; an eager 'journal'
                 * here loaded the anchor's own JV and threw it away, while
                 * reading as though it were what keeps a deleted journal's
                 * number on the register.
                 */
                'header' => ['kind' => 'none'],
                // No 'date': the register is cumulative and has no document
                // date of its own, so the sheet is dated when it is printed
                // and PERIODE says which tax year it covers.
                // TIDAK ada deklarasi 'period' di sini: baris PERIODE hanya
                // dicetak lewat kepala empat-pihak proyek (FormPrintService::
                // header), dan dokumen tanpa kepala ('kind' => 'none') membuang
                // opsinya tanpa jejak — deklarasi yang pernah ada di sini mati
                // diam-diam. Rentang waktu lembar ini dinyatakan di blok
                // identitasnya, yang memang tercetak.
                'identityHouse' => false,
                'identity' => [
                    // NO 'int' CAST. That cast is number_format with a
                    // thousands separator, and it printed the most-read line
                    // on this register as "TAHUN PAJAK : 2.026". A year is a
                    // label, not a quantity.
                    'TAHUN PAJAK' => 'masa_year',
                    'NPWP PERUSAHAAN' => fn (): ?string => Company::current()?->npwp,
                    'JUMLAH MASA TERCATAT' => [
                        'value' => fn (TaxObligation $row): int => app(FinanceFormService::class)
                            ->taxRegisterCount($row),
                        'cast' => 'int',
                    ],
                    'BELUM DISETOR' => [
                        'value' => fn (TaxObligation $row): int => app(FinanceFormService::class)
                            ->taxRegisterUnpaid($row),
                        'cast' => 'int',
                    ],
                    // "Per hari cetak" in the label, because that is the only
                    // date this register has — see taxRegisterOverdue().
                    'LEWAT TENGGAT (PER HARI CETAK)' => [
                        'value' => fn (TaxObligation $row): int => app(FinanceFormService::class)
                            ->taxRegisterOverdue($row),
                        'cast' => 'int',
                    ],
                ],
                'body' => [
                    [
                        'id' => 'register-masa',
                        'title' => 'DAFTAR KEWAJIBAN PAJAK MASA',
                        'rows' => fn (TaxObligation $row): array => app(FinanceFormService::class)
                            ->taxRegisterRows($row),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'JENIS PAJAK', 'width' => '28mm', 'value' => 'jenis'],
                            ['label' => 'MASA', 'width' => '26mm', 'value' => 'masa'],
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'TENGGAT SETOR', 'align' => 'center', 'width' => '26mm',
                                'value' => 'tenggat', 'cast' => 'date'],
                            // NULLABLE by design — the calendar row is minted
                            // before the money is known — so an unpriced masa
                            // is RULED. A zero here would state that nothing
                            // is owed for that month.
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '30mm',
                                'value' => 'nilai', 'cast' => 'money'],
                            ['label' => 'NTPN', 'width' => '32mm', 'value' => 'ntpn'],
                            ['label' => 'TGL. SETOR', 'align' => 'center', 'width' => '24mm',
                                'value' => 'disetor', 'cast' => 'date'],
                            ['label' => 'TGL. LAPOR', 'align' => 'center', 'width' => '24mm',
                                'value' => 'dilapor', 'cast' => 'date'],
                            ['label' => 'STATUS', 'align' => 'center', 'width' => '24mm', 'value' => 'status'],
                            ['label' => 'REF. JURNAL', 'width' => '26mm', 'value' => 'jurnal'],
                            ['label' => 'KETERANGAN', 'width' => '36mm', 'value' => 'catatan'],
                        ],
                        'totals' => [
                            [
                                // The label counts what it summed, because
                                // half the amounts are legitimately unfilled.
                                'label' => fn (TaxObligation $row): string => app(FinanceFormService::class)
                                    ->taxRegisterTotalLabel($row),
                                'value' => fn (TaxObligation $row): ?float => app(FinanceFormService::class)
                                    ->taxRegisterTotal($row),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Belum ada kewajiban masa tercatat untuk tahun pajak ini.',
                    ],
                ],
                // RULED, not read from the anchor row. This sheet is a
                // YEAR-WIDE register printed from whichever masa the button
                // happened to be pressed on, so 'notes' => 'notes' made the
                // same register carry a different Catatan depending on the
                // row clicked — and printed that row's note twice, once here
                // and once in its own KETERANGAN cell. A register has no
                // single row's note to carry; the block is for the pen.
                'notes' => ['text' => null, 'lines' => 2],
                'signatures' => [
                    [
                        'heading' => 'Disusun,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Staf Pajak',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Keuangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],
            /*
             * EKUALISASI PAJAK — buku vs SPT, one fiscal year, four worksheets
             * on one landscape sheet (Form F/EQ). The working papers a
             * pemeriksa pajak (or an SP2DK letter) asks for, and the copy that
             * leaves the building — which is why the honesty rule matters MORE
             * here, not less: a residual forced to zero on this paper is a
             * forged working paper with our signature block under it.
             *
             * ANCHORED LIKE THE MASA REGISTER ABOVE: the endpoint hands one
             * fin_tax_obligations row's id and the sheet prints the whole
             * ekualisasi of that row's masa_year. Like that register, NO SPA
             * RESOURCE KEY exists — 'finance/tax-obligations' is the API path
             * the screens already read — and the button is views/ekualisasi.js
             * own hard-coded core/print/forms call, with a reason on it while
             * the chosen year has no masa row to anchor on.
             *
             * EVERY CELL COMES THROUGH TaxEqualizationService — the same
             * service the screen renders — so paper and screen can never
             * disagree about a figure and the residual is computed exactly
             * once. The four rows closures map payloads to cells and do NO
             * arithmetic; see $ekualisasiRows at the top of this method for
             * the one mapping (and the dash-vs-zero rule) they share.
             *
             * Landscape: three 42mm money columns beside labels the length of
             * "Pendapatan konstruksi/integrasi pada bulan tanpa run pengakuan
             * terposting (Februari)" do not fit across a portrait page.
             */
            'ekualisasi-pajak' => [
                'resource' => 'finance/tax-obligations',
                'model' => TaxObligation::class,
                'permission' => 'fin.view',
                'label' => 'Ekualisasi Pajak',
                'formTitle' => 'EKUALISASI PAJAK',
                'formCode' => 'Form F/EQ',
                'orientation' => 'landscape',
                // No `with` at all — the honest declaration, as on the masa
                // register: nothing on this sheet reads the anchor row beyond
                // masa_year, and every printed cell is re-queried by the
                // service against the whole tax year.
                'header' => ['kind' => 'none'],
                // No 'date': the working papers are cumulative for a year and
                // dated when printed; PERIODE says which tax year they cover.
                // TIDAK ada deklarasi 'period' di sini: baris PERIODE hanya
                // dicetak lewat kepala empat-pihak proyek (FormPrintService::
                // header), dan dokumen tanpa kepala ('kind' => 'none') membuang
                // opsinya tanpa jejak — deklarasi yang pernah ada di sini mati
                // diam-diam. Rentang waktu lembar ini dinyatakan di blok
                // identitasnya, yang memang tercetak.
                'identityHouse' => false,
                'identity' => [
                    // NO 'int' CAST — the kewajiban-pajak lesson: that cast is
                    // number_format with a thousands separator and printed
                    // "TAHUN PAJAK : 2.026". A year is a label, not a
                    // quantity.
                    'TAHUN PAJAK' => 'masa_year',
                    'NPWP PERUSAHAAN' => fn (): ?string => Company::current()?->npwp,
                ],
                'body' => [
                    [
                        'id' => 'ekualisasi-ppn-keluaran',
                        'title' => 'EKUALISASI PPN KELUARAN',
                        'rows' => $ekualisasiRows('ppnKeluaran'),
                        'columns' => $ekualisasiColumns,
                        'empty' => 'Tidak ada data PPN keluaran untuk tahun pajak ini.',
                    ],
                    [
                        'id' => 'ekualisasi-ppn-masukan',
                        'title' => 'EKUALISASI PPN MASUKAN',
                        'rows' => $ekualisasiRows('ppnMasukan'),
                        'columns' => $ekualisasiColumns,
                        'empty' => 'Tidak ada data PPN masukan untuk tahun pajak ini.',
                    ],
                    [
                        'id' => 'ekualisasi-pph21',
                        'title' => 'EKUALISASI PPH 21',
                        'rows' => $ekualisasiRows('pph21'),
                        'columns' => $ekualisasiColumns,
                        'empty' => 'Tidak ada data PPh 21 untuk tahun pajak ini.',
                    ],
                    [
                        'id' => 'ekualisasi-pph-dipotong',
                        'title' => 'EKUALISASI PPH DIPOTONG',
                        'rows' => $ekualisasiRows('pphWithholding'),
                        'columns' => $ekualisasiColumns,
                        'empty' => 'Tidak ada data PPh dipotong untuk tahun pajak ini.',
                    ],
                ],
                // RULED, for the pemeriksa's own pen — the one part of this
                // sheet that is legitimately handwritten.
                'notes' => ['text' => null, 'lines' => 2],
                'signatures' => [
                    [
                        'heading' => 'Disusun,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Staf Pajak',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Keuangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (Finance) — tambahkan dokumen baru di sini.
        ];
    }

    /**
     * SDM — rekap gaji, pengajuan cuti, daftar hadir.
     *
     * THE PERSONAL-DATA POSTURE these three inherit is stated once, in
     * Modules\HrPayroll\Services\HrFormService: no KTP number, no NPWP, no
     * bank account on any printed sheet, because a printout is the one
     * artefact that leaves the permission system entirely. The per-employee
     * slip gaji that legitimately carries those already exists as a dompdf
     * document and is NOT duplicated here.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function hr(): array
    {
        return [
            /*
             * REKAP GAJI — one run, one page per twenty-odd employees, and the
             * sheet HR, Finance and the director sign before the transfer file
             * is uploaded.
             *
             * Landscape, because the row has to ADD UP across the page: pokok
             * + tunjangan + lembur + THR = bruto and bruto − potongan = netto
             * is ten columns, and a rekap whose columns do not reconcile is a
             * rekap nobody can check.
             */
            'rekap-payroll' => [
                'resource' => 'hr/payroll-runs',
                'model' => PayrollRun::class,
                'permission' => 'hr.view',
                'label' => 'Rekap Gaji',
                'formTitle' => 'REKAPITULASI GAJI KARYAWAN',
                'formCode' => 'Form F/RG',
                'orientation' => 'landscape',
                /*
                 * withTrashed on the employee, and this is the WORST CASE the
                 * class docblock's rule exists for: MONEY AGAINST A NAMELESS
                 * ROW. hr_employees soft-deletes on the ordinary path — somebody
                 * leaves and the record is removed — while the payslip that
                 * paid them stays for ever. Loaded plainly the row printed
                 * NAMA KARYAWAN and JABATAN ruled beside a netto of
                 * 8.450.000,00, on the sheet HR, Finance and the director sign
                 * before the transfer file is uploaded, and JUMLAH KARYAWAN
                 * counted a person the sheet could not name.
                 */
                'with' => [
                    'payslips.employee' => fn ($query) => $query->withTrashed(),
                ],
                // A payroll run belongs to no customer and no job — a run can
                // span every project at once — so the band is our own
                // letterhead and nothing else.
                'header' => ['kind' => 'none'],
                // Dated at the END of the period it pays, the same date
                // PayrollPostingService journals it on, so the sheet and the
                // ledger name the same month. Never the day somebody printed.
                'date' => fn (PayrollRun $run): string => Carbon::create(
                    (int) $run->period_year, (int) $run->period_month, 1
                )->endOfMonth()->toDateString(),
                // TIDAK ada deklarasi 'period' di sini: baris PERIODE hanya
                // dicetak lewat kepala empat-pihak proyek (FormPrintService::
                // header), dan dokumen tanpa kepala ('kind' => 'none') membuang
                // opsinya tanpa jejak — deklarasi yang pernah ada di sini mati
                // diam-diam. Rentang waktu lembar ini dinyatakan di blok
                // identitasnya, yang memang tercetak.
                'title' => fn (PayrollRun $run): string => 'REKAP GAJI '.mb_strtoupper(
                    (string) app(HrFormService::class)->periodLabel((int) $run->period_month, (int) $run->period_year)
                ),
                'identityHouse' => false,
                'identity' => [
                    'NO. RUN GAJI' => 'code',
                    'PERIODE' => fn (PayrollRun $run): ?string => app(HrFormService::class)
                        ->periodLabel((int) $run->period_month, (int) $run->period_year),
                    'JENIS RUN' => 'run_type',
                    // Nullable until the run is scheduled. Ruled on a draft:
                    // a pay date nobody has set is not a pay date.
                    'TANGGAL PEMBAYARAN' => ['value' => 'payment_date', 'cast' => 'date'],
                    'JUMLAH KARYAWAN' => [
                        'value' => fn (PayrollRun $run): int => $run->payslips->count(),
                        'cast' => 'int',
                    ],
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'rincian-gaji',
                        'title' => 'RINCIAN GAJI PER KARYAWAN',
                        'rows' => fn (PayrollRun $run): array => app(HrFormService::class)->payrollRows($run),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'NAMA KARYAWAN', 'value' => 'nama'],
                            ['label' => 'JABATAN', 'width' => '32mm', 'value' => 'jabatan'],
                            ['label' => 'GAJI POKOK (Rp)', 'align' => 'right', 'width' => '27mm',
                                'value' => 'pokok', 'cast' => 'money'],
                            ['label' => 'TUNJANGAN (Rp)', 'align' => 'right', 'width' => '25mm',
                                'value' => 'tunjangan', 'cast' => 'money'],
                            ['label' => 'LEMBUR (Rp)', 'align' => 'right', 'width' => '23mm',
                                'value' => 'lembur', 'cast' => 'money'],
                            ['label' => 'THR (Rp)', 'align' => 'right', 'width' => '23mm',
                                'value' => 'thr', 'cast' => 'money'],
                            ['label' => 'BRUTO (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'bruto', 'cast' => 'money'],
                            ['label' => 'POTONGAN (Rp)', 'align' => 'right', 'width' => '27mm',
                                'value' => 'potongan', 'cast' => 'money'],
                            ['label' => 'NETTO (Rp)', 'align' => 'right', 'width' => '29mm',
                                'value' => 'netto', 'cast' => 'money'],
                        ],
                        'totals' => [
                            /*
                             * The run's own stored total, under the NETTO
                             * column it totals — not a second sum taken here.
                             *
                             * CAPTIONED FOR THE COLUMN IT FOOTS, not with the
                             * sheet's headline. total_net is printed three
                             * times on this form — here, as the third line of
                             * the REKAPITULASI identity, and in the terbilang
                             * box — and two of those carried the WORD-FOR-WORD
                             * caption "JUMLAH NETTO DIBAYARKAN". Two cells with
                             * the same caption and the same figure read as two
                             * independent checks of one number: a reader who
                             * finds them agreeing has learnt nothing, and had
                             * they ever disagreed nobody could say which was
                             * wrong. Each caption now states what its own cell
                             * counted — this one foots the column of employee
                             * nettos directly above it.
                             */
                            ['label' => 'JUMLAH NETTO SELURUH KARYAWAN', 'value' => 'total_net', 'cast' => 'money'],
                        ],
                        'empty' => 'Run gaji ini belum dihitung: belum ada slip gaji.',
                    ],
                    [
                        'id' => 'rekap-gaji',
                        'title' => 'REKAPITULASI',
                        'rows' => fn (PayrollRun $run): array => app(HrFormService::class)->payrollRecapRows($run),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'NILAI (Rp)', 'align' => 'right', 'width' => '44mm',
                                'value' => 'nilai', 'cast' => 'money'],
                        ],
                    ],
                    [
                        'id' => 'terbilang-gaji',
                        'rows' => fn (PayrollRun $run): array => app(HrFormService::class)->payrollTerbilangRow($run),
                        'columns' => [
                            ['label' => 'NETTO DIBAYARKAN (Rp)', 'align' => 'right', 'width' => '42mm',
                                'value' => 'amount', 'cast' => 'money'],
                            ['label' => 'TERBILANG', 'value' => 'terbilang'],
                        ],
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 2],
                'signatures' => [
                    [
                        'heading' => 'Dibuat,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Bagian SDM',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Keuangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Menyetujui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],

            /*
             * PENGAJUAN CUTI / IZIN — the form the employee signs, the
             * supervisor approves and HR files.
             *
             * The saldo block is the reason this sheet is worth printing at
             * all: LeaveService computes it from join_date and the approved
             * rows and never stores it, so the paper is the only place the
             * three parties see the same number at the same moment. It exists
             * for cuti tahunan and nowhere else — see
             * HrFormService::leaveBalanceRows.
             */
            'pengajuan-cuti' => [
                'resource' => 'hr/leave-requests',
                'model' => LeaveRequest::class,
                'permission' => 'hr.view',
                'label' => 'Pengajuan Cuti',
                'formTitle' => 'FORMULIR PENGAJUAN CUTI / IZIN',
                'formCode' => 'Form F/PC',
                // withTrashed on the employee — see the class docblock. This
                // form is filed and kept: an application printed after the
                // person left would carry the dates, the day count and the
                // saldo with the applicant's own name ruled, over a signature
                // rule labelled Pemohon.
                'with' => [
                    'employee' => fn ($query) => $query->withTrashed(),
                ],
                'header' => ['kind' => 'employee', 'source' => 'employee'],
                // hr_leave_requests has no request-date column: created_at IS
                // the day it was raised. Named here so a reprint next year is
                // still dated when the employee asked.
                'date' => 'created_at',
                'identityHouse' => false,
                'identity' => [
                    'NO. PENGAJUAN' => 'code',
                    'TANGGAL PENGAJUAN' => ['value' => 'created_at', 'cast' => 'date'],
                    'NAMA PEGAWAI' => 'employee.name',
                    // The EMPLOYEE code, never nik_ktp — see the posture note
                    // on HrFormService.
                    'NIK PEGAWAI' => 'employee.code',
                    'JABATAN' => 'employee.position',
                    // Through Department::labelFor, not straight off the
                    // column: hr_employees.department is a plain string with
                    // no cast, so the raw slug was printing — "DEPARTEMEN :
                    // hrga" on a form signed by three people, while the screen
                    // it was raised on says "HR & GA". An unknown slug still
                    // prints as itself.
                    'DEPARTEMEN' => fn (LeaveRequest $request): ?string => Department::labelFor(
                        $request->employee?->department,
                    ),
                    'TANGGAL MASUK KERJA' => ['value' => 'employee.join_date', 'cast' => 'date'],
                    'JENIS PENGAJUAN' => 'leave_type',
                    'MULAI TANGGAL' => ['value' => 'start_date', 'cast' => 'date'],
                    'SAMPAI TANGGAL' => ['value' => 'end_date', 'cast' => 'date'],
                    // Counted by LeaveService::workingDays under the configured
                    // workweek, stored on the row, and printed from the row —
                    // so the sheet and the saldo debit agree.
                    'JUMLAH HARI KERJA' => ['value' => 'day_count', 'cast' => 'int'],
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'saldo-cuti',
                        'title' => 'SALDO CUTI TAHUNAN',
                        'rows' => fn (LeaveRequest $request): array => app(HrFormService::class)
                            ->leaveBalanceRows($request),
                        'columns' => [
                            ['label' => 'URAIAN', 'value' => 'uraian'],
                            ['label' => 'JUMLAH', 'align' => 'right', 'width' => '52mm', 'value' => 'nilai'],
                        ],
                        'empty' => 'Saldo cuti tahunan tidak berlaku untuk jenis pengajuan ini.',
                    ],
                    /*
                     * Two ruled pads, and both are honest gaps rather than
                     * laziness: nothing in hr_leave_requests records where the
                     * employee can be reached or who covers the work, and both
                     * are what a supervisor actually decides on. The paper has
                     * always had them; so does this.
                     */
                    [
                        'id' => 'kontak-cuti',
                        'title' => 'ALAMAT & KONTAK SELAMA CUTI (diisi pemohon)',
                        'columns' => [
                            ['label' => 'ALAMAT / NOMOR YANG DAPAT DIHUBUNGI'],
                        ],
                        'minRows' => 2,
                    ],
                    [
                        'id' => 'delegasi-cuti',
                        'title' => 'PEKERJAAN YANG DIDELEGASIKAN (diisi pemohon & atasan)',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm'],
                            ['label' => 'URAIAN PEKERJAAN'],
                            ['label' => 'DIALIHKAN KEPADA', 'width' => '52mm'],
                        ],
                        'minRows' => 3,
                    ],
                ],
                'notes' => ['text' => 'reason', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Pemohon,',
                        'subheading' => null,
                        'party' => null,
                        // employee_id really does record whose request this is
                        // — and LeaveService keeps it immovable for exactly
                        // that reason — so this one rule carries a name.
                        'name' => 'employee.name',
                        'role' => 'Pemohon',
                    ],
                    [
                        'heading' => 'Menyetujui,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Atasan Langsung',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Bagian SDM',
                    ],
                ],
            ],

            /*
             * DAFTAR HADIR HARIAN — the sheet the site passes round for wet
             * signatures.
             *
             * WHICH TABLE IT IS PRINTED FROM was the choice on this document,
             * and hr_attendances is the only possible answer. The sheet is
             * defined as one PROJECT on one DATE, and that pair exists nowhere
             * else: hr_attendance_recaps is (employee, year, month) — one
             * person for a whole month, with no project column at all — so a
             * daftar hadir built from it could not name the site it was signed
             * on, and a "monthly attendance sheet per employee" is a different
             * document that nobody signs.
             *
             * As with the Crm guarantee register, the endpoint hands ONE row's
             * id and the sheet is the whole register that row belongs to. The
             * SPA has no RESOURCES key for it either — views/absensi.js is a
             * custom route — so 'hr/attendances' is declared as the API path
             * the screen already reads, and the button appears the moment that
             * screen asks the catalogue for it (see the lane report's seam).
             *
             * Landscape: nine columns, three of them wide enough to sign in.
             */
            'daftar-hadir' => [
                'resource' => 'hr/attendances',
                'model' => Attendance::class,
                'permission' => 'hr.view',
                'label' => 'Daftar Hadir Harian',
                'formTitle' => 'DAFTAR HADIR HARIAN',
                'formCode' => 'Form F/DH',
                'orientation' => 'landscape',
                /*
                 * ONE relation, and the anchor's EMPLOYEE is deliberately not
                 * it.
                 *
                 * Nothing on this sheet reads the anchor row's employee. The
                 * band takes the project, the title takes the project, and
                 * every printed name comes from HrFormService::attendanceRegister,
                 * which re-queries the whole register — the anchor row included
                 * — under its own withTrashed. An 'employee' load here was a
                 * query per print whose result was thrown away, and worse, it
                 * read as the thing that keeps a departed worker's name on the
                 * paper when the register is what actually does that.
                 *
                 * withTrashed on the project because that one IS read, and for
                 * the class docblock's reason: prj_projects soft-deletes, the
                 * attendance row keeps its project_id, and loaded plainly a
                 * site register came back with an empty PROYEK box, no centred
                 * title and no contract identity lines — an undated-looking
                 * office sheet for a day's work on a named job.
                 */
                'with' => [
                    'project' => fn ($query) => $query->withTrashed(),
                ],
                /*
                 * kind 'none' with the project box, rather than the four-party
                 * band, and the reason is the office sheet: hr_attendances
                 * .project_id is nullable, and a band captioned PEMILIK /
                 * KONSULTAN MK over an attendance list for head office would
                 * print two empty boxes naming parties who do not exist. This
                 * way the PROYEK box appears exactly when the register has a
                 * project, and the contract identity lines come with it —
                 * "hari ke 52" on a site attendance sheet is the same day
                 * count the laporan harian of that day carries, counted once.
                 */
                'header' => ['kind' => 'none', 'project' => 'project'],
                'date' => 'date',
                'identity' => [
                    // Kept even though a site sheet's house block already
                    // carries TANGGAL: an OFFICE register (project_id null)
                    // resolves no project, therefore no house block at all,
                    // and an undated signature list is worth nothing. One
                    // repeated date beats a sheet nobody can file. The project
                    // is NOT repeated here — the band box and the centred
                    // title both name it.
                    'TANGGAL DAFTAR HADIR' => ['value' => 'date', 'cast' => 'date'],
                    'JUMLAH TERCATAT' => [
                        'value' => fn (Attendance $row): int => app(HrFormService::class)->attendanceCount($row),
                        'cast' => 'int',
                    ],
                    'REKAPITULASI' => fn (Attendance $row): string => app(HrFormService::class)
                        ->attendanceRecap($row),
                ],
                'body' => [
                    [
                        'id' => 'daftar-hadir-pekerja',
                        'title' => 'DAFTAR HADIR PEKERJA',
                        'rows' => fn (Attendance $row): array => app(HrFormService::class)->attendanceRows($row),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            // NIK PEGAWAI, never bare NIK, and never nik_ktp:
                            // this column carries hr_employees.code (EMP-0007).
                            // The sister sheet spells it the same way for the
                            // same reason — a signature list that circulates by
                            // photocopy and heads a column NIK invites its
                            // reader to treat the payroll code as an identity
                            // number, which is the one thing HrFormService's
                            // personal-data posture keeps off every sheet.
                            ['label' => 'NIK PEGAWAI', 'width' => '24mm', 'value' => 'kode'],
                            ['label' => 'NAMA', 'value' => 'nama'],
                            ['label' => 'JABATAN', 'width' => '34mm', 'value' => 'jabatan'],
                            ['label' => 'STATUS', 'align' => 'center', 'width' => '24mm', 'value' => 'status'],
                            /*
                             * Four columns with NO value spec at all, which is
                             * how the generic sheet is told to rule them. That
                             * is the point of the document: nothing in
                             * hr_attendances records a clock time, and the
                             * signature is the wet ink the sheet is printed to
                             * collect. A column that filled itself would
                             * defeat it.
                             */
                            ['label' => 'JAM MASUK', 'align' => 'center', 'width' => '20mm'],
                            ['label' => 'JAM KELUAR', 'align' => 'center', 'width' => '20mm'],
                            ['label' => 'KETERANGAN', 'width' => '42mm', 'value' => 'keterangan'],
                            ['label' => 'TANDA TANGAN', 'align' => 'center', 'width' => '38mm'],
                        ],
                        // The rows the ERP has, then ruled lines for the
                        // workers nobody keyed — a site sheet is filled in as
                        // people arrive, and a register with no room to add
                        // one gets rewritten on the back of the page.
                        'minRows' => 20,
                    ],
                ],
                'notes' => ['text' => null, 'lines' => 2],
                'signatures' => [
                    [
                        'heading' => 'Dibuat,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Administrasi Proyek',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Pengawas Lapangan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (HrPayroll) — tambahkan dokumen baru di sini.
        ];
    }

    /**
     * LAYANAN — berita acara servis, ringkasan kontrak layanan.
     *
     * Both carry the counterparty band with the CUSTOMER in it and no PROYEK
     * box: a service ticket belongs to a maintenance contract and a site, not
     * to a construction job, and prj_projects has nothing to do with either.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function serviceDesk(): array
    {
        return [
            /*
             * BERITA ACARA PEKERJAAN SERVIS — the paper the technician leaves
             * behind and the customer signs.
             *
             * THE CUSTOMER'S SIGNATURE LINE is the decision on this sheet, and
             * it is made in ServiceDeskFormService::reportSignatory where the
             * prose fits: svc_field_reports.customer_sign_name can be written
             * by create() and update() and is "not proof of a signature on its
             * own" (FieldReportService's own words). Only an ACKNOWLEDGED
             * report with customer_signed_at set prints a name; every other
             * state gets the blank rule.
             */
            'berita-acara-servis' => [
                'resource' => 'servicedesk/field-reports',
                'model' => FieldReport::class,
                'permission' => 'svc.view',
                'label' => 'Berita Acara Servis',
                'formTitle' => 'BERITA ACARA PEKERJAAN SERVIS',
                'formCode' => 'Form F/BS',
                // withTrashed on every soft-deleting parent — see the class
                // docblock. The customer here is the sheet's counterparty AND
                // the party the customer-signature column is headed with; the
                // technician is the one name this document really records.
                'with' => [
                    'ticket' => fn ($query) => $query->withTrashed(),
                    'ticket.customer' => fn ($query) => $query->withTrashed(),
                    'ticket.serviceContract' => fn ($query) => $query->withTrashed(),
                    'technician' => fn ($query) => $query->withTrashed(),
                    'warehouse' => fn ($query) => $query->withTrashed(),
                    'parts.item' => fn ($query) => $query->withTrashed(),
                    'ticket.site',
                ],
                'header' => ['kind' => 'customer', 'source' => 'ticket.customer'],
                // Dated by the VISIT, never by the day somebody pressed print:
                // report_date is also the date acknowledge() posts the parts
                // on, and a berita acara that re-dated itself would contradict
                // the stock movement it authorised.
                'date' => 'report_date',
                'pekerjaan' => 'ticket.title',
                'identityHouse' => false,
                'identity' => [
                    'NO. BERITA ACARA' => 'code',
                    'TANGGAL KUNJUNGAN' => ['value' => 'report_date', 'cast' => 'date'],
                    'NO. TIKET' => 'ticket.code',
                    'PELANGGAN' => 'ticket.customer.name',
                    'LOKASI' => fn (FieldReport $report): ?string => app(ServiceDeskFormService::class)
                        ->reportLocation($report),
                    'NO. KONTRAK LAYANAN' => 'ticket.serviceContract.code',
                    'KATEGORI TIKET' => 'ticket.category',
                    'PRIORITAS' => 'ticket.priority',
                    'DILAPORKAN PADA' => ['value' => 'ticket.reported_at', 'cast' => 'date'],
                    'TEKNISI PELAKSANA' => 'technician.name',
                    'GUDANG ASAL SUKU CADANG' => 'warehouse.name',
                    'STATUS' => 'status',
                    // The timestamp acknowledge() wrote — the fact that makes
                    // the name under the signature rule below a signature.
                    // Ruled on anything not yet acknowledged.
                    'DITANDATANGANI PELANGGAN' => ['value' => 'customer_signed_at', 'cast' => 'date'],
                ],
                'body' => [
                    [
                        'id' => 'uraian-servis',
                        'title' => 'URAIAN PEKERJAAN',
                        'rows' => fn (FieldReport $report): array => app(ServiceDeskFormService::class)
                            ->reportNarrativeRows($report),
                        'columns' => [
                            ['label' => 'URAIAN', 'width' => '46mm', 'value' => 'uraian'],
                            ['label' => 'KETERANGAN', 'value' => 'keterangan'],
                        ],
                    ],
                    [
                        'id' => 'suku-cadang',
                        'title' => 'SUKU CADANG YANG DIGUNAKAN',
                        'rows' => fn (FieldReport $report): array => app(ServiceDeskFormService::class)
                            ->reportPartRows($report),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'KODE', 'width' => '24mm', 'value' => 'kode'],
                            ['label' => 'NAMA BARANG', 'value' => 'nama'],
                            ['label' => 'QTY', 'align' => 'right', 'width' => '18mm',
                                'value' => 'qty', 'cast' => 'qty'],
                            ['label' => 'SAT', 'align' => 'center', 'width' => '16mm', 'value' => 'satuan'],
                            ['label' => 'KETERANGAN', 'width' => '48mm', 'value' => 'keterangan'],
                        ],
                        // A sentence, and NO ruled pad — see reportPartRows():
                        // acknowledging this report issues these parts from
                        // the warehouse, so blank rows would invite writing in
                        // a part that never left the shelf.
                        'empty' => 'Kunjungan ini tidak menggunakan suku cadang.',
                    ],
                ],
                'notes' => ['text' => null, 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Diterima & disetujui,',
                        'subheading' => 'Pelanggan',
                        'party' => 'ticket.customer.name',
                        'name' => fn (FieldReport $report): ?string => app(ServiceDeskFormService::class)
                            ->reportSignatory($report),
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Dikerjakan oleh,',
                        'subheading' => null,
                        'party' => null,
                        // technician_employee_id genuinely records who did the
                        // work, so this rule carries a name.
                        'name' => 'technician.name',
                        'role' => 'Teknisi',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Layanan',
                    ],
                ],
            ],

            /*
             * RINGKASAN KONTRAK LAYANAN — what we promised, where, and how
             * often.
             *
             * The sheet a service manager takes to a renewal meeting, so the
             * two SLA lines and the remaining-days line sit in the identity
             * block where they are read first, and the PM schedule lists the
             * DEACTIVATED rows too — see contractScheduleRows().
             */
            'kontrak-layanan' => [
                'resource' => 'servicedesk/contracts',
                'model' => ServiceContract::class,
                'permission' => 'svc.view',
                'label' => 'Ringkasan Kontrak Layanan',
                'formTitle' => 'RINGKASAN KONTRAK LAYANAN',
                'formCode' => 'Form F/KL',
                // withTrashed on the customer, the parent construction contract
                // and the engineer a PM visit is assigned to — see the class
                // docblock. NOT on `preventiveSchedules` or `sites`: those are
                // this contract's own rows, and a schedule somebody deleted has
                // no business reappearing on a summary sheet (the DEACTIVATED
                // ones are listed on purpose and are a different state).
                'with' => [
                    'customer' => fn ($query) => $query->withTrashed(),
                    'crmContract' => fn ($query) => $query->withTrashed(),
                    'preventiveSchedules.assignee' => fn ($query) => $query->withTrashed(),
                    'sites', 'preventiveSchedules.site',
                ],
                'header' => ['kind' => 'customer', 'source' => 'customer'],
                'pekerjaan' => 'name',
                // A summary sheet has no document date of its own: it answers
                // "as at today", which is exactly what SISA MASA BERLAKU below
                // is counted to. No 'date' key, so the URL's ?tanggal= wins
                // and today is the fallback.
                'identityHouse' => false,
                'identity' => [
                    'NO. KONTRAK LAYANAN' => 'code',
                    // No NAMA KONTRAK line: the contract's name is already the
                    // PEKERJAAN subtitle two lines above.
                    'PELANGGAN' => 'customer.name',
                    'ALAMAT PELANGGAN' => 'customer.billing_address',
                    // The construction contract this maintenance hangs off,
                    // when there is one — a CCTV job handed over into its own
                    // warranty period is the ordinary case, and the link is
                    // what lets somebody find the BAST.
                    'NO. KONTRAK INDUK' => 'crmContract.code',
                    'PERIODE MULAI' => ['value' => 'period_start', 'cast' => 'date'],
                    'PERIODE SELESAI' => ['value' => 'period_end', 'cast' => 'date'],
                    // Counted from the SHEET's date, which is the ?tanggal= the
                    // caller asked for and otherwise today. contractRemaining()
                    // has always taken an $asOf; nobody passed it, so a sheet
                    // headed "01 Januari 2026" was reporting the days left as
                    // at the day it was printed — for a period that, on the
                    // date the sheet claimed, had not begun.
                    'SISA MASA BERLAKU' => fn (ServiceContract $contract, Carbon $date): ?string => app(ServiceDeskFormService::class)
                        ->contractRemaining($contract, $date),
                    'NILAI KONTRAK' => ['value' => 'contract_value', 'cast' => 'rupiah'],
                    'SIKLUS PENAGIHAN' => 'billing_cycle',
                    // ServiceContract::billingAmountPerPeriod(), never a second
                    // division written here: the figure on this sheet is the
                    // one the invoice is raised for.
                    'NILAI PER PERIODE TAGIH' => [
                        'value' => fn (ServiceContract $contract): float => $contract->billingAmountPerPeriod(),
                        'cast' => 'rupiah',
                    ],
                    'SLA WAKTU TANGGAP' => fn (ServiceContract $contract): ?string => app(ServiceDeskFormService::class)
                        ->slaLine($contract->sla_response_hours),
                    'SLA WAKTU PENYELESAIAN' => fn (ServiceContract $contract): ?string => app(ServiceDeskFormService::class)
                        ->slaLine($contract->sla_resolution_hours),
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'lokasi-layanan',
                        'title' => 'LOKASI YANG DILAYANI',
                        'rows' => fn (ServiceContract $contract): array => app(ServiceDeskFormService::class)
                            ->contractSiteRows($contract),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'NAMA LOKASI', 'width' => '44mm', 'value' => 'lokasi'],
                            ['label' => 'ALAMAT', 'value' => 'alamat'],
                            ['label' => 'KOTA', 'width' => '26mm', 'value' => 'kota'],
                            ['label' => 'PIC LOKASI', 'width' => '32mm', 'value' => 'pic'],
                            ['label' => 'TELEPON', 'width' => '28mm', 'value' => 'telepon'],
                        ],
                        'empty' => 'Kontrak ini belum mencantumkan lokasi yang dilayani.',
                    ],
                    [
                        'id' => 'jadwal-pm',
                        'title' => 'JADWAL PEMELIHARAAN BERKALA (PM)',
                        'rows' => fn (ServiceContract $contract): array => app(ServiceDeskFormService::class)
                            ->contractScheduleRows($contract),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm', 'value' => 'no'],
                            ['label' => 'PEKERJAAN', 'value' => 'pekerjaan'],
                            ['label' => 'LOKASI', 'width' => '38mm', 'value' => 'lokasi'],
                            ['label' => 'FREKUENSI', 'align' => 'center', 'width' => '24mm', 'value' => 'frekuensi'],
                            ['label' => 'JATUH TEMPO BERIKUT', 'align' => 'center', 'width' => '28mm',
                                'value' => 'jatuh_tempo', 'cast' => 'date'],
                            ['label' => 'PETUGAS', 'width' => '32mm', 'value' => 'petugas'],
                            // Ya / Tidak rather than a tick and a blank: a
                            // ruled cell in this column would let somebody
                            // activate a schedule with a pen.
                            ['label' => 'AKTIF', 'align' => 'center', 'width' => '16mm', 'value' => 'aktif'],
                        ],
                        'empty' => 'Kontrak ini belum memiliki jadwal pemeliharaan berkala.',
                    ],
                ],
                // svc_contracts.coverage is the scope-and-exclusions paragraph
                // the customer signed; ruled when the contract records none.
                'notes' => ['text' => 'coverage', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Mengetahui,',
                        'subheading' => 'Pelanggan',
                        'party' => 'customer.name',
                        'name' => null,
                        'role' => 'Nama & Jabatan',
                    ],
                    [
                        'heading' => 'Diperiksa,',
                        'subheading' => null,
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Layanan',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Hormat kami,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Direktur',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (ServiceDesk) — tambahkan dokumen baru di sini.
        ];
    }

    /**
     * ASET — the equipment card and the mobilisation berita acara.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function assets(): array
    {
        return [
            /*
             * KARTU ASET — one page per machine: what it cost, what it is
             * worth now, where it has been and what has been done to it.
             *
             * THE ONLY HOUSE FORM IN THIS ERP WHOSE FIGURES MOVE UNDER THE
             * READER. accumulated_depreciation and book_value are TODAY'S,
             * rewritten by every posted depreciation run, and ast_assets keeps
             * no history of what they were last quarter. So the card is dated
             * by the printer (a declared date wins over ?tanggal=) and says on
             * its face which day the figures belong to.
             */
            'kartu-aset' => [
                'resource' => 'assets/assets',
                'model' => Asset::class,
                'permission' => 'ast.view',
                'label' => 'Kartu Aset',
                'formTitle' => 'KARTU ASET',
                'formCode' => 'Form F/KA',
                // withTrashed on every soft-deleting parent — see the class
                // docblock. PEMEGANG is an employee and KATEGORI drives the
                // depreciation policy this card states; both keep their id on
                // the asset after the row is deleted. NOT on `deployments` or
                // `maintenances`, which are the two history tables this card
                // prints: a mobilisation somebody deleted must not come back
                // onto a card that charges a project for it.
                'with' => [
                    'category' => fn ($query) => $query->withTrashed(),
                    'currentProject' => fn ($query) => $query->withTrashed(),
                    'custodian' => fn ($query) => $query->withTrashed(),
                    'warehouse' => fn ($query) => $query->withTrashed(),
                    'deployments.project' => fn ($query) => $query->withTrashed(),
                    'maintenances.vendor' => fn ($query) => $query->withTrashed(),
                    // equipmentLogs is a hasManyThrough whose intermediate
                    // (the deployment) keeps its soft-delete scope, so a
                    // deleted mobilisation's readings never reach the card at
                    // all — the same rule as the two history tables above.
                    // withTrashed here is the class-docblock rule on the
                    // belongsTo itself, and it can only ever see live rows.
                    'equipmentLogs.deployment' => fn ($query) => $query->withTrashed(),
                    // Users are deactivated, never deleted (see iam/users), so
                    // PETUGAS cannot dangle.
                    'equipmentLogs.loggedBy',
                ],
                // No PEMILIK box: a machine belongs to us, not to a customer.
                // The PROYEK box appears when it is standing on somebody's job
                // today and is simply absent when it is back in the yard.
                'header' => ['kind' => 'none', 'project' => 'currentProject'],
                'date' => fn (Asset $asset): Carbon => app(AssetFormService::class)->printedOn(),
                'title' => 'name',
                'identityHouse' => false,
                'identity' => [
                    'KODE ASET' => 'code',
                    'NAMA ASET' => 'name',
                    'KATEGORI' => 'category.name',
                    'MEREK' => 'brand',
                    'TIPE / MODEL' => 'model',
                    'NO. SERI' => 'serial_no',
                    'TANGGAL PEROLEHAN' => ['value' => 'acquisition_date', 'cast' => 'date'],
                    'HARGA PEROLEHAN' => ['value' => 'acquisition_cost', 'cast' => 'rupiah'],
                    'NILAI RESIDU' => ['value' => 'salvage_value', 'cast' => 'rupiah'],
                    'UMUR MANFAAT' => fn (Asset $asset): string => app(AssetFormService::class)->usefulLife($asset),
                    'MULAI PENYUSUTAN' => ['value' => 'depreciation_start_date', 'cast' => 'date'],
                    /*
                     * Asset::monthlyDepreciation(), the same formula
                     * DepreciationService posts with. A second straight-line
                     * division written for the paper would disagree with the
                     * ledger the first time a useful life was revised.
                     *
                     * RULED unless the asset is ACTUALLY being depreciated, and
                     * the guard mirrors BOTH conditions DepreciationService
                     * selects on — whereNotNull('depreciation_start_date') AND
                     * where('useful_life_months', '>', 0). An asset failing
                     * either one is never charged a rupiah while
                     * monthlyDepreciation() answers a different question and
                     * answers it happily:
                     *
                     *   no start date        it returns (cost - residu) / umur,
                     *                        so the card printed "MULAI
                     *                        PENYUSUTAN : ......" ruled,
                     *                        directly above "PENYUSUTAN PER
                     *                        BULAN : Rp 2.500.000,00"
                     *   umur 0 (or null)     it returns 0.0, so the card
                     *                        printed "PENYUSUTAN PER BULAN :
                     *                        Rp 0,00" — a computed sentinel
                     *                        for "no useful life recorded",
                     *                        printed as though the company had
                     *                        decided to charge nothing a month
                     *
                     * Both sit above an AKUMULASI of 0,00 that will never move.
                     * A filled figure among ruled neighbours reads as an active
                     * monthly charge; it is a charge nobody is making. UMUR
                     * MANFAAT one line up prints the stored column as it stands
                     * ("0 bulan"), which is the fact that explains why this
                     * line is ruled.
                     *
                     * The guard is HERE and not in monthlyDepreciation(),
                     * whose other callers ask a different question — what the
                     * charge WOULD be — and are entitled to the answer.
                     */
                    'PENYUSUTAN PER BULAN' => [
                        'value' => fn (Asset $asset): ?float => $asset->depreciation_start_date
                            && (int) $asset->useful_life_months > 0
                                ? $asset->monthlyDepreciation()
                                : null,
                        'cast' => 'rupiah',
                    ],
                    'AKUMULASI PENYUSUTAN' => ['value' => 'accumulated_depreciation', 'cast' => 'rupiah'],
                    'NILAI BUKU' => ['value' => 'book_value', 'cast' => 'rupiah'],
                    'NILAI PER TANGGAL' => [
                        'value' => fn (Asset $asset): Carbon => app(AssetFormService::class)->printedOn(),
                        'cast' => 'date',
                    ],
                    'PROYEK SAAT INI' => 'currentProject.name',
                    'PEMEGANG' => 'custodian.name',
                    'GUDANG / LOKASI' => 'warehouse.name',
                    'STATUS' => 'status',
                ],
                'body' => [
                    [
                        'id' => 'riwayat-mobilisasi',
                        'title' => 'RIWAYAT MOBILISASI',
                        'rows' => fn (Asset $asset): array => app(AssetFormService::class)->deploymentHistory($asset),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'NO. MOBILISASI', 'width' => '28mm', 'value' => 'code'],
                            ['label' => 'PROYEK', 'value' => 'project.name'],
                            ['label' => 'MULAI', 'align' => 'center', 'width' => '26mm',
                                'value' => 'deployed_from', 'cast' => 'date'],
                            ['label' => 'RENCANA S/D', 'align' => 'center', 'width' => '26mm',
                                'value' => 'planned_until', 'cast' => 'date'],
                            // Ruled while the machine is still on site, which
                            // is exactly the question this table is read for.
                            ['label' => 'DIKEMBALIKAN', 'align' => 'center', 'width' => '26mm',
                                'value' => 'returned_at', 'cast' => 'date'],
                            // Nullable and it means it: no rate means no
                            // internal charge was agreed, not a charge of
                            // nothing, and DeploymentService charges the
                            // project off exactly this column.
                            ['label' => 'TARIF HARIAN (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'daily_rate_internal', 'cast' => 'money'],
                            ['label' => 'STATUS', 'align' => 'center', 'width' => '22mm', 'value' => 'status'],
                        ],
                        // A sentence, not a pad. Ruled rows here would invite a
                        // mobilisation to be written onto a card by hand,
                        // outside the register that charges a project for it.
                        'empty' => 'Belum ada mobilisasi tercatat untuk aset ini.',
                    ],
                    [
                        'id' => 'riwayat-pemeliharaan',
                        'title' => 'RIWAYAT PEMELIHARAAN',
                        'rows' => fn (Asset $asset): array => app(AssetFormService::class)->maintenanceHistory($asset),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'NO. PERAWATAN', 'width' => '28mm', 'value' => 'code'],
                            ['label' => 'TANGGAL', 'align' => 'center', 'width' => '26mm',
                                'value' => 'maintenance_date', 'cast' => 'date'],
                            ['label' => 'JENIS', 'width' => '26mm', 'value' => 'maintenance_type'],
                            ['label' => 'PELAKSANA', 'width' => '34mm', 'value' => 'vendor.name'],
                            ['label' => 'URAIAN', 'value' => 'description'],
                            ['label' => 'BIAYA (Rp)', 'align' => 'right', 'width' => '28mm',
                                'value' => 'cost', 'cast' => 'money'],
                            ['label' => 'JATUH TEMPO BERIKUT', 'align' => 'center', 'width' => '28mm',
                                'value' => 'next_due_date', 'cast' => 'date'],
                        ],
                        'totals' => [
                            [
                                // "tercatat" is doing work: a sum over nothing
                                // is 0,00, and that is a statement about this
                                // register rather than a claim that nobody ever
                                // serviced the machine.
                                'label' => 'TOTAL BIAYA PEMELIHARAAN TERCATAT (Rp)',
                                'value' => fn (Asset $asset): float => app(AssetFormService::class)->maintenanceCostTotal($asset),
                                'cast' => 'money',
                            ],
                        ],
                        'empty' => 'Belum ada pemeliharaan tercatat untuk aset ini.',
                    ],
                    /*
                     * The BBM & hour-meter register (deviasi #13), the third
                     * history table: the meter trail is exactly what a
                     * mechanic reads off a kartu alat when deciding whether
                     * the 2.000-hour service is due. A register only — the
                     * fuel COST is petty cash (BbmTol) and prints nowhere
                     * here, so this table carries no rupiah column at all.
                     */
                    [
                        'id' => 'log-bbm-jam-alat',
                        'title' => 'LOG BBM & JAM ALAT',
                        'rows' => fn (Asset $asset): array => app(AssetFormService::class)->equipmentLogHistory($asset),
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm',
                                'value' => fn (mixed $row, int $index): int => $index + 1],
                            ['label' => 'TANGGAL', 'align' => 'center', 'width' => '26mm',
                                'value' => 'log_date', 'cast' => 'date'],
                            ['label' => 'NO. MOBILISASI', 'width' => '28mm', 'value' => 'deployment.code'],
                            // Ruled when the gauge was not read that day — a
                            // null reading is not a reading of zero hours.
                            ['label' => 'HOUR METER (JAM)', 'align' => 'right', 'width' => '26mm',
                                'value' => 'hour_meter', 'cast' => 'qty'],
                            // Ruled on a no-refuel day for the same reason.
                            ['label' => 'BBM (LITER)', 'align' => 'right', 'width' => '24mm',
                                'value' => 'fuel_liters', 'cast' => 'qty'],
                            ['label' => 'PETUGAS', 'width' => '30mm', 'value' => 'loggedBy.name'],
                            ['label' => 'CATATAN', 'value' => 'notes'],
                        ],
                        // A sentence, not a pad: ruled rows would invite a
                        // hand-written reading the register's monotonic guard
                        // never saw.
                        'empty' => 'Belum ada log BBM atau jam alat tercatat untuk aset ini.',
                    ],
                ],
                // The disposal facts ride here rather than as identity lines:
                // an identity line prints its label whether or not it has a
                // value, so a live excavator would carry a ruled
                // "NILAI PELEPASAN : ......" inviting a sale price to be
                // written onto the card of an asset nobody sold.
                'notes' => [
                    'text' => fn (Asset $asset): ?string => app(AssetFormService::class)->assetNotes($asset),
                    'lines' => 3,
                ],
            ],

            /*
             * BERITA ACARA MOBILISASI ALAT — the handover, signed by the yard
             * and the site.
             *
             * A site document: four-party band and the contract day count,
             * because it is filed with the job the machine went to and the
             * internal charge runs from the date on it.
             */
            'berita-acara-mobilisasi' => [
                'resource' => 'assets/deployments',
                'model' => Deployment::class,
                'permission' => 'ast.view',
                'label' => 'Berita Acara Mobilisasi Alat',
                'formTitle' => 'BERITA ACARA MOBILISASI ALAT',
                'formCode' => 'Form F/BAM',
                // withTrashed on the asset and down the project path — see the
                // class docblock. This berita acara names the machine being
                // handed over; without the asset row it hands over nothing.
                'with' => [
                    'asset' => fn ($query) => $query->withTrashed(),
                    'asset.category' => fn ($query) => $query->withTrashed(),
                    'project' => fn ($query) => $query->withTrashed(),
                    'project.customer' => fn ($query) => $query->withTrashed(),
                    'project.contract' => fn ($query) => $query->withTrashed(),
                ],
                'header' => ['kind' => 'project', 'source' => 'project'],
                // Dated by the MOBILISATION, never by the day somebody pressed
                // print: the internal charge is counted from this date, and a
                // reprint must not re-answer which day the machine arrived.
                'date' => 'deployed_from',
                'identity' => [
                    'NO. BERITA ACARA' => 'code',
                    'KODE ASET' => 'asset.code',
                    'NAMA ALAT' => 'asset.name',
                    'MEREK' => 'asset.brand',
                    'TIPE / MODEL' => 'asset.model',
                    'NO. SERI' => 'asset.serial_no',
                    'TANGGAL MOBILISASI' => ['value' => 'deployed_from', 'cast' => 'date'],
                    'RENCANA SAMPAI' => ['value' => 'planned_until', 'cast' => 'date'],
                    'TANGGAL DEMOBILISASI' => ['value' => 'returned_at', 'cast' => 'date'],
                    // Ruled when no rate was agreed. "Rp 0,00" here would tell
                    // a project manager the plant is free.
                    'TARIF HARIAN INTERNAL' => ['value' => 'daily_rate_internal', 'cast' => 'rupiah'],
                    'STATUS' => 'status',
                ],
                'body' => [
                    /*
                     * A PAD, and it has to be. ast_deployments records no
                     * condition, no hour meter, no fuel level and no
                     * attachments — and the condition of the machine at
                     * handover is precisely what the two signatures below are
                     * about. Ruled rows, never a column of "Baik".
                     */
                    [
                        'id' => 'kondisi-alat',
                        'title' => 'KONDISI ALAT SAAT SERAH TERIMA',
                        'columns' => [
                            ['label' => 'NO', 'align' => 'center', 'width' => '9mm'],
                            ['label' => 'URAIAN PEMERIKSAAN'],
                            ['label' => 'KONDISI (BAIK / RUSAK)', 'align' => 'center', 'width' => '40mm'],
                            ['label' => 'KETERANGAN', 'width' => '50mm'],
                        ],
                        'minRows' => 6,
                    ],
                ],
                'notes' => ['text' => 'notes', 'lines' => 3],
                'signatures' => [
                    [
                        'heading' => 'Menyerahkan,',
                        'subheading' => 'Bagian Peralatan',
                        'party' => null,
                        'name' => null,
                        'role' => 'Nama & Tanggal',
                    ],
                    [
                        'heading' => 'Menerima,',
                        'subheading' => 'Pelaksana Lapangan',
                        'party' => null,
                        'name' => null,
                        'role' => 'Nama & Tanggal',
                    ],
                    [
                        'heading' => null,
                        'subheading' => 'Mengetahui,',
                        'party' => null,
                        'name' => null,
                        'role' => 'Manajer Proyek',
                    ],
                ],
            ],
            // PRINTABLE REGISTRY (Assets) — tambahkan dokumen baru di sini.
        ];
    }

    // ------------------------------------------------------------- reading

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function has(string $slug): bool
    {
        return isset($this->all()[$slug]);
    }

    /**
     * One entry with every optional key filled in, so neither the composer nor
     * the Blade has to guess what a missing key meant.
     *
     * A row missing a REQUIRED key is refused by name rather than printed
     * half-formed: a sheet with no permission key would be a sheet anybody
     * could read.
     *
     * @return array<string, mixed>
     */
    public function definition(string $slug): array
    {
        $entry = $this->all()[$slug] ?? throw new InvalidArgumentException(
            "Jenis formulir cetak tidak dikenal: {$slug}."
        );

        foreach (self::REQUIRED as $key) {
            if (blank($entry[$key] ?? null)) {
                throw new InvalidArgumentException(
                    "Dokumen cetak {$slug} tidak lengkap: kunci '{$key}' wajib diisi."
                );
            }
        }

        return $entry + [
            'formCode' => null,
            'orientation' => 'portrait',
            'idField' => 'id',
            'params' => [],
            'with' => [],
            'header' => ['kind' => 'none'],
            'title' => null,
            'date' => null,
            'period' => null,
            'pekerjaan' => null,
            'identity' => [],
            'identityHouse' => null,
            'body' => [],
            'notes' => ['text' => null, 'lines' => 3, 'weather' => null, 'hours' => false],
            'signatures' => 'house',
            'docControl' => null,
        ];
    }

    /**
     * What this caller may print, in the shape the SPA renders a button from.
     *
     * Permission-filtered rather than filtered in the browser: a button that
     * always answers 403 is a support ticket, and the list of documents a role
     * cannot reach is not the browser's business.
     *
     * @return list<array{slug: string, resource: string, label: string, idField: string, params: array<string, string>}>
     */
    public function catalogue(?Authorizable $user): array
    {
        $catalogue = [];

        // The seven bespoke Projects forms ride the same catalogue: printable
        // has meant "served by the endpoint" since day one, and a catalogue
        // that lists 33 of 40 makes the other seven undiscoverable to any
        // client that trusts it (temuan T3). Same permission filter as below;
        // slug collisions are impossible (PrintRegistryTest pins disjointness).
        foreach (FormPrintService::catalogueRows() as $row) {
            if ($user === null || ! $user->can($row['permission'])) {
                continue;
            }

            $catalogue[] = [
                'slug' => $row['slug'],
                'resource' => $row['resource'],
                'label' => $row['label'],
                'idField' => $row['idField'],
                'params' => $row['params'],
            ];
        }

        foreach ($this->keys() as $slug) {
            $definition = $this->definition($slug);

            if ($user === null || ! $user->can($definition['permission'])) {
                continue;
            }

            $catalogue[] = [
                'slug' => $slug,
                'resource' => $definition['resource'],
                'label' => $definition['label'],
                'idField' => $definition['idField'],
                'params' => $definition['params'],
            ];
        }

        return $catalogue;
    }
}
