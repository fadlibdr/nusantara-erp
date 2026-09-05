<?php

namespace Tests\Feature\Projects;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\HseDaily;
use Modules\Projects\Models\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * "Satu laporan per proyek per hari" berlaku untuk baris HIDUP saja — dan
 * berlaku sama di SQLite maupun MySQL (Fase 0, T0.2).
 *
 * Di SQLite janji itu dipegang indeks unik parsial (000721, 000742); di MySQL
 * oleh kolom generated live_key + UNIQUE(project_id, report_date, live_key)
 * (000746). Uji ini tidak bertanya driver mana yang dipakai: ia menjalankan
 * alur yang dulu pecah 500 permanen (hapus laporan, catat ulang hari yang
 * sama), memastikan duplikat hidup tetap dijawab 422 dengan kalimat yang sama,
 * lalu menembus validasi dengan INSERT mentah untuk membuktikan indeksnya
 * sendiri yang menolak — bukan hanya Rule-nya. Dijalankan pada kedua driver
 * (phpunit.xml untuk SQLite; DB_CONNECTION=mysql untuk phpunit.mysql.xml).
 *
 * INSERT mentah menyalin report_date PERSIS seperti tersimpan — SQLite
 * membandingkan string mentah di indeks ("2026-03-25 00:00:00" vs
 * "2026-03-25" tidak pernah sama, temuan T1), MySQL membandingkan DATE.
 * Menyalin nilai tersimpan membuat pertanyaan yang sama di keduanya.
 */
class DailyReportUniqueLiveRowsTest extends ErpTestCase
{
    private const DAILY_422 = 'Sudah ada laporan harian untuk proyek ini pada tanggal tersebut.';

    private const HSE_422 = 'Formulir K3 harian untuk proyek dan tanggal ini sudah ada.';

    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-081',
            'name' => 'Rusun ASN Cibubur',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyPayload(Project $project): array
    {
        return [
            'project_id' => $project->id,
            'report_date' => '2026-03-25',
            'manpower_count' => 12,
            'activities' => 'Pengecoran kolom lantai 2 zona A',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hsePayload(Project $project): array
    {
        return [
            'project_id' => $project->id,
            'report_date' => '2026-03-25',
            'toolbox_topic' => 'Bekerja di ketinggian',
            'toolbox_attendees' => ['Agus Prasetyo'],
            'apd' => [['category' => 'helm', 'qty' => 12]],
            'findings' => [],
        ];
    }

    public function test_a_deleted_daily_report_day_can_be_recorded_again_and_a_live_duplicate_is_still_422(): void
    {
        $project = $this->project();
        $this->actingAs($this->adminUser());

        $first = $this->postJson('api/projects/daily-reports', $this->dailyPayload($project))
            ->assertCreated()->json('data.id');

        DailyReport::query()->findOrFail($first)->delete();
        $this->assertSoftDeleted('prj_daily_reports', ['id' => $first]);

        // The flow that used to be a permanent 500: the day was deleted, and
        // is recorded again.
        $second = $this->postJson('api/projects/daily-reports', $this->dailyPayload($project))
            ->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);

        // The live duplicate is still refused, in the same sentence.
        $this->postJson('api/projects/daily-reports', $this->dailyPayload($project))
            ->assertStatus(422)
            ->assertJsonPath('errors.report_date.0', self::DAILY_422);

        $this->assertSame(2, DailyReport::withTrashed()->where('project_id', $project->id)->count());
        $this->assertSame(1, DailyReport::query()->where('project_id', $project->id)->count());
    }

    public function test_a_deleted_hse_daily_day_can_be_recorded_again_and_a_live_duplicate_is_still_422(): void
    {
        $project = $this->project();
        $this->actingAs($this->adminUser());

        $first = $this->postJson('api/projects/hse-daily', $this->hsePayload($project))
            ->assertCreated()->json('data.id');

        HseDaily::query()->findOrFail($first)->delete();
        $this->assertSoftDeleted('prj_hse_daily', ['id' => $first]);

        $second = $this->postJson('api/projects/hse-daily', $this->hsePayload($project))
            ->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);

        $this->postJson('api/projects/hse-daily', $this->hsePayload($project))
            ->assertStatus(422)
            ->assertJsonPath('errors.report_date.0', self::HSE_422);

        $this->assertSame(2, HseDaily::withTrashed()->where('project_id', $project->id)->count());
    }

    #[DataProvider('tables')]
    public function test_the_index_itself_refuses_a_live_duplicate_and_ignores_deleted_rows(string $table, string $prefix): void
    {
        $project = $this->project();

        $live = DB::table($table)->insertGetId($this->rawRow($table, $prefix, $project, 1));
        $stored = DB::table($table)->where('id', $live)->value('report_date');

        // Deleted rows never collide — with the live one, or with each other.
        foreach ([2, 3] as $n) {
            DB::table($table)->insert($this->rawRow($table, $prefix, $project, $n, $stored, deleted: true));
        }

        $this->assertSame(3, DB::table($table)->where('project_id', $project->id)->count());

        // A second LIVE row for the same (project, date), past every Rule:
        // this is the index speaking, on whichever driver runs the suite.
        try {
            DB::table($table)->insert($this->rawRow($table, $prefix, $project, 4, $stored));
            $this->fail("{$table} accepted a second live row for the same project and date");
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/unique|duplicate/i', $e->getMessage());
        }

        $this->assertSame(3, DB::table($table)->where('project_id', $project->id)->count());
    }

    /**
     * The race neither validator can see: two requests for the same day pass
     * UniqueDailyReportDate / assertSingleSheetPerDay at the same moment
     * (neither row exists yet) and the unique index refuses the second
     * INSERT. Measured by the burst harness (T0.4, MySQL 8.0.46, 5 Sep 2026):
     * 3 of 4 parallel POSTs answered 500 "1062 Duplicate entry" before the
     * services caught it. Simulated here by letting the OTHER request's row
     * land from the model's `creating` hook — after this request's validator
     * looked, before its INSERT — so the refusal has to come from the index.
     */
    #[DataProvider('races')]
    public function test_a_duplicate_that_lands_between_the_validator_and_the_insert_is_the_same_422_not_a_500(
        string $table,
        string $prefix,
        string $model,
        string $route,
        string $payload,
        string $sentence,
    ): void {
        $project = $this->project();
        $this->actingAs($this->adminUser());
        $fired = false;

        // '2026-03-25 00:00:00' is the exact text the date cast writes on
        // SQLite (where the index compares strings); MySQL folds it into DATE.
        $model::creating(function () use (&$fired, $table, $prefix, $project): void {
            $fired = true;
            DB::table($table)->insert($this->rawRow($table, $prefix, $project, 9, '2026-03-25 00:00:00'));
        });

        $this->postJson($route, $this->{$payload}($project))
            ->assertStatus(422)
            ->assertJsonPath('errors.report_date.0', $sentence);

        $this->assertTrue($fired, 'The validator refused before the INSERT — the race was not simulated.');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: class-string, 3: string, 4: string, 5: string}>
     */
    public static function races(): array
    {
        return [
            'laporan harian' => ['prj_daily_reports', 'DRP', DailyReport::class, 'api/projects/daily-reports', 'dailyPayload', self::DAILY_422],
            'formulir K3 harian' => ['prj_hse_daily', 'HSE', HseDaily::class, 'api/projects/hse-daily', 'hsePayload', self::HSE_422],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tables(): array
    {
        return [
            'prj_daily_reports' => ['prj_daily_reports', 'DRP'],
            'prj_hse_daily' => ['prj_hse_daily', 'HSE'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRow(string $table, string $prefix, Project $project, int $n, ?string $storedDate = null, bool $deleted = false): array
    {
        $row = [
            'code' => sprintf('%s/2026/03/%04d', $prefix, $n),
            'project_id' => $project->id,
            'report_date' => $storedDate ?? '2026-03-25',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ];

        if ($table === 'prj_daily_reports') {
            $row += ['manpower_count' => 12, 'activities' => 'Pengecoran kolom'];
        }

        return $row;
    }
}
