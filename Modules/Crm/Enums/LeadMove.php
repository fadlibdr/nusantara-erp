<?php

namespace Modules\Crm\Enums;

/**
 * Putusan `LeadStatus::canMoveTo()` — lima jawaban, bukan satu boolean.
 *
 * Sebuah boolean hanya bisa mengatakan "tidak", dan setiap layar yang
 * membacanya lalu harus MENEBAK sebabnya untuk bisa menulis kalimatnya:
 * papan akan mengarang "tidak ada aksi ke kolom itu" untuk sebuah aturan
 * bisnis, dan pemakainya tidak pernah tahu jalan yang benar. Kelima nilai di
 * bawah adalah lima kalimat yang berbeda — dan LeadPipelineService menuliskan
 * kalimatnya satu kali untuk SEMUA permukaan.
 */
enum LeadMove: string
{
    /** Sudah berada di tahap itu. */
    case Sama = 'sama';

    /** Maju ke tahap berikutnya (atau melompati beberapa) — bebas. */
    case Maju = 'maju';

    /** Mundur ke tahap sebelumnya — boleh, dengan alasan yang tersimpan. */
    case Mundur = 'mundur';

    /** Menang/Kalah: hanya lewat penawaran, tidak pernah lewat tahap. */
    case LewatPenawaran = 'lewat_penawaran';

    /** Prospek sudah menang/kalah: nasibnya milik penawarannya. */
    case Terkunci = 'terkunci';

    public function requiresReason(): bool
    {
        return $this === self::Mundur;
    }

    /** Perpindahan yang boleh dijalankan layanan tahap (dengan atau tanpa alasan). */
    public function isAllowed(): bool
    {
        return $this === self::Maju || $this === self::Mundur;
    }
}
