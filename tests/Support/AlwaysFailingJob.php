<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Job uji yang selalu gagal pada percobaan pertama — bahan baku tabel
 * failed_jobs untuk QueueFailedJobsTest (Fase 0 / P-0b, T0b.4). Kelas
 * bernama, bukan anonim: payload antrean menyerialkan nama kelasnya dan
 * pekerja harus bisa memuatnya kembali.
 */
class AlwaysFailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $reason = 'Sengaja gagal untuk uji') {}

    public function handle(): void
    {
        throw new RuntimeException($this->reason);
    }
}
