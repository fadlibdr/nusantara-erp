<?php

namespace Modules\Core\Models;

/**
 * Baris tabel failed_jobs Laravel (Fase 0 / P-0b, T0b.4), dibaca layar
 * Sistem › Antrean Gagal. Model tipis tanpa timestamps: tabelnya milik
 * kerangka kerja (failed_at diisi useCurrent), dan yang menulisnya hanya
 * pekerja antrean lewat FailedJobProviderInterface — tidak pernah kode ini.
 */
class FailedJob extends BaseModel
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    /** Nama job dari payload (displayName), atau `?` bila payload tidak terbaca. */
    public function displayName(): string
    {
        $payload = json_decode((string) $this->payload, true);

        return is_array($payload) && is_string($payload['displayName'] ?? null) && $payload['displayName'] !== ''
            ? $payload['displayName']
            : '?';
    }

    /** Baris pertama pengecualian: "RuntimeException: SMTP 550 … in …:12". */
    public function exceptionExcerpt(): string
    {
        $first = strtok((string) $this->exception, "\n");

        return is_string($first) && $first !== '' ? mb_substr($first, 0, 300) : '?';
    }
}
