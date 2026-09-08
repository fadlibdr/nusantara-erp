<?php

namespace Modules\Crm\Enums;

/**
 * Lima bentuk pekerjaan penjualan yang benar-benar dicatat orang.
 *
 * Bukan daftar bebas: setiap nilai di sini muncul sebagai kolom saring dan
 * sebagai lencana di kartu aktivitas, dan sebuah jenis yang diketik bebas
 * ("telp", "telepon", "call") akan memecah satu antrean kerja menjadi tiga
 * yang tidak pernah bisa dijumlahkan.
 */
enum ActivityType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Email = 'email';
    case Visit = 'visit';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Telepon',
            self::Meeting => 'Rapat',
            self::Email => 'Email',
            self::Visit => 'Kunjungan',
            self::Note => 'Catatan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
