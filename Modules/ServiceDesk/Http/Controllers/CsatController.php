<?php

namespace Modules\ServiceDesk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\ServiceDesk\Http\Requests\CsatLinkStoreRequest;
use Modules\ServiceDesk\Http\Resources\CsatRatingResource;
use Modules\ServiceDesk\Models\CsatRating;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\CsatService;

/**
 * Sisi ber-sesi CSAT: menerbitkan undangan, mencabutnya, membacanya, dan
 * ringkasan yang jujur.
 *
 * GERBANG (perangkap E — ditegakkan di rute, dijelaskan di sana): membaca =
 * permission:svc.view, LEBIH KETAT daripada rute baca tiketnya sendiri yang
 * hanya bersesi. Menerbitkan dan mencabut = svc.update. Kedua endpoint baca di
 * bawah ini adalah satu-satunya tempat `comment` menyeberangi kawat.
 *
 * TOKEN POLOS TAMPIL TEPAT SEKALI, di respons store() ini. Tidak ada endpoint
 * yang bisa membacanya lagi, dan tidak ada satu jalur log pun yang menyentuhnya.
 */
class CsatController extends ApiController
{
    public function __construct(private readonly CsatService $service) {}

    /** Undangan + penilaian milik satu tiket. */
    public function index(Ticket $ticket): JsonResponse
    {
        $rows = CsatRating::query()
            ->where('ticket_id', $ticket->getKey())
            ->with('issuedBy:id,name', 'revokedBy:id,name')
            ->orderByDesc('id')
            ->get();

        return $this->ok(CsatRatingResource::collection($rows), null, [
            // Layar butuh tahu MENGAPA tombolnya tidak ada, bukan hanya bahwa
            // ia tidak ada — sebuah tombol yang hilang tanpa kalimat terbaca
            // sebagai fitur yang rusak.
            'ratable' => in_array($ticket->status?->value, CsatService::RATABLE, true),
            'ratable_statuses' => CsatService::RATABLE,
            'ticket_status' => $ticket->status?->value,
            'already_rated' => $this->service->ratingFor($ticket) !== null,
            // Masa berlaku dikirim SERVER, tidak dipegang dua pihak: dialog
            // penerbitan menuliskannya ("Kosongkan untuk N hari") dan medan
            // harinya berbatas atasnya. Sebuah salinan di SPA berarti mengubah
            // konstantanya membuat dialognya berbohong dengan suite tetap
            // hijau — hanya SATU uji yang memerah (terukur 10 Sep 2026).
            'default_validity_days' => CsatService::DEFAULT_VALIDITY_DAYS,
        ]);
    }

    public function store(CsatLinkStoreRequest $request, Ticket $ticket): JsonResponse
    {
        $issued = $this->service->issue($request->user(), $ticket, $request->validated());

        return $this->created([
            'rating' => CsatRatingResource::make($issued['rating']),
            // Sekali. Di sini. Tidak ada jalan membacanya lagi.
            'url' => $issued['url'],
        ]);
    }

    public function revoke(Request $request, CsatRating $csatRating): JsonResponse
    {
        return $this->ok(
            CsatRatingResource::make($this->service->revoke($csatRating, $request->user())),
            'Tautan penilaian dicabut.',
        );
    }

    /**
     * Ringkasan CSAT — dan penilaian yang sudah masuk, lengkap dengan
     * komentarnya (gerbangnya svc.view, sama dengan tiketnya).
     *
     * meta membawa ketiga penyebut bersama rata-ratanya, bukan sebagai
     * kelengkapan melainkan sebagai penjagaan: klien yang menerima `average`
     * sendirian akan memajangnya sendirian.
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'service_contract_id' => $request->query('service_contract_id'),
            'customer_id' => $request->query('customer_id'),
        ];

        $summary = $this->service->summary($filters);

        $rated = $this->service->ratedQuery($filters)
            ->paginate($request->integer('per_page', 20) ?: 20);

        return $this->ok(CsatRatingResource::collection($rated), null, [
            'summary' => $summary,
            'current_page' => $rated->currentPage(),
            'per_page' => $rated->perPage(),
            'total' => $rated->total(),
            'last_page' => $rated->lastPage(),
        ]);
    }
}
