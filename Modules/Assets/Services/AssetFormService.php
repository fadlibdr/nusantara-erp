<?php

namespace Modules\Assets\Services;

use Illuminate\Support\Carbon;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;
use Modules\Assets\Models\Maintenance;
use Modules\Core\Support\Money;

/**
 * The body of the two Aset house forms — kartu aset and berita acara
 * mobilisasi — in the taste of
 * Modules\Subcontract\Services\SubcontractFormService.
 *
 * Almost every cell on both sheets is a stored column that AssetService,
 * DeploymentService and DepreciationService already computed, and the registry
 * entry reads those directly. What lands here is the handful of answers that
 * are NOT a straight read, each of them a decision worth saying out loud:
 *
 *   A KARTU ASET IS DATED BY THE PRINTER. accumulated_depreciation and
 *   book_value are TODAY'S — rewritten by every posted depreciation run — and
 *   ast_assets keeps no history of what they were in June. The card therefore
 *   states the day it came off the printer and nothing else can re-date it;
 *   see printedOn().
 *
 *   THE DISPOSAL FACTS RIDE IN THE CATATAN BLOCK. An identity line prints its
 *   label whether or not it has a value, so three disposal lines would leave a
 *   live excavator carrying "NILAI PELEPASAN : ......" — a ruled line inviting
 *   somebody to write a sale price onto the card of an asset nobody sold. Same
 *   reasoning as ProcurementFormService::orderNotes and the override reason.
 *
 *   NO DAYS COLUMN ON THE MOBILISATION HISTORY. How many days a deployment is
 *   CHARGED for is answered by DeploymentService — per month by accrueMonth(),
 *   at demobilisation by returnDeployment() — against an accounting window
 *   this sheet does not have. A days figure computed here would be a second
 *   answer to a question the project cost ledger has already answered once,
 *   and the two would part company the first time a month was accrued.
 */
class AssetFormService
{
    /**
     * Indonesian month names, for the one sentence this class composes.
     *
     * The fourth copy in this codebase (FormPrintService, DocumentPdfService,
     * Support\CalendarEvents) and for their reason: APP_LOCALE is 'en' with no
     * lang/ directory, so reaching Carbon's translatedFormat() would mean
     * switching the whole application locale to 'id' and taking every
     * validation message with it.
     */
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * The as-at date of a sheet the database cannot date itself.
     *
     * The registry's own date wins over ?tanggal=, so naming this as the
     * card's date is what stops a URL heading it with a month whose figures
     * nothing in this ERP kept.
     */
    public function printedOn(): Carbon
    {
        return Carbon::now()->startOfDay();
    }

    /** "96 bulan" — a stored integer, worded, never left as a bare number. */
    public function usefulLife(Asset $asset): string
    {
        return ((int) $asset->useful_life_months).' bulan';
    }

    /**
     * Every mobilisation this asset has been on, oldest first.
     *
     * @return array<int, Deployment>
     */
    public function deploymentHistory(Asset $asset): array
    {
        return $asset->deployments
            ->sortBy([['deployed_from', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Every service and repair recorded against it, oldest first.
     *
     * @return array<int, Maintenance>
     */
    public function maintenanceHistory(Asset $asset): array
    {
        return $asset->maintenances
            ->sortBy([['maintenance_date', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The BBM & hour-meter register across all this asset's mobilisations,
     * oldest first — the third history table on the card. The meter trail is
     * exactly what a mechanic reads off a kartu alat when deciding whether
     * the 2.000-hour service is due. Through LIVE deployments only (see
     * Asset::equipmentLogs): a deleted mobilisation's readings must not come
     * back onto the card, the same rule its two sibling tables follow.
     *
     * @return array<int, EquipmentLog>
     */
    public function equipmentLogHistory(Asset $asset): array
    {
        return $asset->equipmentLogs
            ->sortBy([['log_date', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * What upkeep has cost so far, from the stored cost column.
     *
     * A sum over nothing is 0,00 and the label says "tercatat": an asset with
     * no maintenance rows has had no maintenance cost RECORDED, which is a
     * statement about this register and not a claim that nobody ever serviced
     * the machine.
     */
    public function maintenanceCostTotal(Asset $asset): float
    {
        return round((float) $asset->maintenances->sum('cost'), 2);
    }

    /**
     * The catatan block: what was typed onto the asset, and — only on a card
     * that has one — the disposal, spelled out.
     */
    public function assetNotes(Asset $asset): ?string
    {
        $blocks = [trim((string) $asset->notes), trim((string) $this->rentalSentence($asset))];

        if ($asset->disposal_date !== null || $asset->disposal_value !== null || filled($asset->disposal_reason)) {
            $facts = array_filter([
                $asset->disposal_date === null ? null : $this->date($asset->disposal_date),
                // A disposal at 0 is a real outcome — scrapped, or handed over
                // for nothing — and prints as such. Only an unrecorded value
                // is left out of the sentence entirely.
                $asset->disposal_value === null ? null : 'nilai '.Money::format($asset->disposal_value),
            ]);

            /*
             * The colon is written only when something follows it.
             *
             * A row carrying a reason but neither date nor value composed
             * "Pelepasan aset : . Dijual ke pihak ketiga" — a stray " : ." in
             * the catatan block of a signed asset card, which reads as a cell
             * the printer failed to fill rather than as a fact the register
             * does not hold.
             *
             * No request can produce that row today: AssetDisposeRequest
             * requires disposal_date, disposal_value and reason together, and
             * AssetUpdateRequest accepts none of the three. It is fixed anyway
             * because the guard is upstream and the sheet is downstream — the
             * three columns are independently nullable in ast_assets (the
             * reason arrived in a later migration, nullable, over rows that
             * already had a date and a value), and a seeder, an import or a
             * console fix writes them without passing a FormRequest at all. A
             * sentence that only reads correctly while a validator somewhere
             * else keeps holding is not a sentence to print over three
             * signatures.
             */
            $sentence = 'Pelepasan aset'.($facts === [] ? '' : ' : '.implode(', ', $facts)).'.';

            $blocks[] = trim($sentence.' '.trim((string) $asset->disposal_reason));
        }

        $notes = implode("\n", array_filter($blocks, fn (string $block): bool => $block !== ''));

        return $notes === '' ? null : $notes;
    }

    /**
     * P5 — the rental facts of a RENTED machine, as one composed sentence in
     * the catatan block. Not identity lines, for the disposal facts' reason:
     * an identity line prints its caption on every card, so an OWNED
     * excavator would carry "VENDOR RENTAL : ......" — a ruled line inviting
     * a lessor to be written onto plant the company owns. (The KEPEMILIKAN
     * identity line, which always has a value, is what says which kind of
     * card this is.)
     *
     * Composed only from the columns that hold something, and the sentence
     * survives any of them being absent — the disposal sentence's defensive
     * wording, for its reason: AssetStoreRequest requires vendor/tarif/basis
     * together on a rented asset, but seeders, imports and console fixes
     * write these columns without a FormRequest in sight, and a sentence
     * that composes " : ." or "Rp 0,00 per " under a validator's protection
     * is not a sentence to print over three signatures. Null when the asset
     * is not rented at all.
     */
    public function rentalSentence(Asset $asset): ?string
    {
        if (! $asset->isRented()) {
            return null;
        }

        $facts = [];

        if (filled($asset->vendor?->name)) {
            $facts[] = 'dari '.trim((string) $asset->vendor->name);
        }

        if ($asset->rental_rate !== null) {
            // lcfirst on the enum label: "Per hari (8 jam)" reads as
            // "tarif Rp 1.500.000,00 per hari (8 jam)" inside a sentence.
            $facts[] = 'tarif '.Money::format($asset->rental_rate)
                .($asset->rate_basis !== null ? ' '.lcfirst($asset->rate_basis->label()) : '');
        }

        if ($asset->rental_start !== null && $asset->rental_end !== null) {
            $facts[] = 'periode sewa '.$this->date($asset->rental_start).' s/d '.$this->date($asset->rental_end);
        } elseif ($asset->rental_start !== null) {
            $facts[] = 'periode sewa sejak '.$this->date($asset->rental_start);
        } elseif ($asset->rental_end !== null) {
            $facts[] = 'periode sewa s/d '.$this->date($asset->rental_end);
        }

        return 'Aset sewa'.($facts === [] ? '' : ' '.implode(', ', $facts)).'.';
    }

    private function date(mixed $value): string
    {
        $date = Carbon::parse($value);

        return $date->format('d').' '.self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }
}
