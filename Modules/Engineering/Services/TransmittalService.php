<?php

namespace Modules\Engineering\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\MaterialSubmittal;
use Modules\Engineering\Models\Transmittal;

/**
 * P1-ENG: transmittals. Lines are replaced wholesale (CONVENTIONS §6); each
 * line either references a submittal through the closed wire vocabulary below
 * or is free text. Once the tanda-terima is recorded the sheet is signed for
 * and locks — an edited transmittal is a different document from the one that
 * was signed.
 */
class TransmittalService
{
    /**
     * The whole morph surface. Wire kind => class; anything else is refused.
     * A class name must never cross the wire (the AttachableDocuments lesson:
     * the difference between an allowlist and an arbitrary string here is an
     * object-injection surface).
     */
    public const LINE_KINDS = [
        'drawing_submittal' => DrawingSubmittal::class,
        'material_submittal' => MaterialSubmittal::class,
        'lainnya' => null,
    ];

    public function create(array $data, User $by): Transmittal
    {
        return DB::transaction(function () use ($data, $by): Transmittal {
            /** @var Transmittal $transmittal */
            $transmittal = Transmittal::query()->create(
                Arr::except($data, ['code', 'lines', 'received_by', 'received_at', 'created_by'])
                + ['created_by' => $by->id],
            );

            $this->writeLines($transmittal, $data['lines'] ?? []);

            return $transmittal;
        });
    }

    public function update(Transmittal $transmittal, array $data): Transmittal
    {
        $this->assertNotReceived($transmittal, 'diubah');

        return DB::transaction(function () use ($transmittal, $data): Transmittal {
            $transmittal->fill(Arr::except($data, [
                'code', 'project_id', 'lines', 'received_by', 'received_at', 'created_by',
            ]))->save();

            if (array_key_exists('lines', $data)) {
                $transmittal->lines()->delete();
                $this->writeLines($transmittal, $data['lines']);
            }

            return $transmittal;
        });
    }

    public function delete(Transmittal $transmittal): void
    {
        $this->assertNotReceived($transmittal, 'dihapus');

        $transmittal->delete();
    }

    /** The tanda-terima: who signed, when — once. */
    public function receive(Transmittal $transmittal, array $data): Transmittal
    {
        if ($transmittal->isReceived()) {
            throw ValidationException::withMessages(['received_by' => sprintf(
                'Tanda terima %s sudah dicatat atas nama %s pada %s.',
                $transmittal->code,
                $transmittal->received_by,
                $transmittal->received_at?->format('d-m-Y H:i'),
            )]);
        }

        $transmittal->forceFill([
            'received_by' => $data['received_by'],
            'received_at' => $data['received_at'] ?? now(),
        ])->save();

        return $transmittal;
    }

    // ------------------------------------------------------------------ lines

    private function writeLines(Transmittal $transmittal, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $kind = (string) ($line['kind'] ?? 'lainnya');

            if (! array_key_exists($kind, self::LINE_KINDS)) {
                throw ValidationException::withMessages(["lines.{$index}.kind" => sprintf(
                    'Jenis baris "%s" tidak dikenal. Yang tersedia: %s.',
                    $kind,
                    implode(', ', array_keys(self::LINE_KINDS)),
                )]);
            }

            $class = self::LINE_KINDS[$kind];

            if ($class === null) {
                if (blank($line['description'] ?? null)) {
                    throw ValidationException::withMessages(["lines.{$index}.description" => 'Baris teks bebas wajib membawa uraian.']);
                }

                $transmittal->lines()->create([
                    'description' => (string) $line['description'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

                continue;
            }

            $document = $class::query()->find((int) ($line['document_id'] ?? 0));

            if ($document === null) {
                throw ValidationException::withMessages(["lines.{$index}.document_id" => 'Dokumen yang dirujuk baris ini tidak ditemukan.']);
            }

            // A transmittal proves what left THIS project's document control;
            // a line from another project on it would be a signed claim about
            // paperwork this sheet never carried.
            $documentProject = (int) ($document instanceof DrawingSubmittal
                ? $document->drawing?->project_id
                : $document->project_id);

            if ($documentProject !== (int) $transmittal->project_id) {
                throw ValidationException::withMessages(["lines.{$index}.document_id" => sprintf(
                    'Dokumen %s berada pada proyek lain dan tidak dapat dimuat pada transmittal proyek ini.',
                    $document->code,
                )]);
            }

            $transmittal->lines()->create([
                'document_type' => $class,
                'document_id' => $document->id,
                'description' => filled($line['description'] ?? null)
                    ? (string) $line['description']
                    : $this->describe($document),
                'remarks' => $line['remarks'] ?? null,
            ]);
        }
    }

    private function describe(DrawingSubmittal|MaterialSubmittal $document): string
    {
        if ($document instanceof DrawingSubmittal) {
            return sprintf(
                '%s — %s %s (%s)',
                $document->code,
                $document->drawing?->number,
                $document->revision,
                $document->drawing?->title,
            );
        }

        return sprintf('%s — %s', $document->code, $document->material_name);
    }

    private function assertNotReceived(Transmittal $transmittal, string $verb): void
    {
        if ($transmittal->isReceived()) {
            throw ValidationException::withMessages(['transmittal' => sprintf(
                'Transmittal %s sudah diterima %s pada %s dan tidak dapat %s lagi.',
                $transmittal->code,
                $transmittal->received_by,
                $transmittal->received_at?->format('d-m-Y H:i'),
                $verb,
            )]);
        }
    }
}
