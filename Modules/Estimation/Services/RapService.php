<?php

namespace Modules\Estimation\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Enums\CostCategory;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\CostBudget;

class RapService
{
    public function create(array $data): CostBudget
    {
        $boq = Boq::query()->findOrFail($data['boq_id']);

        return CostBudget::query()->create([
            'code' => $data['code'] ?? null, // null => HasDocumentNumber assigns RAP/{Y}/{N4}
            'boq_id' => $boq->id,
            'project_id' => $data['project_id'] ?? $boq->project_id,
            'target_margin_pct' => $data['target_margin_pct'],
            'status' => DocumentStatus::Draft,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Header fields of an existing RAP, and its lines when the caller carries them.
     *
     * The counterpart of BoqService::update: `items` is replaced WHOLESALE when
     * the key is present and left untouched when it is absent, so a caller that
     * only means to move the margin cannot silently empty the budget.
     *
     * boq_id is deliberately not fillable here. Re-parenting a RAP would leave
     * every est_cost_budget_items.boq_item_id pointing into a BOQ the budget no
     * longer belongs to, and prj_baselines resolves a project's RAP by
     * project_id — so a variance report would compare against a different
     * bill of quantities without one screen saying so.
     */
    public function update(CostBudget $budget, array $data): CostBudget
    {
        $this->assertEditable($budget, 'edited');

        return DB::transaction(function () use ($budget, $data): CostBudget {
            $budget->fill([
                // A blank proyek_kode means "the BOQ's project", never "no
                // project": BaselineService finds a project's RAP with
                // where('project_id', …), so nulling it detaches the budget from
                // every baseline and EVM report that would have found it, while
                // the RAP itself still looks complete on its own screen.
                'project_id' => $data['project_id'] ?? $budget->boq()->value('project_id'),
                'target_margin_pct' => $data['target_margin_pct'] ?? $budget->target_margin_pct,
                'notes' => $data['notes'] ?? $budget->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($budget, $data['items'] ?? []);
            }

            return $budget->refresh();
        });
    }

    /**
     * Replace every line of a manually-costed RAP.
     *
     * The manual counterpart of generateFromBoq, and it carries the SAME
     * editability guard for a reason that is not symmetry: the RAP's other
     * editability check lives in CostBudgetController::update, not in this
     * service, so a service-level write path without a guard of its own would
     * make any caller — the document importer above all — the supported way to
     * rewrite an approved RAP and move the budget a project is measured against.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function replaceItems(CostBudget $budget, array $lines): CostBudget
    {
        $this->assertEditable($budget, 'rewritten');

        return DB::transaction(function () use ($budget, $lines): CostBudget {
            $budget->items()->delete();

            foreach ($lines as $line) {
                $qty = round((float) ($line['qty'] ?? 0), 3);
                $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

                $budget->items()->create([
                    'boq_item_id' => $line['boq_item_id'],
                    'cost_category' => $line['cost_category'],
                    'description' => $line['description'],
                    'qty' => $qty,
                    'unit' => $line['unit'],
                    'unit_price' => $unitPrice,
                    // amount is authoritative — recalcTotals and EvmService's
                    // cost coverage both sum this column, never qty x price.
                    'amount' => round($qty * $unitPrice, 2),
                ]);
            }

            return $this->recalcTotals($budget);
        });
    }

    /**
     * Reasons, in Indonesian, why replacing this RAP's lines would move a number
     * somebody else already froze.
     *
     * prj_baselines stores bac + cost_budget_id, but EvmService::costCoverage
     * reads est_cost_budget_items LIVE by that id to get the per-category
     * budget. An approved baseline is frozen by ProjectBaseline::isFrozen(), and
     * yet its RAP may still be a draft — BacSource::RapUnapproved exists exactly
     * for that case — so rewriting the draft silently changes what a frozen
     * baseline's CPI coverage is measured against, and the baseline goes on
     * reporting a BAC that no longer matches its own lines.
     *
     * @return array<int, string>
     */
    public function dependencyBlockers(CostBudget $budget): array
    {
        $frozen = DB::table('prj_baselines')
            ->where('cost_budget_id', $budget->id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->pluck('code')
            ->all();

        if ($frozen === []) {
            return [];
        }

        return [sprintf(
            'baseline %s sudah dibekukan terhadap RAP ini; mengganti rinciannya akan mengubah acuan biaya laporan EVM.'
            .' Buat baseline revisi baru lalu impor ke RAP-nya.',
            implode(', ', $frozen),
        )];
    }

    /**
     * (Re)build the RAP lines from the linked BOQ.
     *
     * Per BOQ item the internal budget is the selling amount deflated by the
     * target margin:  budget = amount / (1 + margin / 100).
     * When the item has an AHSP analysis, the budget is split across cost
     * categories proportionally to the component mix (labor / material /
     * equipment) plus the AHSP overhead share; otherwise it becomes a single
     * lump-sum line (assumed subcontracted scope).
     */
    public function generateFromBoq(CostBudget $budget, ?float $marginPct = null): CostBudget
    {
        $this->assertEditable($budget, 'regenerated');

        $margin = $marginPct ?? (float) $budget->target_margin_pct;

        if ($margin <= -100) {
            throw new LogicException('Target margin must be greater than -100%.');
        }

        return DB::transaction(function () use ($budget, $margin): CostBudget {
            $budget->forceFill(['target_margin_pct' => $margin])->save();
            $budget->items()->delete();

            $boq = $budget->boq()->with(['items.ahsp.components'])->firstOrFail();

            foreach ($boq->items as $item) {
                $target = round((float) $item->amount / (1 + $margin / 100), 2);

                foreach ($this->splitBudget($item, $target) as $line) {
                    $budget->items()->create($line + ['boq_item_id' => $item->id]);
                }
            }

            return $this->recalcTotals($budget);
        });
    }

    public function recalcTotals(CostBudget $budget): CostBudget
    {
        $total = (float) $budget->items()->sum('amount');

        $budget->forceFill(['total_budget' => round($total, 2)])->save();

        return $budget;
    }

    /**
     * One guard for every path that writes a RAP's lines.
     *
     * $action only names the attempt; the sentence is otherwise identical
     * whichever door was tried, so an approved RAP refuses the same way through
     * the importer as it does through the generator.
     */
    private function assertEditable(CostBudget $budget, string $action): void
    {
        if (! $budget->status->isEditable()) {
            throw new LogicException("RAP {$budget->code} cannot be {$action} while status is {$budget->status->value}.");
        }
    }

    // ------------------------------------------------------------ revisi RAP

    /**
     * RAP yang MENGATUR sebuah proyek hari ini: disetujui, revisi terbaru yang
     * belum digantikan.
     *
     * SATU KALIMAT ATURAN, DAN INILAH KALIMATNYA. Gerbang anggaran PO/SPK,
     * layar portofolio, layar anggaran bulanan dan registri ambang membaca RAP
     * yang sama — dan sebelum F-2 kalimatnya adalah "disetujui, id terbesar".
     * Klausa superseded_at MENYEMPITKAN tanpa mengubah jawabannya pada data
     * yang belum pernah direvisi (sebuah revisi selalu ber-id lebih besar
     * daripada yang digantikannya), dan itu dibuktikan
     * RapRevisionTest::test_an_approved_rap_without_revisions_answers_exactly_as_before.
     */
    public function governing(int $projectId): ?CostBudget
    {
        return CostBudget::query()
            ->where('project_id', $projectId)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Buat revisi DRAF dari sebuah RAP yang sudah disetujui.
     *
     * Menyalin rinciannya apa adanya supaya yang mengerjakan revisi mengubah
     * baris yang berbeda saja, bukan mengetik ulang seluruh anggaran. Alasan
     * WAJIB, alasan yang sama dengan BaselineService::snapshot: enam bulan
     * kemudian tidak ada yang bisa mengatakan apakah anggaran naik karena CCO,
     * karena eskalasi harga, atau karena angkanya tidak nyaman.
     *
     * Yang direvisi harus DISETUJUI. RAP draf tidak perlu revisi — ia diedit;
     * membuat revisi dari draf akan melahirkan dua draf yang sama-sama belum
     * pernah mengatur apa pun.
     */
    public function revise(CostBudget $budget, array $data, ?User $by = null): CostBudget
    {
        if ($budget->status !== DocumentStatus::Approved) {
            throw new LogicException(
                "RAP {$budget->code} berstatus {$budget->status->value}, jadi tidak perlu direvisi — "
                .'yang belum disetujui cukup diubah langsung. Revisi hanya untuk RAP yang sudah berlaku.'
            );
        }

        if ($budget->superseded_at !== null) {
            throw new LogicException(
                "RAP {$budget->code} sudah digantikan revisi berikutnya, jadi bukan lagi anggaran yang "
                .'berlaku. Buat revisi dari RAP yang sedang mengatur proyek ini.'
            );
        }

        $this->assertNoLiveRevision($budget);

        $reason = trim((string) ($data['revision_reason'] ?? ''));

        if ($reason === '') {
            throw new LogicException(
                "Revisi RAP {$budget->code} wajib menyebutkan alasan (mis. CCO, addendum, eskalasi harga "
                .'yang disetujui). Anggaran yang berubah tanpa sebab tertulis adalah anggaran yang tidak '
                .'bisa dipertanggungjawabkan enam bulan kemudian.'
            );
        }

        return DB::transaction(function () use ($budget, $data, $reason): CostBudget {
            /** @var CostBudget $revision */
            $revision = CostBudget::query()->create([
                'boq_id' => $budget->boq_id,
                'project_id' => $budget->project_id,
                'target_margin_pct' => $data['target_margin_pct'] ?? $budget->target_margin_pct,
                'status' => DocumentStatus::Draft,
                'notes' => $data['notes'] ?? $budget->notes,
                'revision' => (int) $budget->revision + 1,
                'revised_from_id' => $budget->id,
                'revision_reason' => $reason,
            ]);

            foreach ($budget->items()->get() as $item) {
                $revision->items()->create([
                    'boq_item_id' => $item->boq_item_id,
                    'cost_category' => $item->cost_category,
                    'description' => $item->description,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                ]);
            }

            return $this->recalcTotals($revision)->refresh();
        });
    }

    /**
     * Setujui sebuah RAP — dan, bila ia sebuah revisi, GANTIKAN pendahulunya
     * dalam transaksi yang sama.
     *
     * Pintu satu-satunya. Sebelum F-2 controller memanggil trait langsung; kalau
     * itu dibiarkan, sebuah revisi yang disetujui lewat pintu itu akan berdiri
     * berdampingan dengan pendahulunya dan "RAP yang mengatur" punya dua
     * jawaban. Yang ditulis pada pendahulunya HANYA superseded_at dan
     * superseded_by_id — isinya tidak disentuh, jadi revisi 0 tetap terbaca
     * utuh berapa pun revisi yang menyusul (aturan append-only yang sama
     * dengan BaselineService).
     *
     * DAN MENSTEMPEL PENDAHULUNYA SAJA TIDAK CUKUP (verifikasi F-2). Menggantikan
     * pendahulu HANYA menutup satu jalan menuju dua jawaban; jalan kedua adalah
     * RAP disetujui LAIN yang bukan pendahulunya — dua revisi paralel dari satu
     * RAP, atau sebuah RAP kedua dari BOQ lain. Terukur pada versi sebelum
     * perbaikan ini: dua revisi dari RAP/2026/0001 sama-sama disetujui, keduanya
     * approved dengan superseded_at NULL, layar riwayat mencetak Rp 40,4 miliar
     * sementara gerbang PO/SPK menolak terhadap Rp 30,3 miliar milik id
     * terbesar — Rp 10,1 miliar berselisih pada satu proyek. Maka persetujuan
     * memeriksa SELURUH sisa: sesudah transaksi ini boleh ada tepat SATU RAP
     * disetujui yang belum digantikan untuk proyek ini.
     */
    public function approve(CostBudget $budget, User $by, ?string $note = null): CostBudget
    {
        return DB::transaction(function () use ($budget, $by, $note): CostBudget {
            $predecessor = $budget->revised_from_id === null
                ? null
                : CostBudget::query()->find($budget->revised_from_id);

            $this->assertNoGoverningRival($budget, $predecessor);

            $budget->approve($by, $note);

            if ($predecessor !== null && $predecessor->superseded_at === null) {
                $predecessor->forceFill([
                    'superseded_at' => now(),
                    'superseded_by_id' => $budget->id,
                ])->save();
            }

            return $budget;
        });
    }

    public function reject(CostBudget $budget, User $by, ?string $note = null): CostBudget
    {
        // Tanpa penggantian apa pun: sebuah revisi yang ditolak tidak pernah
        // mengatur apa pun, jadi pendahulunya tetap berlaku tanpa disentuh.
        return $budget->reject($by, $note);
    }

    /**
     * Sesudah persetujuan ini, proyek harus punya TEPAT SATU RAP yang mengatur.
     *
     * Yang dicari: RAP disetujui yang belum digantikan, bukan RAP ini dan bukan
     * pendahulu yang akan distempel beberapa baris di bawah. Kalau ada, dua
     * anggaran akan berdiri berdampingan dan governing() harus memilih salah
     * satunya diam-diam (id terbesar) — sementara layar riwayat revisi mencetak
     * yang lain.
     *
     * TIDAK ADA INDEKS UNIK yang menjaga ini di basis data, berbeda dengan
     * fin_overhead_budgets (satu OVB disetujui per tahun, migrasi 001500), dan
     * itu keputusan yang diukur: data SEBELUM F-2 sah memuat dua RAP disetujui
     * pada satu proyek — RapRevisionTest::test_an_approved_rap_without_revisions_answers_exactly_as_before
     * memakukan bahwa proyek seperti itu tetap dijawab persis seperti dulu —
     * jadi sebuah indeks unik parsial akan MENOLAK BERMIGRASI justru di
     * pemasangan yang paling membutuhkan perbaikan ini. Yang dijaga pintu ini
     * adalah baris baru; yang lama tetap terbaca, dengan aturan yang sama.
     */
    private function assertNoGoverningRival(CostBudget $budget, ?CostBudget $predecessor): void
    {
        if ($budget->project_id === null) {
            return;
        }

        $rival = CostBudget::query()
            ->where('project_id', $budget->project_id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->where('id', '!=', $budget->id)
            ->when($predecessor !== null, fn ($query) => $query->where('id', '!=', $predecessor->id))
            ->orderByDesc('id')
            ->first();

        if ($rival === null) {
            return;
        }

        throw new LogicException(sprintf(
            'Proyek ini sudah punya RAP yang berlaku (%s), jadi RAP %s tidak dapat disetujui. Dua RAP '
            .'disetujui yang belum digantikan membuat "RAP yang mengatur" punya dua jawaban: layar '
            .'anggaran akan mencetak yang satu sementara gerbang PO/SPK menolak dokumen dengan yang '
            .'lain. Buat RAP ini sebagai REVISI dari %s (revisi menggantikan pendahulunya pada detik '
            .'ia disetujui), atau tolak salah satunya lebih dulu.',
            $rival->code,
            $budget->code,
            $rival->code,
        ));
    }

    /**
     * Satu RAP hanya boleh punya satu revisi yang masih hidup.
     *
     * Dua revisi dari pendahulu yang sama menerima NOMOR REVISI yang sama
     * (keduanya revisi + 1) dan bercabang: rantainya berhenti bisa dibaca
     * sebagai satu garis, dan bila keduanya sampai ke meja penyetuju yang kedua
     * ditolak assertNoGoverningRival — sesudah seseorang mengerjakan seluruh
     * anggarannya. Ditolak di sini, sebelum pekerjaan itu dimulai.
     */
    private function assertNoLiveRevision(CostBudget $budget): void
    {
        $live = CostBudget::query()
            ->where('revised_from_id', $budget->id)
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Submitted->value])
            ->orderBy('id')
            ->first();

        if ($live === null) {
            return;
        }

        throw new LogicException(sprintf(
            'RAP %s sudah punya revisi yang belum selesai (%s, status %s). Selesaikan revisi itu — '
            .'disetujui atau ditolak — sebelum membuat revisi berikutnya, supaya nomor revisi dan '
            .'rantai riwayatnya tetap satu garis.',
            $budget->code,
            $live->code,
            $live->status->value,
        ));
    }

    /**
     * Rantai revisi sebuah RAP, dari revisi 0 ke depan, dengan SELISIH tiap
     * revisi terhadap pendahulunya.
     *
     * Dibelah subkon / non-subkon karena itulah garis yang dibaca gerbang PO
     * dan SPK: sebuah revisi yang menaikkan total tetapi memindahkan Rp 300
     * juta dari material ke subkon mengubah dua gerbang ke arah yang berlawanan,
     * dan sebuah tabel yang hanya menampilkan total menyembunyikannya.
     *
     * @return array<int, array<string, mixed>>
     */
    public function revisionChain(CostBudget $budget): array
    {
        $chain = $this->chainOf($budget);
        $rows = [];
        $previous = null;

        foreach ($chain as $entry) {
            $totals = $this->totalsOf($entry);

            $rows[] = [
                'id' => $entry->id,
                'code' => $entry->code,
                'revision' => (int) $entry->revision,
                'status' => $entry->status->value,
                'is_governing' => $entry->status === DocumentStatus::Approved && $entry->superseded_at === null,
                'subcon' => $totals['subcon'],
                'non_subcon' => $totals['non_subcon'],
                'total' => $totals['total'],
                // Revisi 0 tidak punya pendahulu, jadi selisihnya BUKAN 0 —
                // tidak ada yang bisa dikurangkan. null, dan layar menggarisnya.
                'delta_total' => $previous === null ? null : round($totals['total'] - $previous['total'], 2),
                'delta_subcon' => $previous === null ? null : round($totals['subcon'] - $previous['subcon'], 2),
                'delta_non_subcon' => $previous === null ? null : round($totals['non_subcon'] - $previous['non_subcon'], 2),
                'revision_reason' => $entry->revision_reason,
                'superseded_at' => $entry->superseded_at?->toDateTimeString(),
                'superseded_by_id' => $entry->superseded_by_id,
            ];

            $previous = $totals;
        }

        return $rows;
    }

    /**
     * Selisih per KATEGORI BIAYA antara tiap revisi dan pendahulunya —
     * "riwayat selisih" yang diminta kontrak paket, pada granularitas tempat
     * uangnya benar-benar berpindah.
     *
     * @return array<int, array<string, mixed>>
     */
    public function revisionDiff(CostBudget $budget): array
    {
        $chain = $this->chainOf($budget);
        $rows = [];
        $previous = null;
        $previousCode = null;

        foreach ($chain as $entry) {
            $current = $this->byCategory($entry);

            if ($previous !== null) {
                foreach (array_unique(array_merge(array_keys($previous), array_keys($current))) as $category) {
                    $from = $previous[$category] ?? 0.0;
                    $to = $current[$category] ?? 0.0;

                    if (round($to - $from, 2) === 0.0) {
                        continue;
                    }

                    $rows[] = [
                        'revision' => (int) $entry->revision,
                        'from_code' => $previousCode,
                        'to_code' => $entry->code,
                        'cost_category' => $category,
                        'label' => CostCategory::tryFrom($category)?->label() ?? $category,
                        'amount_from' => $from,
                        'amount_to' => $to,
                        'delta' => round($to - $from, 2),
                    ];
                }
            }

            $previous = $current;
            $previousCode = $entry->code;
        }

        return $rows;
    }

    /**
     * Seluruh rantai yang memuat RAP ini, dari revisi 0 ke depan.
     *
     * Ditelusuri lewat revised_from_id ke belakang lalu superseded_by_id/
     * revised_from_id ke depan, bukan lewat "semua RAP proyek ini": sebuah
     * proyek boleh punya dua RAP yang tidak berhubungan (dua BOQ), dan
     * menampilkan keduanya sebagai satu rantai revisi akan mengarang selisih
     * antara dua anggaran yang tidak pernah menggantikan satu sama lain.
     *
     * KE BELAKANG DULU, DARI RAP YANG DIMINTA — bukan "dari akar lalu anak
     * pertama". Setiap RAP punya TEPAT SATU pendahulu, jadi jalan mundur tidak
     * pernah bercabang; jalan maju bisa (data sebelum verifikasi F-2 memuat dua
     * revisi dari satu pendahulu, dan kini assertNoLiveRevision menutupnya untuk
     * baris baru). Versi sebelumnya berjalan maju dari akar lewat anak ber-id
     * terkecil, jadi pada data bercabang RAP yang dibuka orangnya TIDAK ADA di
     * riwayatnya sendiri — terukur: riwayat RAP/2026/0004 mencetak 0001, 0002,
     * 0003 dan menandai 0003 "mengatur", padahal yang mengatur adalah 0004.
     *
     * @return array<int, CostBudget>
     */
    private function chainOf(CostBudget $budget): array
    {
        $chain = [$budget];
        $cursor = $budget;
        $guard = 0;

        while ($cursor->revised_from_id !== null && $guard++ < 100) {
            $parent = CostBudget::query()->find($cursor->revised_from_id);

            if ($parent === null) {
                break;
            }

            array_unshift($chain, $parent);
            $cursor = $parent;
        }

        $cursor = $budget;
        $guard = 0;

        while ($guard++ < 100) {
            // Cabang yang dipilih ke depan: yang MENGGANTIKAN kursor bila ada
            // (satu-satunya yang benar-benar mengatur sesudahnya), selain itu
            // anak ber-id terkecil.
            $next = $cursor->superseded_by_id !== null
                ? CostBudget::query()->find($cursor->superseded_by_id)
                : CostBudget::query()->where('revised_from_id', $cursor->id)->orderBy('id')->first();

            if ($next === null) {
                break;
            }

            $chain[] = $next;
            $cursor = $next;
        }

        return $chain;
    }

    /**
     * @return array{subcon: float, non_subcon: float, total: float}
     */
    private function totalsOf(CostBudget $budget): array
    {
        $subcon = round((float) $budget->items()->where('cost_category', CostCategory::Subcon->value)->sum('amount'), 2);
        $total = round((float) $budget->items()->sum('amount'), 2);

        return ['subcon' => $subcon, 'non_subcon' => round($total - $subcon, 2), 'total' => $total];
    }

    /**
     * @return array<string, float>
     */
    private function byCategory(CostBudget $budget): array
    {
        $totals = [];

        foreach ($budget->items()->selectRaw('cost_category, SUM(amount) as total')->groupBy('cost_category')->get() as $row) {
            $key = $row->cost_category instanceof CostCategory ? $row->cost_category->value : (string) $row->cost_category;
            $totals[$key] = round((float) $row->total, 2);
        }

        return $totals;
    }

    /**
     * Split one BOQ item's budget into RAP lines per cost category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function splitBudget(BoqItem $item, float $target): array
    {
        $ahsp = $item->ahsp;
        $qty = (float) $item->qty;

        if ($ahsp === null || $ahsp->components->isEmpty()) {
            return [$this->line(CostCategory::Subcon, $item, $qty, $target)];
        }

        // Base cost per component type from the AHSP analysis.
        $bases = [];
        foreach ($ahsp->components as $component) {
            $category = $component->component_type->costCategory()->value;
            $bases[$category] = ($bases[$category] ?? 0.0)
                + (float) $component->coefficient * (float) $component->unit_price;
        }
        $bases = array_filter($bases, fn (float $base): bool => $base > 0);

        $baseTotal = array_sum($bases);
        if ($baseTotal <= 0) {
            return [$this->line(CostCategory::Subcon, $item, $qty, $target)];
        }

        $overheadBase = $baseTotal * (float) $ahsp->overhead_pct / 100;
        $grand = $baseTotal + $overheadBase;

        $lines = [];
        $allocated = 0.0;
        $largestKey = null;

        foreach ($bases as $category => $base) {
            $amount = round($target * $base / $grand, 2);
            $allocated += $amount;
            $lines[$category] = $this->line(CostCategory::from($category), $item, $qty, $amount);

            if ($largestKey === null || $base > $bases[$largestKey]) {
                $largestKey = $category;
            }
        }

        // Whatever is not allocated to direct categories is the overhead share
        // plus rounding remainders — so category lines always sum to the target.
        $remainder = round($target - $allocated, 2);

        if ($overheadBase > 0) {
            $lines[CostCategory::Overhead->value] = $this->line(CostCategory::Overhead, $item, $qty, $remainder);
        } elseif ($remainder != 0.0) {
            $adjusted = round((float) $lines[$largestKey]['amount'] + $remainder, 2);
            $lines[$largestKey]['amount'] = $adjusted;
            $lines[$largestKey]['unit_price'] = $qty > 0 ? round($adjusted / $qty, 2) : $adjusted;
        }

        return array_values($lines);
    }

    private function line(CostCategory $category, BoqItem $item, float $qty, float $amount): array
    {
        return [
            'cost_category' => $category->value,
            'description' => $item->description.' ('.$category->label().')',
            'qty' => $qty,
            'unit' => $item->unit,
            // amount is authoritative; unit_price is informative (amount / qty).
            'unit_price' => $qty > 0 ? round($amount / $qty, 2) : $amount,
            'amount' => $amount,
        ];
    }
}
