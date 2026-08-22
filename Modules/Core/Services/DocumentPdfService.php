<?php

namespace Modules\Core\Services;

use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Company;
use Modules\Core\Support\Terbilang;

/**
 * Printable documents.
 *
 * There were none. barryvdh/laravel-dompdf has been a dependency since the first
 * commit and was called nowhere; resources/views held no Blade file at all; and
 * the only way to get an invoice onto paper was Ctrl-P on a web detail screen,
 * which prints a key/value dump of whatever the API resource returned.
 *
 * The gap showed up in the data as well as the output. fin_ar_invoices.terbilang
 * is COMPUTED AND STORED — the amount in words exists precisely so an invoice
 * document can carry it, and until now there was no document to carry it on.
 *
 * Everything renders through one letterhead so a customer receiving an invoice
 * and a vendor receiving a purchase order see the same company identity, and so
 * a change to that identity is one edit rather than three.
 */
class DocumentPdfService
{
    /** A4 portrait for all four; none of them is a wide table. */
    private const PAPER = 'a4';

    /** What a letterhead logo may be, and how large it may get. */
    private const LOGO_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    private const LOGO_MAX_BYTES = 1_048_576;

    /**
     * Indonesian month names, spelled here rather than through Carbon's
     * translatedFormat(). APP_LOCALE is 'en' and there is no lang/ directory, so
     * switching the application locale to 'id' to get "Juli" would take every
     * validation message with it and leave them untranslated. These documents
     * are the only Indonesian-language output in the system; the twelve words
     * belong with them.
     */
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * @return array{filename: string, body: string}
     */
    public function pdf(string $type, $record): array
    {
        $document = $this->compose($type, $record);

        $pdf = Pdf::loadView($document['view'], $document['data'])
            ->setPaper(self::PAPER)
            // Without subsetting, dompdf embeds the whole of DejaVu Sans and a
            // one-page invoice weighs 1.2 MB. The package ships the option off.
            ->setOption('enable_font_subsetting', true)
            // Nothing on these pages is remote, and a document generator that
            // will fetch URLs is a document generator that can be pointed at
            // something internal. Off explicitly rather than by default.
            ->setOption('isRemoteEnabled', false);

        return ['filename' => $document['filename'], 'body' => $pdf->output()];
    }

    /**
     * The same document as markup, before dompdf turns it into a page.
     *
     * This is what the tests read. A PDF is a compressed binary stream, so
     * asserting against one means either parsing PDF or asserting nothing; this
     * is the actual markup that becomes the page, not a parallel rendering.
     */
    public function html(string $type, $record): string
    {
        $document = $this->compose($type, $record);

        return view($document['view'], $document['data'])->render();
    }

    /**
     * @return array{view: string, data: array, filename: string}
     */
    private function compose(string $type, $record): array
    {
        return match ($type) {
            'ar-invoice' => $this->arInvoice($record),
            'bast' => $this->bast($record),
            'purchase-order' => $this->purchaseOrder($record),
            'payslip' => $this->payslip($record),
            default => throw new InvalidArgumentException("Jenis dokumen cetak tidak dikenal: {$type}."),
        };
    }

    private function arInvoice($invoice): array
    {
        $invoice->loadMissing(['customer', 'contract']);

        return $this->document('ar-invoice', [
            'invoice' => $invoice,
            'voided' => $this->voided($invoice),
            'title' => 'Invoice',
            'subtitle' => $invoice->code,
        ], 'invoice-'.$this->slug($invoice->code));
    }

    /**
     * The banner that stops a cancelled document passing for a live one.
     *
     * Without it the PDF of a cancelled invoice is byte-for-byte the argument
     * for paying it: same faktur pajak number, same "Jumlah yang harus
     * dibayar". The customer pays, and the receipt cannot be recorded at all —
     * PaymentService refuses to settle a non-approved invoice — so the money
     * sits in the bank reconciliation with nothing to allocate it to.
     */
    private function voided($record): ?string
    {
        $status = $record->status ?? null;
        $value = $status instanceof BackedEnum ? $status->value : $status;

        if ($value !== DocumentStatus::Cancelled->value) {
            return null;
        }

        $reason = trim((string) ($record->cancellation_reason ?? ''));

        return 'DIBATALKAN'
            .($record->cancelled_at ? ' '.$this->date($record->cancelled_at) : '')
            .($reason !== '' ? ' — '.$reason : '');
    }

    /**
     * Berita Acara Serah Terima.
     *
     * The document with the most obvious reason to exist: prj_bast records a
     * customer_representative — the name of the person who signs it — against a
     * handover nobody could print for them to sign.
     */
    private function bast($bast): array
    {
        $bast->loadMissing(['project.customer', 'project.contract']);

        return $this->document('bast', [
            'bast' => $bast,
            'bastTypeLabel' => $bast->bast_type?->label() ?? '',
            'title' => 'Berita Acara Serah Terima',
            'subtitle' => $bast->code,
        ], 'bast-'.$this->slug($bast->code));
    }

    private function purchaseOrder($order): array
    {
        $order->loadMissing(['vendor', 'project', 'items']);

        return $this->document('purchase-order', [
            'order' => $order,
            'voided' => $this->voided($order),
            'terbilang' => Terbilang::rupiah((float) $order->total),
            'title' => 'Purchase Order',
            'subtitle' => $order->code,
        ], 'po-'.$this->slug($order->code));
    }

    private function payslip($payslip): array
    {
        $payslip->loadMissing(['employee', 'payrollRun']);
        $run = $payslip->payrollRun;

        return $this->document('payslip', [
            'payslip' => $payslip,
            'terbilang' => Terbilang::rupiah((float) $payslip->net_pay),
            'title' => trim('Slip Gaji '.$this->period($run)),
            'subtitle' => trim(($run?->code ?? '').' · '.($payslip->employee?->name ?? ''), " \u{B7}"),
        ], 'slip-gaji-'.$this->slug(($payslip->employee?->code ?? (string) $payslip->id).'-'.($run?->code ?? '')));
    }

    /**
     * @return array{view: string, data: array, filename: string}
     */
    private function document(string $view, array $data, string $filename): array
    {
        return [
            'view' => "coredoc::documents.{$view}",
            'data' => $data + [
                'voided' => null,
                'company' => $company = Company::current(),
                'logo' => $this->logo($company),
                // Formatting is passed in as closures rather than called inside
                // the templates, so every amount and every date on every
                // document is rendered by one piece of code and cannot drift.
                'money' => fn ($value): string => $this->money($value),
                'date' => fn ($value): string => $this->date($value),
                'printedAt' => $this->date(now()).' '.now()->format('H:i'),
            ],
            'filename' => $filename.'.pdf',
        ];
    }

    /**
     * The letterhead logo, inlined as a data URI.
     *
     * core_company.logo_path has been in the schema since the first migration and
     * was referenced nowhere — the deadest column in the database, because there
     * was no document to put a logo on.
     *
     * Inlined rather than handed to dompdf as a path: the renderer runs with
     * remote fetching off, so a data URI is the only route that always works.
     * Read through the public disk rather than by filename, which is what keeps
     * a logo_path of "../../.env" inside the storage directory. Anything
     * missing, oversized, or not one of the three raster formats simply yields
     * no logo — a letterhead without one is a letterhead; a broken image is not.
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

    /** Empty string, not "01 Januari 1970", when a document has no such date. */
    private function date($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof \DateTimeInterface ? $value : Carbon::parse($value);

        return $date->format('d').' '.self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }

    private function period($run): string
    {
        if ($run === null) {
            return '';
        }

        return trim((self::MONTHS[(int) $run->period_month] ?? '').' '.$run->period_year);
    }

    /** A filename any browser on any OS will save without argument. */
    private function slug(string $code): string
    {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $code) ?? '', '-') ?: 'dokumen';
    }
}
