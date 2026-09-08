<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Enums\AttendanceStatus;
use Modules\Projects\Models\Project;

class Attendance extends BaseModel
{
    protected $table = 'hr_attendances';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
            'check_in_at' => 'datetime',
            'check_in_device_at' => 'datetime',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'check_in_accuracy_m' => 'integer',
            'check_in_distance_m' => 'integer',
            'check_in_geofence_m' => 'integer',
            'check_out_at' => 'datetime',
            'check_out_device_at' => 'datetime',
            'check_out_latitude' => 'decimal:7',
            'check_out_longitude' => 'decimal:7',
            'check_out_accuracy_m' => 'integer',
            'check_out_distance_m' => 'integer',
            'check_out_geofence_m' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class, 'attendance_id');
    }

    /**
     * "Di luar lokasi proyek?" — true, false, atau NULL karena tidak diketahui.
     *
     * Dihitung, tidak disimpan: kolom turunan yang disimpan adalah kolom yang
     * suatu hari melenceng dari sumbernya (migrasi 001091). Tiga keadaan, bukan
     * dua — sebuah absensi tanpa koordinat perangkat, atau pada proyek yang
     * belum punya titik peta, TIDAK berada di dalam geofence dan TIDAK berada
     * di luarnya: tidak ada yang tahu. Mengembalikan false untuk kasus itu
     * berarti layar berkata "di lokasi" tentang seseorang yang posisinya tidak
     * pernah terukur, dan itulah kebohongan yang paling mudah dipercaya.
     */
    public function outsideGeofence(string $side): ?bool
    {
        $distance = $this->{"{$side}_distance_m"};
        $limit = $this->{"{$side}_geofence_m"};

        if ($distance === null || $limit === null) {
            return null;
        }

        return (int) $distance > (int) $limit;
    }
}
