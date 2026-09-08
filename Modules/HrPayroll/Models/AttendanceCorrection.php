<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu perubahan pada satu baris absensi. Tambah-saja — lihat migrasi
 * 001092. Tidak ada service yang memanggil update() atau delete() di sini,
 * dan AttendanceCorrectionTest memakukannya dari tiga sisi: tidak ada rute
 * update/delete untuk jejaknya, baris absensi yang membawa jejak tidak bisa
 * dihapus, dan FK-nya restrictOnDelete sebagai lapis basis data.
 */
class AttendanceCorrection extends BaseModel
{
    protected $table = 'hr_attendance_corrections';

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
