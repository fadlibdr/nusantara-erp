<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\MethodLibraryEntry;

/**
 * P7 — the metode pelaksanaan library, and the one thing it must never do:
 * overwrite a version somebody has already attached to a bid.
 *
 * A revision is a NEW ROW at version n+1; the old row keeps its text, its
 * attachments and its code, and gains a superseded_by_id pointing at the new
 * one. That is the P1-ENG submittal pattern, and it is here for the same
 * reason: a quotation sent in March cites the method that was current in
 * March, and rewriting that row in June rewrites the annex of a letter that
 * has already gone out.
 */
class MethodLibraryService
{
    /** Kolom yang tidak pernah datang dari klien. */
    private const COMPUTED = ['code', 'version', 'superseded_by_id'];

    public function create(array $data, ?User $by = null): MethodLibraryEntry
    {
        $category = trim((string) ($data['category'] ?? ''));
        $workPackage = trim((string) ($data['work_package'] ?? ''));

        $current = $this->current($category, $workPackage);

        if ($current !== null) {
            throw ValidationException::withMessages([
                'work_package' => [
                    "Metode untuk paket \"{$workPackage}\" sudah ada dan berlaku ({$current->code} versi "
                        ."{$current->version}); terbitkan revisi, jangan entri baru.",
                ],
            ]);
        }

        $entry = new MethodLibraryEntry(Arr::except($data, self::COMPUTED));
        $entry->category = $category;
        $entry->work_package = $workPackage;
        $entry->version = 1;
        $entry->created_by = $by?->id;
        $entry->save(); // HasDocumentNumber fills the MTD code

        return $entry;
    }

    /**
     * Sunting entri yang BELUM digantikan. Sebuah versi yang sudah digantikan
     * adalah catatan sejarah; menyuntingnya mengubah apa yang pernah dikutip.
     */
    public function update(MethodLibraryEntry $entry, array $data): MethodLibraryEntry
    {
        if (! $entry->isCurrent()) {
            throw ValidationException::withMessages([
                'id' => ["{$entry->code} sudah digantikan versi berikutnya dan tidak dapat disunting."],
            ]);
        }

        // category/work_package identitas rangkaian versinya — pindah paket
        // berarti metode lain, bukan suntingan.
        $entry->fill(Arr::except($data, [...self::COMPUTED, 'category', 'work_package']))->save();

        return $entry;
    }

    /**
     * Terbitkan versi berikutnya dari sebuah metode.
     *
     * Muatan versi baru = muatan versi lama, ditimpa apa yang dikirim. Sebuah
     * revisi yang harus mengetik ulang seluruh isinya adalah revisi yang
     * kehilangan bagian yang tidak berubah.
     */
    public function publishRevision(MethodLibraryEntry $entry, array $data, ?User $by = null): MethodLibraryEntry
    {
        if (! $entry->isCurrent()) {
            $successor = $entry->supersededBy;

            throw ValidationException::withMessages([
                'id' => [
                    "{$entry->code} sudah digantikan"
                        .($successor !== null ? " oleh {$successor->code} versi {$successor->version}" : '')
                        .'; terbitkan revisi dari versi yang berlaku.',
                ],
            ]);
        }

        return DB::transaction(function () use ($entry, $data, $by): MethodLibraryEntry {
            $next = new MethodLibraryEntry(Arr::except($data, [...self::COMPUTED, 'category', 'work_package']));

            $next->category = $entry->category;
            $next->work_package = $entry->work_package;
            $next->title = $data['title'] ?? $entry->title;
            $next->summary = array_key_exists('summary', $data) ? $data['summary'] : $entry->summary;
            $next->effective_date = array_key_exists('effective_date', $data)
                ? $data['effective_date']
                : $entry->effective_date;
            $next->notes = array_key_exists('notes', $data) ? $data['notes'] : $entry->notes;
            $next->version = (int) $entry->version + 1;
            $next->created_by = $by?->id;
            $next->save();

            $entry->superseded_by_id = $next->id;
            $entry->save();

            return $next;
        });
    }

    /** Versi yang berlaku untuk satu paket pekerjaan, bila ada. */
    public function current(string $category, string $workPackage): ?MethodLibraryEntry
    {
        return MethodLibraryEntry::query()
            ->where('category', $category)
            ->where('work_package', $workPackage)
            ->whereNull('superseded_by_id')
            ->orderByDesc('version')
            ->first();
    }
}
