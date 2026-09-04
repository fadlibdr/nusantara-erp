<?php

namespace Modules\Subcontract\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Services\BudgetGateService;
use Modules\Procurement\Services\VendorQualificationService;
use Modules\Subcontract\Http\Requests\AdvanceClaimRequest;
use Modules\Subcontract\Http\Requests\AdvancePayoutRequest;
use Modules\Subcontract\Http\Requests\RetentionReleaseRequest;
use Modules\Subcontract\Http\Requests\SubcontractStoreRequest;
use Modules\Subcontract\Http\Requests\SubcontractUpdateRequest;
use Modules\Subcontract\Http\Resources\ProgressClaimResource;
use Modules\Subcontract\Http\Resources\RetentionReleaseResource;
use Modules\Subcontract\Http\Resources\SubcontractResource;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Services\AddendumService;
use Modules\Subcontract\Services\AdvanceService;
use Modules\Subcontract\Services\RetentionService;
use Modules\Subcontract\Services\SubcontractService;

class SubcontractController extends ApiController
{
    public function __construct(
        private readonly SubcontractService $service,
        private readonly RetentionService $retention,
        private readonly AdvanceService $advances,
        private readonly AddendumService $addenda,
        private readonly VendorQualificationService $qualification,
        private readonly BudgetGateService $budget,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Subcontract::query()
            ->with('vendor')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, SubcontractResource::class,
            sortable: ['code', 'title', 'value', 'pph_rate', 'status'], dateColumn: 'start_date');
    }

    public function store(SubcontractStoreRequest $request): JsonResponse
    {
        try {
            $subcontract = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(SubcontractResource::make($subcontract));
    }

    public function show(Subcontract $subcontract): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(SubcontractResource::make(
            $subcontract->load('items', 'vendor', 'project', 'claims', 'retentionReleases', 'approvals.user')
        ));
    }

    public function update(SubcontractUpdateRequest $request, Subcontract $subcontract): JsonResponse
    {
        try {
            $subcontract = $this->service->update($subcontract, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(SubcontractResource::make($subcontract));
    }

    public function destroy(Subcontract $subcontract): JsonResponse
    {
        try {
            $this->service->delete($subcontract);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'SPK deleted.');
    }

    public function submit(Request $request, Subcontract $subcontract): JsonResponse
    {
        try {
            // Gate prakualifikasi (temuan #35), cermin sisi PO: berdiri pada
            // saat MENGAJUKAN, bukan hanya saat membuat draf — SBU bisa
            // kedaluwarsa (dan subkon bisa dinonaktifkan) di antara draf dan
            // pengajuan, dan update() bebas menukar vendor_id sebuah draf.
            // Tanpa cek ini, draf yang di-repoint ke subkon terblokir lolos
            // menjadi komitmen tanpa satu pun pemeriksaan.
            $vendor = $subcontract->vendor;

            if ($vendor === null) {
                return $this->error("Vendor SPK {$subcontract->code} sudah dihapus; pilih vendor lain sebelum mengajukan.");
            }

            $reason = trim((string) $request->input('qualification_override_reason', ''));
            $overridden = $this->qualification->assertQualified($vendor, $reason === '' ? null : $reason);

            $subcontract = DB::transaction(function () use ($subcontract, $request, $reason, $overridden): Subcontract {
                // Gate anggaran menilai nilai SPK terhadap sisa RAP, jadi
                // keputusannya diambil pada re-read terkunci di dalam
                // transaksi — instance route-binding bisa basi terhadap edit
                // paralel yang baru saja mengganti nilai (atau proyek) SPK.
                /** @var Subcontract $spk */
                $spk = Subcontract::query()->whereKey($subcontract->id)->lockForUpdate()->firstOrFail();

                // Gate anggaran (#33), cermin sisi PO: komitmen SPK + realisasi
                // subkon diadu dengan anggaran RAP kategori subkon SEBELUM
                // janji baru ditandatangani.
                $this->budget->assertSpkWithinBudget($spk, $request->boolean('confirm_over_budget'));

                // submit() DULU, alasan sesudahnya, satu transaksi: pengajuan
                // yang ditolak Approvable (mis. SPK sudah submitted) tidak
                // boleh meninggalkan jejak override palsu.
                $spk->submit($request->user());

                if ($overridden !== []) {
                    // Alasan hanya tercatat saat override benar-benar DIPAKAI.
                    $spk->forceFill(['qualification_override_reason' => $reason])->save();
                }

                return $spk;
            });
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        $message = $subcontract->needs_director_approval
            ? 'SPK submitted; requires director approval (above threshold).'
            : 'SPK submitted.';

        return $this->ok(SubcontractResource::make($subcontract), $message);
    }

    public function approve(Request $request, Subcontract $subcontract): JsonResponse
    {
        try {
            // Through the service, not the model: SubcontractService::approve
            // is where the needs_director_approval gate lives.
            $this->service->approve($subcontract, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(SubcontractResource::make($subcontract), 'SPK approved.');
    }

    public function reject(Request $request, Subcontract $subcontract): JsonResponse
    {
        try {
            $subcontract->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(SubcontractResource::make($subcontract), 'SPK rejected.');
    }

    /**
     * Pintu sempit temuan #75 (susulan): defect_liability_until pada SPK yang
     * sudah diajukan/disetujui. SubcontractService::update menolak SPK
     * non-editable — benar untuk nilai dan lingkup, tetapi tanggal masa
     * pemeliharaan justru baru diketahui (BAST I) SETELAH SPK disetujui, jadi
     * portofolio hidup tak pernah bisa patuh pada gate waktunya sendiri.
     *
     * Satu kolom itu saja yang bergerak, dan keputusannya dibaca dari baris
     * hidup di dalam transaksi — bukan dari instance route model binding yang
     * bisa keburu berubah status atau keburu melepas retensinya.
     */
    public function updateDefectLiability(Request $request, Subcontract $subcontract): JsonResponse
    {
        $data = $request->validate([
            'defect_liability_until' => ['required', 'date'],
        ]);

        try {
            $subcontract = DB::transaction(function () use ($subcontract, $data): Subcontract {
                /** @var Subcontract $live */
                $live = Subcontract::query()
                    ->whereKey($subcontract->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Draf/ditolak masih bisa lewat form edit biasa — pintu ini
                // hanya untuk status yang form biasa tolak. SPK batal tidak
                // punya retensi yang gate-nya perlu tanggal.
                if (! in_array($live->status, [DocumentStatus::Submitted, DocumentStatus::Approved], true)) {
                    throw new LogicException(
                        "SPK {$live->code} berstatus {$live->status->value}; tanggal masa pemeliharaan "
                        .'SPK yang masih dapat diedit diubah lewat form SPK biasa.'
                    );
                }

                // Retensi sudah pernah dilepas: gate waktunya sudah terpakai,
                // dan tanggal yang diganti sesudahnya memalsukan alasan kenapa
                // pelepasan itu (tidak) meminta override.
                if ($this->retention->balance($live)['released'] > 0.0) {
                    throw new LogicException(
                        "Retensi SPK {$live->code} sudah pernah dilepas; tanggal masa pemeliharaan "
                        .'tidak dapat diubah lagi setelahnya.'
                    );
                }

                $live->forceFill([
                    'defect_liability_until' => $data['defect_liability_until'],
                ])->save();

                return $live;
            });
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            SubcontractResource::make($subcontract),
            'Akhir masa pemeliharaan dicatat; gate pelepasan retensi memakai tanggal ini.',
        );
    }

    public function retention(Subcontract $subcontract): JsonResponse
    {
        $balance = $this->retention->balance($subcontract);

        return $this->ok([
            'subcontract_id' => $subcontract->id,
            'code' => $subcontract->code,
            'retention_pct' => $subcontract->retention_pct,
            // The time gate's date, so the release prompt can say up front
            // whether an override reason will be demanded.
            'defect_liability_until' => $subcontract->defect_liability_until?->toDateString(),
            'retained' => $balance['retained'],
            // What the general ledger carries in 2-1500 for this SPK, and how
            // much of the balance a release may actually debit out of it.
            'posted' => $balance['posted'],
            'released' => $balance['released'],
            'balance' => $balance['balance'],
            'releasable' => $balance['releasable'],
            'releases' => RetentionReleaseResource::collection(
                $subcontract->retentionReleases()->with('apBill')->get()
            ),
        ]);
    }

    public function retentionRelease(RetentionReleaseRequest $request, Subcontract $subcontract): JsonResponse
    {
        try {
            $release = $this->retention->release($subcontract, $request->validated(), $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(
            RetentionReleaseResource::make($release->load('apBill')),
            'Retensi dilepas; tagihan pembayaran diterbitkan.',
        );
    }

    /** Riwayat addendum + what the SPK is worth now (mirror of the CCO summary). */
    public function addendumSummary(Subcontract $subcontract): JsonResponse
    {
        return $this->ok($this->addenda->summaryFor($subcontract));
    }

    /** The DP's whole story for the SPK screen: claim, payout bill, recovery. */
    public function advance(Subcontract $subcontract): JsonResponse
    {
        return $this->ok($this->advances->panelFor($subcontract));
    }

    public function advanceClaim(AdvanceClaimRequest $request, Subcontract $subcontract): JsonResponse
    {
        try {
            $claim = $this->advances->createClaim($subcontract, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(
            ProgressClaimResource::make($claim),
            'Klaim uang muka dibuat; ajukan dan setujui seperti opname biasa.',
        );
    }

    public function advancePayout(AdvancePayoutRequest $request, Subcontract $subcontract): JsonResponse
    {
        try {
            $bill = $this->advances->payout($subcontract, $request->validated(), $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created([
            'ap_bill_id' => $bill->id,
            'ap_bill_code' => $bill->code,
            'total_payable' => $bill->total_payable,
        ], 'Uang muka dicairkan; tagihan pembayaran diterbitkan.');
    }
}
