<?php

namespace Modules\Core\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\ApprovableDocuments;

/**
 * P8 — revisi generik (D9) untuk dokumen yang belum punya pola revisinya
 * sendiri: izin kerja lapangan, IPP, inspeksi mutu. Semantik mengikuti
 * DrawingSubmittal, kata demi kata:
 *
 *  - sebuah revisi adalah BARIS BARU bernomor dokumen baru (service modul yang
 *    menyalinnya — trait ini tidak tahu baris anak mana yang ikut);
 *  - pendahulunya distempel superseded_at + superseded_by_id — bukan flag
 *    status — sehingga baris tanpa stempel terbukti hidup;
 *  - pendahulu MEMPERTAHANKAN nomor, status, dan seluruh riwayat persetujuan
 *    (core_approvals menempel pada morph pendahulu dan tidak pernah dipindah);
 *  - hanya baris hidup yang bisa di-submit/approve/reject/ubah/hapus/revisi.
 *
 * Dokumen yang SUDAH punya pola sendiri (DrawingSubmittal, ProjectBaseline,
 * MethodLibraryEntry, versi BOQ, revisi penawaran) tidak memakai trait ini.
 *
 * Model butuh kolom: revision (unsignedSmallInteger, default 0),
 * superseded_at (dateTime nullable), superseded_by_id (unsignedBigInteger
 * nullable, self-reference tanpa FK).
 */
trait Revisable
{
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(static::class, 'superseded_by_id');
    }

    /** Baris yang digantikan langsung oleh baris ini (mata rantai mundur). */
    public function predecessor(): HasOne
    {
        return $this->hasOne(static::class, 'superseded_by_id');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /**
     * Penjaga satu kalimat untuk setiap aksi yang kini milik revisi hidupnya.
     * ValidationException, bukan LogicException: pengguna yang membuka revisi
     * lama dari riwayat adalah kejadian normal, dan jawabannya adalah 422
     * dengan arah yang jelas — bukan galat server.
     */
    public function assertRevisiBerlaku(string $verb): void
    {
        if (! $this->isSuperseded()) {
            return;
        }

        throw ValidationException::withMessages(['revision' => sprintf(
            '%s %s telah digantikan revisi %s dan tidak dapat %s; buka revisi terbarunya.',
            ApprovableDocuments::label($this),
            $this->code,
            $this->supersededBy?->code ?? '-',
            $verb,
        )]);
    }
}
