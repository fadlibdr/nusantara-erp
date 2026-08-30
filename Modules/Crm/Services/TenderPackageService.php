<?php

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\TenderPackage;

/**
 * P7 — the tender dossier: its document register, and its completeness
 * checklist.
 *
 * TWO RULES LIVE HERE, and both are about a document we might not have.
 *
 * 1. THE ADDENDUM REGISTER HAS NO HOLES. Addendum numbers run 1..n with no
 *    gap. A register listing "Addendum III" with no Addendum II is not an
 *    untidy register; it is a statement that one issued document never reached
 *    us, and the bid priced on that register is priced on missing information.
 *    The 422 names the missing number so somebody can go and ask for it.
 *
 * 2. THE CHECKLIST CANNOT GROW ITS OWN ITEMS. Keys come from
 *    config('erp.tender.checklist_template') and nowhere else; an unknown key
 *    is refused by name. What is STORED is a snapshot — label and group beside
 *    the tick — so editing the template later never rewrites a checklist that
 *    has already been answered and filed.
 */
class TenderPackageService
{
    public function create(array $data, ?User $by = null): TenderPackage
    {
        Lead::query()->findOrFail((int) $data['lead_id']);

        $package = new TenderPackage(Arr::except($data, ['code', 'checklist', 'documents', 'created_by']));
        $package->created_by = $by?->id;
        $package->save(); // HasDocumentNumber fills the TND code

        return $package;
    }

    public function update(TenderPackage $package, array $data): TenderPackage
    {
        // lead_id tidak ikut: berkas lelang tidak pindah prospek.
        $package->fill(Arr::except($data, ['code', 'lead_id', 'checklist', 'documents', 'created_by']))->save();

        return $package;
    }

    /**
     * Ganti seluruh register dokumen lelang, utuh, di dalam satu transaksi.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function replaceDocuments(TenderPackage $package, array $rows): TenderPackage
    {
        $prepared = [];
        $addenda = [];

        foreach (array_values($rows) as $index => $row) {
            $line = $index + 1;
            $title = trim((string) ($row['title'] ?? ''));

            if ($title === '') {
                throw ValidationException::withMessages([
                    "documents.{$index}.title" => ["Baris {$line}: judul dokumen wajib diisi."],
                ]);
            }

            if (empty($row['issued_date'])) {
                throw ValidationException::withMessages([
                    "documents.{$index}.issued_date" => ["Baris {$line}: tanggal terbit dokumen wajib diisi."],
                ]);
            }

            $addendumNo = $row['addendum_no'] ?? null;

            if ($addendumNo !== null && $addendumNo !== '') {
                $addendumNo = (int) $addendumNo;

                if ($addendumNo < 1) {
                    throw ValidationException::withMessages([
                        "documents.{$index}.addendum_no" => [
                            "Baris {$line}: nomor addendum dimulai dari 1; kosongkan untuk terbitan asli.",
                        ],
                    ]);
                }

                if (in_array($addendumNo, $addenda, true)) {
                    throw ValidationException::withMessages([
                        "documents.{$index}.addendum_no" => [
                            "Baris {$line}: addendum ke-{$addendumNo} sudah tercatat pada register ini.",
                        ],
                    ]);
                }

                $addenda[] = $addendumNo;
            } else {
                $addendumNo = null;
            }

            $prepared[] = [
                'sort_order' => (int) ($row['sort_order'] ?? $line),
                'title' => $title,
                'chapter' => $row['chapter'] ?? null,
                'issued_date' => $row['issued_date'],
                'addendum_no' => $addendumNo,
                'notes' => $row['notes'] ?? null,
            ];
        }

        $this->assertAddendaAreContiguous($addenda);

        DB::transaction(function () use ($package, $prepared): void {
            $package->documents()->delete();

            foreach ($prepared as $attributes) {
                $package->documents()->create($attributes);
            }
        });

        return $package->load('documents');
    }

    /**
     * Isi checklist kelengkapan dari template, dan simpan SNAPSHOT-nya.
     *
     * Butir yang tidak dikirim tetap ada dan tetap belum tercentang: sebuah
     * daftar periksa yang menghilangkan butir yang tidak dijawab tidak
     * memeriksa apa pun.
     *
     * @param  array<int, array<string, mixed>>  $answers
     */
    public function setChecklist(TenderPackage $package, array $answers): TenderPackage
    {
        $template = $this->template();
        $known = array_column($template, 'key');

        $byKey = [];

        foreach ($answers as $answer) {
            $key = (string) ($answer['key'] ?? '');

            if (! in_array($key, $known, true)) {
                throw ValidationException::withMessages([
                    'checklist' => [
                        "Butir checklist \"{$key}\" tidak dikenali template kelengkapan paket tender.",
                    ],
                ]);
            }

            $byKey[$key] = $answer;
        }

        $snapshot = [];

        foreach ($template as $item) {
            $answer = $byKey[$item['key']] ?? null;
            $notes = $answer['notes'] ?? null;

            $snapshot[] = [
                'key' => $item['key'],
                'group' => $item['group'],
                'label' => $item['label'],
                'checked' => (bool) ($answer['checked'] ?? false),
                'notes' => $notes === null || $notes === '' ? null : (string) $notes,
            ];
        }

        $package->checklist = $snapshot;
        $package->save();

        return $package;
    }

    /**
     * Template kelengkapan apa adanya dari config — dipakai layar pengisian
     * dan uji.
     *
     * @return array<int, array{key: string, group: string, label: string}>
     */
    public function template(): array
    {
        /** @var array<int, array{key: string, group: string, label: string}> $template */
        $template = config('erp.tender.checklist_template', []);

        return $template;
    }

    /**
     * Checklist paket ini untuk dibaca — snapshot tersimpan bila sudah pernah
     * diisi, template kosong bila belum. Tidak pernah campuran keduanya: layar
     * yang menampilkan template baru di atas jawaban lama akan menunjukkan
     * centang pada butir yang bukan butir itu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checklist(TenderPackage $package): array
    {
        $stored = $package->checklist;

        if (is_array($stored) && $stored !== []) {
            return $stored;
        }

        return array_map(static fn (array $item): array => $item + ['checked' => false, 'notes' => null], $this->template());
    }

    /** @param  array<int, int>  $addenda */
    private function assertAddendaAreContiguous(array $addenda): void
    {
        if ($addenda === []) {
            return;
        }

        sort($addenda);
        $highest = end($addenda);

        for ($expected = 1; $expected <= $highest; $expected++) {
            if (! in_array($expected, $addenda, true)) {
                throw ValidationException::withMessages([
                    'documents' => [
                        "Register dokumen lelang melompat: addendum ke-{$expected} belum tercatat, "
                            ."sementara addendum ke-{$highest} sudah. Catat dokumen yang terlewat dahulu.",
                    ],
                ]);
            }
        }
    }
}
