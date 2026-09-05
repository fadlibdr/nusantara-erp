<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Support\AlwaysFailingJob;

/**
 * Sistem › Antrean Gagal (Fase 0 / P-0b, T0b.4): tabel failed_jobs dari layar.
 *
 * Barisnya dibuat oleh pekerja SUNGGUHAN (queue:work database --once) atas job
 * yang memang gagal — bukan INSERT tangan — supaya bentuk baris yang dibaca
 * layar adalah bentuk yang ditulis kerangka kerja. Kirim ulang memindahkannya
 * kembali ke tabel jobs; Hapus hanya membuang catatannya.
 */
class QueueFailedJobsTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    private function userWith(string $permission): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('role-'.md5($permission), 'web');
        $role->givePermissionTo($permission);

        $user = User::query()->create([
            'name' => 'Pemegang '.$permission,
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** Antrekan satu job yang gagal dan biarkan pekerja menjatuhkannya ke failed_jobs. */
    private function failOne(string $reason = 'SMTP 550 mailbox unavailable'): string
    {
        AlwaysFailingJob::dispatch($reason);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Job dengan tries=1 harus mendarat di failed_jobs setelah satu kali.');

        return (string) DB::table('failed_jobs')->value('uuid');
    }

    public function test_a_failed_job_appears_in_the_list_with_its_first_exception_line(): void
    {
        $admin = $this->adminUser();
        $uuid = $this->failOne('SMTP 550 mailbox unavailable');

        $this->actingAs($admin, 'sanctum');
        $rows = $this->getJson('/api/core/queue/failed')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($uuid, $rows[0]['uuid']);
        $this->assertSame($uuid, $rows[0]['code']);
        $this->assertSame('default', $rows[0]['queue']);
        $this->assertSame(AlwaysFailingJob::class, $rows[0]['job']);
        $this->assertStringStartsWith('RuntimeException: SMTP 550 mailbox unavailable', $rows[0]['exception_excerpt']);
        $this->assertStringNotContainsString("\n", $rows[0]['exception_excerpt']);
        $this->assertArrayNotHasKey('exception', $rows[0], 'Daftar tidak membawa jejak tumpukan.');
        $this->assertNotNull($rows[0]['failed_at']);

        $detail = $this->getJson('/api/core/queue/failed/'.$rows[0]['id'])->assertOk()->json('data');
        $this->assertStringContainsString('#0 ', $detail['exception'], 'Detail membawa jejak tumpukannya.');
    }

    public function test_retry_moves_the_job_back_to_the_queue(): void
    {
        $admin = $this->adminUser();
        $this->failOne();
        $id = (int) DB::table('failed_jobs')->value('id');

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/core/queue/failed/{$id}/retry")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Job dikembalikan ke antrean; pekerja akan mencobanya lagi.']);

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Catatan gagal dihapus setelah kembali ke antrean.');
        $this->assertSame(1, DB::table('jobs')->count(), 'Job harus kembali ke tabel jobs.');
        $this->assertSame(0, (int) DB::table('jobs')->value('attempts'), 'queue:retry mereset attempts.');

        // Dan pekerja benar-benar mencobanya lagi (job ini memang selalu gagal).
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_delete_removes_only_the_record(): void
    {
        $admin = $this->adminUser();
        $this->failOne();
        $id = (int) DB::table('failed_jobs')->value('id');

        $this->actingAs($admin, 'sanctum');
        $this->deleteJson("/api/core/queue/failed/{$id}")->assertOk();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count(), 'Hapus tidak menjalankan ulang job.');
        $this->getJson("/api/core/queue/failed/{$id}")->assertNotFound();
    }

    public function test_the_screen_is_gated_to_settings_holders(): void
    {
        $this->failOne();
        $id = (int) DB::table('failed_jobs')->value('id');

        $this->getJson('/api/core/queue/failed')->assertUnauthorized();

        $this->actingAs($this->userWith('fin.view'), 'sanctum');
        $this->getJson('/api/core/queue/failed')->assertForbidden();
        $this->postJson("/api/core/queue/failed/{$id}/retry")->assertForbidden();
        $this->deleteJson("/api/core/queue/failed/{$id}")->assertForbidden();

        // core.update boleh membaca dan mengirim ulang, tetapi menghapus butuh core.delete.
        $this->actingAs($this->userWith('core.update'), 'sanctum');
        $this->getJson('/api/core/queue/failed')->assertOk();
        $this->deleteJson("/api/core/queue/failed/{$id}")->assertForbidden();
    }

    public function test_health_counts_it_and_the_spa_carries_the_screen(): void
    {
        $admin = $this->adminUser();
        $this->failOne();

        $this->actingAs($admin, 'sanctum');
        $this->assertSame(1, $this->getJson('/api/core/health')->assertOk()->json('data.failed_jobs_count'));

        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $this->assertStringContainsString("  'core/queue/failed': {", $schema);
        $this->assertStringContainsString("route: 'r/core/queue/failed', perm: 'core.update'", $schema);
        $this->assertStringContainsString("api: 'core/queue/failed'", $schema);
    }
}
