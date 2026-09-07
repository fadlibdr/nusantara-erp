<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Models\NumberSequence;
use Modules\Core\Support\Erp;

class DocumentNumberService
{
    private const ROMAN_MONTHS = [
        1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII',
    ];

    /**
     * Generate the next document number for a type, e.g. next('PO') => "PO/2026/VII/0001".
     * Formats live in config/erp.php (documents), overridable per type on the
     * settings screen. Sequences reset per type per year.
     *
     * P8 — {PROJ}: a mask may carry the {PROJ} token, which renders the KODE
     * PROYEK (prj_projects.code — the only stable, human-meaningful project
     * identifier the schema has; an id means nothing on paper). A {PROJ} mask
     * splits the counter per project: the sequence key becomes
     * (type, year, scope) with scope = the project code. A mask without the
     * token keeps scope = '' and mints byte-identically to before.
     *
     * $projectScope is consulted ONLY when the mask wants it — a caller passing
     * a scope under a token-less mask must not silently split the counter into
     * invisible buckets, so it is discarded. The reverse — a {PROJ} mask minting
     * without a project — is a configuration error (the token was switched on
     * in Pengaturan for a document that cannot supply a project) and fails
     * loudly rather than printing a blank into a document code.
     *
     * F-2 (verifikasi) — $year: sebuah dokumen yang seluruh identitasnya adalah
     * SEBUAH TAHUN BUKU tidak boleh membawa tahun jam server. Terukur: OVB
     * untuk tahun buku 2031 yang dibuat hari ini menerima kode
     * 'OVB/2026/IX/0001', dan kalimat penolakannya berbunyi "Tahun buku 2031
     * sudah punya anggaran overhead yang disetujui (OVB/2026/IX/0001)" — dua
     * tahun berbeda dalam satu kalimat. Yang dilewatkan pemanggil menggantikan
     * {Y} DAN ember urutannya, jadi OVB tahun buku 2031 punya penomorannya
     * sendiri. Tidak dilewatkan (setiap jenis dokumen lain, tanpa kecuali) =
     * tahun berjalan, byte demi byte seperti sebelumnya.
     */
    public function next(string $type, ?string $projectScope = null, ?int $year = null): string
    {
        $format = $this->format($type);
        $year ??= now()->year;
        $month = now()->month;

        $scope = '';

        if (str_contains($format, '{PROJ}')) {
            if ($projectScope === null || trim($projectScope) === '') {
                throw new LogicException(sprintf(
                    'Mask penomoran %s memakai token {PROJ}, tetapi tidak ada konteks proyek untuk '
                        .'dokumen ini — hapus {PROJ} dari documents.%s di Pengaturan, atau isi proyek '
                        .'pada dokumennya. Nomor tidak diterbitkan.',
                    $type,
                    $type,
                )); // gagal KERAS: token tanpa proyek tidak boleh dirender sebagai kosong
            }

            $scope = trim($projectScope);
        }

        return DB::transaction(function () use ($type, $format, $year, $month, $scope) {
            // Created-if-missing and row-locked in one go, so concurrent
            // requests queue on this row and can neither share a number nor
            // trip over each other creating the bucket (see lockBucket).
            $sequence = NumberSequence::lockBucket($type, $year, $scope);

            $sequence->last_number++;
            $sequence->save();

            $n = (int) $sequence->last_number;

            return strtr($format, [
                '{Y}' => (string) $year,
                '{M2}' => str_pad((string) $month, 2, '0', STR_PAD_LEFT),
                '{RM}' => self::ROMAN_MONTHS[$month],
                '{PROJ}' => $scope,
                '{N3}' => str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                '{N4}' => str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                '{N5}' => str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            ]);
        });
    }

    /**
     * Does the effective mask for this type demand a project scope? The caller
     * that owns the model (HasDocumentNumber) asks this BEFORE resolving the
     * project, so a document whose mask never mentions {PROJ} costs no extra
     * query and no project lookup.
     */
    public function requiresProjectScope(string $type): bool
    {
        return str_contains($this->format($type), '{PROJ}');
    }

    private function format(string $type): string
    {
        return Erp::string("documents.{$type}", strtoupper($type).'/{Y}/{RM}/{N4}');
    }
}
