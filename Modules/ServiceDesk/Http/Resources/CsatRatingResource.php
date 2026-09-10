<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu undangan CSAT — dan, bila sudah dipakai, penilaiannya.
 *
 * TIDAK ADA token_hash di sini, dan tidak akan pernah ada: hash-nya tidak
 * berguna bagi klien dan satu-satunya hal yang bisa dilakukan orang dengannya
 * adalah mencocokkan tebakan di luar throttle. Token polosnya sendiri hanya
 * pernah hidup satu kali, di respons penerbitan (CsatController::store).
 *
 * `comment` IKUT di sini karena seluruh permukaan ini bergerbang svc.view —
 * gerbang yang sama dengan tiketnya. Ia TIDAK ikut ke TicketResource pada
 * daftar tiket: daftar itu juga melayani pemilih (lookup.js memaginasi
 * endpoint yang sama sampai plafonnya, `ROW_CEILING = MAX_PAGES * PAGE_SIZE`),
 * dan komentar pelanggan tentang seorang teknisi tidak punya urusan di dalam
 * sebuah pemilih.
 */
class CsatRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'ticket_code' => $this->whenLoaded('ticket', fn () => $this->ticket?->code),
            'ticket_title' => $this->whenLoaded('ticket', fn () => $this->ticket?->title),
            'recipient_name' => $this->recipient_name,
            'recipient_email' => $this->recipient_email,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'issued_by_name' => $this->whenLoaded('issuedBy', fn () => $this->issuedBy?->name),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by_name' => $this->whenLoaded('revokedBy', fn () => $this->revokedBy?->name),
            'score' => $this->score?->value,
            'score_label' => $this->score?->label(),
            'comment' => $this->comment,
            'rated_at' => $this->rated_at?->toIso8601String(),
            'rated_via' => $this->rated_via,
            // Keadaan turunan, dihitung server supaya kartu SPA dan halaman
            // publik tidak bisa berselisih tentang baris yang sama.
            'state' => match (true) {
                $this->isRated() => 'rated',
                $this->isRevoked() => 'revoked',
                $this->isExpired() => 'expired',
                default => 'live',
            },
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
