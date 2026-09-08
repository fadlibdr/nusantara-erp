<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\AttendanceBulkRequest;
use Modules\HrPayroll\Http\Requests\AttendanceClockRequest;
use Modules\HrPayroll\Http\Requests\AttendanceUpdateRequest;
use Modules\HrPayroll\Http\Resources\AttendanceCorrectionResource;
use Modules\HrPayroll\Http\Resources\AttendanceResource;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Services\AttendanceClockService;
use Modules\HrPayroll\Services\AttendanceCorrectionService;
use Modules\HrPayroll\Services\AttendanceService;

class AttendanceController extends ApiController
{
    public function __construct(
        private readonly AttendanceService $service,
        private readonly AttendanceClockService $clock,
        private readonly AttendanceCorrectionService $corrections,
    ) {}

    /**
     * Satu kalimat, dipakai daftar maupun tombol absen.
     *
     * Akun tanpa `employee_id` bukan kesalahan pemakainya dan bukan galat
     * sistem: akun integrasi, admin, dan orang yang datanya belum ditautkan HR
     * semuanya sah. Yang tidak boleh terjadi adalah layar kosong tanpa sebab,
     * atau — jauh lebih buruk — absensi yang jatuh ke karyawan lain.
     */
    private const NO_EMPLOYEE_MESSAGE = 'Akun ini belum ditautkan ke data karyawan, '
        .'jadi tidak ada absensi yang bisa ditampilkan atau dicatat atas namanya. '
        .'Minta HR menautkan akun Anda ke kartu karyawan (Karyawan → akun pengguna).';

    /**
     * Kartu karyawan yang SUDAH ditautkan tetapi diarsipkan.
     *
     * Kalimat di atas akan menyuruh orangnya meminta HR menautkan akun yang
     * SUDAH tertaut; HR memeriksanya, menemukannya benar, dan tidak punya apa
     * pun untuk dikerjakan. Dua keadaan, dua kalimat.
     */
    private const ARCHIVED_EMPLOYEE_MESSAGE = 'Kartu karyawan yang tertaut ke akun ini sudah '
        .'diarsipkan, jadi absensi tidak dapat dicatat atas namanya. Minta HR mengaktifkan kembali '
        .'kartu karyawan Anda.';

    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query()
            ->with(['employee', 'project'])
            ->when($request->filled('date'), fn ($query) => $query->whereDate('date', $request->string('date')))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->whereHas('employee', function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('date')
            ->orderBy('employee_id');

        return $this->listing($request, $query, AttendanceResource::class,
            sortable: ['date', 'status'], dateColumn: 'date');
    }

    /**
     * The site sheet in one POST — see AttendanceService::bulkUpsert for why
     * it upserts instead of inserting.
     */
    public function bulk(AttendanceBulkRequest $request): JsonResponse
    {
        $result = $this->service->bulkUpsert($request->validated(), $request->user()?->id);

        return $this->ok(
            $result,
            sprintf('Absensi tersimpan: %d baru, %d diperbarui.', $result['created'], $result['updated']),
        );
    }

    public function show(Attendance $attendance): JsonResponse
    {
        return $this->ok(AttendanceResource::make($attendance->load(['employee', 'project'])));
    }

    /**
     * Koreksi pengawas — TAMBAH-SAJA di sisi jejaknya.
     *
     * Barisnya memang ditimpa (layar harus menampilkan satu kebenaran), tetapi
     * setiap nilai lama disimpan lebih dulu di hr_attendance_corrections
     * bersama alasan yang diketik. Sejak absensi bisa diisi orangnya sendiri,
     * pintu ini menimpa catatan seseorang tentang dirinya; tanpa jejak,
     * pertanyaan "siapa memindahkan jam pulang saya, kapan, kenapa" tidak
     * punya jawaban di mana pun.
     */
    public function update(AttendanceUpdateRequest $request, Attendance $attendance): JsonResponse
    {
        $data = $request->validated();
        $reason = (string) $data['reason'];
        unset($data['reason']);

        $attendance->fill($data);
        $pending = $this->corrections->pending($attendance);
        $attendance->save();
        $this->corrections->write($attendance, $pending, $reason, 'update', $request->user()?->id);

        return $this->ok(
            AttendanceResource::make($attendance->load(['employee', 'project'])),
            $pending === []
                ? 'Tidak ada nilai yang berubah — tidak ada koreksi yang dicatat.'
                : sprintf('%d nilai dikoreksi dan tercatat beserta alasannya.', count($pending)),
        );
    }

    /**
     * Jejak koreksi satu baris. Membaca absensi orang lain menuntut hr.view
     * (rute), jadi jejaknya tidak lebih mudah dijangkau daripada barisnya.
     */
    public function corrections(Attendance $attendance): JsonResponse
    {
        return $this->ok(AttendanceCorrectionResource::collection(
            $attendance->corrections()->orderBy('id')->get(),
        ));
    }

    /**
     * Absensi SAYA — tanpa izin hr.*, dan hanya baris milik pemanggil.
     *
     * Register absensi dijaga hr.view sejak F-4 karena barisnya kini membawa
     * koordinat dan selfie orang. Itu menutup layar "Absensi Saya" untuk hampir
     * semua orang yang justru memakainya, jadi pintu ini ada: bukan penyaring
     * di atas daftar yang sama, melainkan kueri yang secara struktur tidak bisa
     * mengembalikan baris orang lain.
     */
    public function mine(Request $request): JsonResponse
    {
        ['employee' => $employee, 'message' => $refusal] = $this->employeeOf($request);

        if ($employee === null) {
            return $this->ok([
                'linked' => false,
                'employee' => null,
                'data' => [],
            ], $refusal);
        }

        $rows = Attendance::query()
            ->with(['project', 'employee'])
            ->where('employee_id', $employee->id)
            ->when($request->filled('from'), fn ($query) => $query->whereDate('date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('date', '<=', $request->string('to')))
            ->orderByDesc('date')
            ->limit(60)
            ->get();

        return $this->ok([
            'linked' => true,
            'employee' => ['id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name],
            'geofence_m' => $this->clock->geofenceMetres(),
            'data' => AttendanceResource::collection($rows)->resolve(),
        ]);
    }

    public function clockIn(AttendanceClockRequest $request): JsonResponse
    {
        return $this->punch($request, AttendanceClockService::SIDE_IN);
    }

    public function clockOut(AttendanceClockRequest $request): JsonResponse
    {
        return $this->punch($request, AttendanceClockService::SIDE_OUT);
    }

    private function punch(AttendanceClockRequest $request, string $side): JsonResponse
    {
        ['employee' => $employee, 'message' => $refusal] = $this->employeeOf($request);

        if ($employee === null) {
            return $this->error($refusal, 422);
        }

        $result = $this->clock->clock($side, $employee, $request->validated(), $request->user()?->id);

        $message = $result['message'];

        // Foto yang ditolak TIDAK membatalkan kehadirannya — dan tidak boleh
        // hilang diam-diam juga. Kalimatnya digabung supaya satu toast
        // mengatakan dua hal yang benar sekaligus.
        if ($result['selfie_error'] !== null) {
            $message .= ' Foto tidak tersimpan: '.$result['selfie_error'];
        }

        return $this->ok([
            'outcome' => $result['outcome'],
            'selfie_error' => $result['selfie_error'],
            'attendance' => AttendanceResource::make(
                $result['attendance']->load(['employee', 'project'])
            )->resolve(),
        ], $message);
    }

    /**
     * Menghapus baris absensi — kecuali baris yang sudah punya sejarah.
     *
     * Sebuah baris yang PERNAH DIKOREKSI membawa jejak tambah-saja, dan sebuah
     * jejak yang bisa dimusnahkan oleh pemegang hr.delete tidak membuktikan
     * apa pun: yang paling berkepentingan menghapusnya adalah orang yang
     * dicatat di dalamnya. Baris yang membawa absen dari ponsel juga ditolak —
     * itu catatan seseorang tentang dirinya, bukan ketikan kerani; yang salah
     * dikoreksi lewat pintu koreksi, yang beralasan dan berjejak.
     *
     * Yang masih bisa dihapus: baris kerani murni yang belum pernah disentuh
     * siapa pun. Itulah kasus yang pintu ini memang dibuat untuknya (lembar
     * ganda, tanggal salah ketik).
     */
    public function destroy(Attendance $attendance): JsonResponse
    {
        if ($attendance->corrections()->exists()) {
            return $this->error(
                'Baris ini sudah pernah dikoreksi, jadi ia membawa jejak yang tidak boleh ikut hilang. '
                .'Perbaiki nilainya lewat Rincian → Koreksi; jejaknya tetap terbaca di sana.',
                422,
            );
        }

        if ($attendance->check_in_at !== null || $attendance->check_out_at !== null) {
            return $this->error(
                'Baris ini berisi absen dari ponsel yang dicatat karyawannya sendiri dan tidak dapat dihapus. '
                .'Kalau isinya keliru, koreksi lewat Rincian → Koreksi dengan menyebut alasannya.',
                422,
            );
        }

        $attendance->delete();

        return $this->ok(null, 'Attendance deleted.');
    }

    /**
     * Kartu karyawan pemanggil, atau kalimat yang menjelaskan kenapa tidak ada.
     *
     * @return array{employee: ?Employee, message: ?string}
     */
    private function employeeOf(Request $request): array
    {
        $employeeId = $request->user()?->employee_id;

        if ($employeeId === null) {
            return ['employee' => null, 'message' => self::NO_EMPLOYEE_MESSAGE];
        }

        $employee = Employee::query()->find($employeeId);

        if ($employee !== null) {
            return ['employee' => $employee, 'message' => null];
        }

        // Tertaut, tetapi kartunya di-soft-delete: keadaan yang berbeda, dan
        // kalimat yang berbeda. withTrashed() membedakan keduanya tanpa
        // membuka jalan menulis absensi atas nama kartu yang diarsipkan.
        $archived = Employee::query()->withTrashed()->find($employeeId);

        return [
            'employee' => null,
            'message' => $archived === null ? self::NO_EMPLOYEE_MESSAGE : self::ARCHIVED_EMPLOYEE_MESSAGE,
        ];
    }
}
