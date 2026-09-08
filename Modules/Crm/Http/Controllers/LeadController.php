<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Core\Http\ApiController;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Http\Requests\LeadPipelineRequest;
use Modules\Crm\Http\Requests\LeadStoreRequest;
use Modules\Crm\Http\Requests\LeadUpdateRequest;
use Modules\Crm\Http\Resources\CustomerResource;
use Modules\Crm\Http\Resources\LeadResource;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\LeadPipelineService;
use Modules\Crm\Services\LeadService;

class LeadController extends ApiController
{
    public function __construct(
        private readonly LeadService $service,
        private readonly LeadPipelineService $pipeline,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Lead::query()
            ->with('owner')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('company_name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('owner_user_id'), fn ($query) => $query->where('owner_user_id', $request->integer('owner_user_id')))
            /*
             * "Belum ditugaskan" adalah saringan yang sungguhan dipakai: satu
             * klik menjawab "prospek mana yang tidak dikejar siapa pun" — dan
             * kliknya ADA, di bilah saringan daftar prospek (schema.js,
             * boolFilter). Sampai 8 Sep 2026 komentar ini menjanjikan afordansi
             * yang tidak digambar layar mana pun, dan ?unassigned=1 yang
             * dirakit tangan dibuang diam-diam oleh seedFromUrl karena kuncinya
             * tidak dideklarasikan.
             *
             * `filled`, bukan `boolean`: 0 juga sebuah jawaban ("yang SUDAH
             * ditugaskan"), dan pilihan "Tidak" yang diam-diam memulangkan
             * seluruh baris adalah saringan yang berbohong.
             */
            ->when($request->filled('unassigned'), fn ($query) => $request->boolean('unassigned')
                ? $query->whereNull('owner_user_id')
                : $query->whereNotNull('owner_user_id'))
            ->orderByDesc('id');

        return $this->listing($request, $query, LeadResource::class,
            sortable: ['code', 'name', 'source', 'estimated_value', 'status', 'next_follow_up_at']);
    }

    public function store(LeadStoreRequest $request): JsonResponse
    {
        $lead = Lead::query()->create($this->writable($request->validated(), status: true));

        // Dibaca ulang, bukan ditebak: kolom yang diisi DEFAULT basis data
        // (status 'new' bila tidak disebut) belum ada pada instance hasil
        // create(), dan jawaban 201 yang berbunyi "status: null" untuk baris
        // yang sesungguhnya 'new' adalah kebohongan yang hidup sampai layarnya
        // dimuat ulang.
        return $this->created(LeadResource::make($lead->refresh()->load('owner')));
    }

    public function show(Lead $lead): JsonResponse
    {
        // Riwayat tahap ikut pada layar dokumen (dan hanya di sana): kartunya
        // adalah tempat alasan mundur dibaca orang.
        return $this->ok(LeadResource::make($lead->load(['owner', 'statusChanges.user:id,name'])));
    }

    public function update(LeadUpdateRequest $request, Lead $lead): JsonResponse
    {
        $lead->update($this->writable($request->validated()));

        return $this->ok(LeadResource::make($lead->load('owner')));
    }

    /**
     * Kolom yang boleh ditulis formulir prospek — IKAT PINGGANG DAN TALI
     * (verifikasi F-3, 8 Sep 2026).
     *
     * `next_follow_up_at` adalah TURUNAN (LeadFollowUpService, satu penulis)
     * dan `status` hanya berpindah lewat LeadPipelineService. Keduanya sudah
     * ditolak di FormRequest — tetapi aturan di sana pernah `prohibited`, yang
     * lulus untuk null: PUT {"next_follow_up_at":null} dijawab 200 dan
     * menghapus kolom turunannya, sehingga satu halaman memajang dua jawaban
     * berbeda untuk pertanyaan yang sama. Saringan kedua di sini berbiaya satu
     * baris dan berarti aturan itu tidak bisa dibatalkan oleh satu rule yang
     * salah pilih — bentuk yang sama dengan ActivityService (done_at/done_by).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function writable(array $data, bool $status = false): array
    {
        $data = Arr::except($data, $status ? ['next_follow_up_at'] : ['next_follow_up_at', 'status']);

        // `status` null pada PEMBUATAN berarti "pakai tahap awal", bukan "tulis
        // NULL": kolomnya NOT NULL berdefault 'new', dan meneruskan null apa
        // adanya adalah HTTP 500 atas permintaan yang sah.
        if ($status && array_key_exists('status', $data) && $data['status'] === null) {
            unset($data['status']);
        }

        return $data;
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();

        return $this->ok(null, 'Lead deleted.');
    }

    /**
     * "Jadikan pelanggan" — copy the lead's fields into a customer master row.
     *
     * NOT gated on status won here: a quotation requires customer_id, so a
     * qualified lead legitimately needs its customer BEFORE any quotation can
     * exist. The SPA surfaces the button on won leads (the resep's case); the
     * API allows the earlier conversion the quotation form forces anyway.
     */
    public function convertToCustomer(Lead $lead): JsonResponse
    {
        $alreadyLinked = $lead->customer_id !== null;
        $customer = $this->service->convertToCustomer($lead);

        return $alreadyLinked
            ? $this->ok(CustomerResource::make($customer), "Lead {$lead->code} sudah menjadi pelanggan {$customer->code}.")
            : $this->created(CustomerResource::make($customer), "Pelanggan {$customer->code} dibuat dari lead {$lead->code}.");
    }

    /**
     * Pindahkan tahap prospek — SATU pintu untuk layar dokumen, daftar, papan
     * kanban dan API (F-3 / T3.5).
     *
     * Maju bebas; mundur menuntut alasan (422 berkunci `reason`, yang dijawab
     * SPA dengan satu isian lalu dicoba lagi); Menang/Kalah ditolak dengan
     * kalimat yang menyebut penawaran mana yang harus ditandai.
     */
    public function movePipeline(LeadPipelineRequest $request, Lead $lead): JsonResponse
    {
        $to = LeadStatus::from($request->validated('status'));
        $lead = $this->pipeline->move($lead, $to, $request->validated('reason'), $request->user());

        return $this->ok(
            LeadResource::make($lead->load(['owner', 'statusChanges.user:id,name'])),
            "{$lead->code} dipindahkan ke tahap {$to->label()}.",
        );
    }
}
