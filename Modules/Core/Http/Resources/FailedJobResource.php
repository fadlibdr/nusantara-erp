<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris Sistem › Antrean Gagal. Daftar hanya membawa baris pertama
 * pengecualian; detail (show) membawa jejak tumpukannya, dipotong 8 KB —
 * cukup untuk membaca sebabnya, tidak cukup untuk menjatuhkan peramban.
 */
class FailedJobResource extends JsonResource
{
    public bool $withTrace = false;

    /** Varian detail: ikut membawa jejak tumpukan. */
    public static function withTrace(mixed $resource): static
    {
        $instance = new static($resource);
        $instance->withTrace = true;

        return $instance;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->uuid,
            'uuid' => $this->uuid,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'job' => $this->displayName(),
            'exception_excerpt' => $this->exceptionExcerpt(),
            'exception' => $this->when($this->withTrace, fn () => mb_substr((string) $this->exception, 0, 8000)),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
