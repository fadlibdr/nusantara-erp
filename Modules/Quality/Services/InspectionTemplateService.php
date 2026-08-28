<?php

namespace Modules\Quality\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\InspectionResult;
use Modules\Quality\Models\InspectionTemplate;

/**
 * P1-QC: the checklist library — header plus butir, replaced wholesale on
 * update. Called both by the API controllers and by the XLSX importer
 * (ImportableDocuments 'inspection-templates'), so the create/update signatures
 * match the assembled import payload (a header array with an `items` key), the
 * AhspService shape.
 */
class InspectionTemplateService
{
    public function create(array $data): InspectionTemplate
    {
        return DB::transaction(function () use ($data): InspectionTemplate {
            /** @var InspectionTemplate $template */
            $template = InspectionTemplate::query()->create(Arr::except($data, ['items']));

            $this->replaceItems($template, $data['items'] ?? []);

            return $template;
        });
    }

    public function update(InspectionTemplate $template, array $data): InspectionTemplate
    {
        return DB::transaction(function () use ($template, $data): InspectionTemplate {
            $template->fill(Arr::except($data, ['items']))->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($template, $data['items'] ?? []);
            }

            return $template;
        });
    }

    /** Wholesale replacement; file/API order becomes sort_order. */
    public function replaceItems(InspectionTemplate $template, array $items): void
    {
        /*
         * Butir yang sudah dipakai baris hasil inspeksi TIDAK boleh dihapus:
         * qc_inspection_results.template_item_id adalah FK terkendala, jadi
         * delete() di sini menabrak constraint dengan 500 telanjang. Sebuah
         * checklist yang butirnya sudah terisi adalah sejarah — koreksinya
         * template versi baru, bukan menulis ulang yang sedang dirujuk.
         * Penolakan 422 yang jujur, bukan 500 dari basis data.
         */
        $usedItemIds = InspectionResult::query()
            ->whereIn('template_item_id', $template->items()->pluck('id'))
            ->exists();

        if ($usedItemIds) {
            throw ValidationException::withMessages([
                'items' => 'Template ini sudah dipakai inspeksi yang terisi; butirnya tidak bisa '
                    .'ditulis ulang. Buat template versi baru untuk perubahan.',
            ]);
        }

        $template->items()->delete();

        foreach (array_values($items) as $index => $line) {
            $template->items()->create(
                Arr::only($line, ['check_text', 'acceptance', 'tolerance'])
                + ['sort_order' => $line['sort_order'] ?? $index + 1],
            );
        }
    }
}
