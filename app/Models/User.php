<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Core\Support\TokenScope;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /*
     * `hasPermissionTo` dari spatie datang lewat TRAIT, bukan lewat kelas
     * induk — jadi `parent::hasPermissionTo()` tidak ada (fatal
     * "Call to undefined method", terukur saat matriks ability pertama
     * dijalankan). Aliasnya di bawah ini yang memberi versi asli itu sebuah
     * nama, supaya metode kelas ini bisa memanggilnya sebelum mempersempit.
     */
    use HasApiTokens, HasFactory, HasRoles, Notifiable {
        HasRoles::hasPermissionTo as private permissionFromRolesAndDirectGrants;
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * SATU TEMPAT YANG DILEWATI SETIAP PEMERIKSAAN IZIN (P-3d, perangkap A).
     *
     * Middleware rute spatie (`canAny`), `Gate::before` yang didaftarkan
     * spatie (`checkPermissionTo`), `$request->user()->can()` di dalam
     * controller dan `hasAnyPermission()` di dalam service — semuanya bermuara
     * di sini. Sebuah token pribadi yang abilitynya tidak memuat izin ini
     * kehilangan izin itu, dan hanya itu: versi spatie-nya tetap
     * kata pertama, jadi penyempitannya hanya pernah MENGURANGI. Izin yang
     * dicabut dari peran mencabut aksesnya token pada permintaan berikutnya
     * juga — ability adalah SUBSET, bukan pemberian.
     *
     * Alasan tempat ini dipilih, dan mengapa bukan `Gate::before` maupun
     * parameter `permission:` pada rutenya: Modules\Core\Support\TokenScope.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        if (! $this->permissionFromRolesAndDirectGrants($permission, $guardName)) {
            return false;
        }

        // Namanya diselesaikan lewat jalur spatie sendiri (koleksi izin yang
        // sudah di-cache, bukan kueri baru) supaya sebuah Permission, sebuah
        // enum atau sebuah id menghasilkan nama yang sama dengan yang tersimpan
        // di kolom abilities token.
        return app(TokenScope::class)->allows($this, $this->filterPermission($permission, $guardName)->name);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            // Deliberately not fillable: only PUT iam/me/onboarding writes it,
            // through forceFill, on the caller's own record.
            'onboarding_seen_at' => 'datetime',
            // P-3a: phone_e164 / whatsapp_opt_in_* are written ONLY through
            // Modules\Core\Support\WhatsAppConsent (forceFill) — consent is
            // stamped, never mass-assigned.
            'whatsapp_opt_in_at' => 'datetime',
        ];
    }
}
