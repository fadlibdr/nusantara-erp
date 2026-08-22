<?php

namespace Modules\ServiceDesk\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Modules\ServiceDesk\Enums\FieldReportStatus;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\ServiceContract;

/**
 * The body of the two Layanan house forms, in the taste of
 * Modules\Procurement\Services\ProcurementFormService.
 *
 * ONE DECISION EARNS THIS WHOLE FILE, and it is the customer's name on the
 * signature rule of a berita acara servis.
 *
 * svc_field_reports.customer_sign_name looks exactly like a record of who
 * signed. FieldReportService says in its own words that it is not:
 *
 *     "customer_sign_name is left alone too, though create() and update() can
 *      both write it, so it is not proof of a signature on its own."
 *
 * What IS proof is customer_signed_at. Only acknowledge() ever writes it, and
 * acknowledge() is the transaction that flips the report to Acknowledged AND
 * posts the spare parts off the shelf — one fact, committed together or not at
 * all, and a one-way door (FieldReportStatus::canReturnToDraft() refuses to
 * come back from it). So a name printed under that rule when both are present
 * is the customer's own sign-off; a name typed into a draft on a technician's
 * tablet is a name typed into a draft, and printing it as a signature would put
 * words in a customer's mouth on the document that closes the visit, consumes
 * the parts and supports the invoice that follows.
 *
 * The rest is ordinary reading: a location assembled from the ticket's site,
 * and the contract's remaining days counted the way every other house form
 * counts sisa hari.
 */
class ServiceDeskFormService
{
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    // ------------------------------------------------------ berita acara

    /**
     * Temuan, tindakan and rekomendasi as three labelled rows.
     *
     * A table rather than three identity lines because these are paragraphs:
     * an identity block is a column of 33mm labels beside one-line values, and
     * a technician's findings run to four sentences. findings and actions_taken
     * are NOT NULL so both always print; recommendations is nullable and its
     * cell is ruled when the visit left none — which is a normal outcome and
     * not a gap to be filled with "tidak ada".
     *
     * @return list<array<string, mixed>>
     */
    public function reportNarrativeRows(FieldReport $report): array
    {
        return [
            ['uraian' => 'Temuan di lapangan', 'keterangan' => $report->findings],
            ['uraian' => 'Tindakan yang dilakukan', 'keterangan' => $report->actions_taken],
            ['uraian' => 'Rekomendasi', 'keterangan' => $report->recommendations],
        ];
    }

    /**
     * One printed line per spare part fitted.
     *
     * NO PAD under these rows, unlike the ruled pads the izin forms carry, and
     * that is the decision worth naming: acknowledging this report ISSUES
     * these parts from the warehouse and debits the ledger for them. Ruled
     * blank rows beneath would invite somebody to write in a part that never
     * left the shelf, on the one document whose parts table is a stock
     * movement. A visit that fitted nothing prints the sentence instead.
     *
     * @return list<array<string, mixed>>
     */
    public function reportPartRows(FieldReport $report): array
    {
        $rows = [];
        $no = 0;

        foreach ($report->parts as $part) {
            $rows[] = [
                'no' => ++$no,
                'kode' => $part->item?->code,
                'nama' => $part->item?->name,
                'qty' => (float) $part->qty,
                'satuan' => $part->item?->unit,
                'keterangan' => $part->notes,
            ];
        }

        return $rows;
    }

    /**
     * The customer's signatory — see the class docblock for why both
     * conditions are required and neither is enough on its own.
     */
    public function reportSignatory(FieldReport $report): ?string
    {
        if ($report->status !== FieldReportStatus::Acknowledged || $report->customer_signed_at === null) {
            return null;
        }

        $name = trim((string) ($report->customer_sign_name ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * Where the work was done: the site's own name, with its address when the
     * site row carries one.
     *
     * A ticket need not name a site — svc_tickets.site_id is nullable, and an
     * incident reported by phone against a whole contract legitimately has
     * none — so this comes back null and the sheet rules the line rather than
     * falling back to the customer's billing address, which is where the
     * invoice goes and not where the technician went.
     */
    public function reportLocation(FieldReport $report): ?string
    {
        $site = $report->ticket?->site;

        if ($site === null) {
            return null;
        }

        $parts = array_filter([
            trim((string) $site->site_name),
            trim((string) ($site->address ?? '')),
            trim((string) ($site->city ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    // ----------------------------------------------------- kontrak layanan

    /**
     * Sisa masa berlaku, worded exactly as the house forms word sisa hari.
     *
     * A contract past its end date says HOW FAR past rather than "0 hari",
     * because a lapsed maintenance contract is precisely the one somebody is
     * about to raise a ticket against, and hiding the overrun on the summary
     * sheet is how an out-of-contract visit gets done for free.
     *
     * Counted as SISA HARI is counted on every house form — plain calendar
     * days from the sheet's own date to the last day of the period, with
     * NEITHER END ADDED ON. On the last covered day it reads "0 hari" (the
     * cover has not lapsed; there is no whole day left after today) and the day
     * after reads "0 hari (lewat 1 hari)".
     *
     * NOT inclusive of both ends, which is what this comment used to claim
     * while pointing at FormPrintService::schedule() for authority.
     * schedule() is inclusive where it counts a SPAN — the first day of a job
     * is HARI KE 1, and 1 Januari to 31 Desember is 365 days — and exclusive
     * where it counts a REMAINDER, which is what this is: remainingDays is
     * daysBetween($date, $end) with no +1. The arithmetic is left alone
     * deliberately rather than made inclusive, because matching the remainder
     * is the whole point — "1 hari" here beside "0 hari" on a laporan harian
     * printed the same morning is a disagreement three signatures cannot
     * resolve. Only the word was wrong.
     */
    public function contractRemaining(ServiceContract $contract, ?DateTimeInterface $asOf = null): ?string
    {
        if ($contract->period_end === null) {
            return null;
        }

        $today = ($asOf === null ? Carbon::now() : Carbon::instance($asOf))->startOfDay();

        // A contract that has not started has no masa berlaku to have a
        // remainder OF. Counted plainly it reads "454 hari" — a number that
        // says the cover is running when it has not begun, which on a sheet
        // dated before the period start is the opposite of the truth.
        if ($contract->period_start !== null
            && $today->lt(Carbon::instance($contract->period_start)->startOfDay())) {
            return 'belum berjalan (mulai '.$this->indonesianDate($contract->period_start).')';
        }

        $end = Carbon::instance($contract->period_end)->startOfDay();
        $diff = $today->diff($end);
        $days = (int) $diff->days * ($diff->invert === 1 ? -1 : 1);

        return $days < 0
            ? '0 hari (lewat '.abs($days).' hari)'
            : $days.' hari';
    }

    /** "4 jam", from a NOT NULL column — so a stored 0 is a term and prints. */
    public function slaLine(?int $hours): ?string
    {
        return $hours === null ? null : $hours.' jam';
    }

    /**
     * One printed line per site under the contract.
     *
     * @return list<array<string, mixed>>
     */
    public function contractSiteRows(ServiceContract $contract): array
    {
        $rows = [];
        $no = 0;

        foreach ($contract->sites as $site) {
            $rows[] = [
                'no' => ++$no,
                'lokasi' => $site->site_name,
                'alamat' => $site->address,
                'kota' => $site->city,
                'pic' => $site->pic_name,
                'telepon' => $site->pic_phone,
            ];
        }

        return $rows;
    }

    /**
     * The PM schedule, including the schedules that have been switched OFF.
     *
     * A deactivated schedule is listed with "Tidak" in its own column rather
     * than dropped: a contract whose quarterly genset check was quietly
     * disabled is exactly what a customer-facing summary has to show, and a
     * sheet that only listed the live ones would read as a contract that never
     * covered it.
     *
     * @return list<array<string, mixed>>
     */
    public function contractScheduleRows(ServiceContract $contract): array
    {
        $rows = [];
        $no = 0;

        foreach ($contract->preventiveSchedules as $schedule) {
            $rows[] = [
                'no' => ++$no,
                'pekerjaan' => $schedule->name,
                'lokasi' => $schedule->site?->site_name,
                'frekuensi' => $schedule->frequency?->label(),
                'jatuh_tempo' => $schedule->next_due_date,
                'petugas' => $schedule->assignee?->name,
                'aktif' => (bool) $schedule->is_active,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------- internals

    /**
     * "01 April 2026" — the house date, assembled here.
     *
     * A second copy of the month names, deliberate for the reason
     * FormPrintService::MONTHS gives about its own: this string carries words
     * around the date, and reaching into Core for its private formatter would
     * couple a module's read model to Core's internals.
     */
    private function indonesianDate(DateTimeInterface $value): string
    {
        $date = Carbon::instance($value);

        return $date->format('d').' '.self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }
}
