<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Models\Project;

/**
 * P0-C: Izin Masuk/Keluar Material & Peralatan — header + baris barang, dan
 * aksi 'periksa' yang HANYA sah SETELAH approve.
 *
 * Urutannya adalah kontrolnya: manajemen menyetujui apa yang boleh lewat
 * (Approvable, prj.approve), lalu satpam di gerbang memeriksa muatan terhadap
 * izin yang SUDAH disetujui dan mengecapnya sekali — checked_by (user yang
 * menekan periksa) dan checked_at. Pemeriksaan sebelum persetujuan berarti
 * gerbang menjadi penyetuju bayangan; pemeriksaan kedua berarti cap bukti
 * boleh ditimpa. Dua-duanya ditolak dengan kalimat yang menyebut alasannya.
 */
class GatePassService
{
    public function create(array $data): GatePass
    {
        Project::query()->findOrFail((int) $data['project_id'])
            ->assertOperational('izin masuk/keluar material');

        $items = $this->pullItems($data) ?? [];

        return DB::transaction(function () use ($data, $items): GatePass {
            // Explicit draft: the column default is not hydrated on create.
            $pass = GatePass::query()->create(
                Arr::except($data, ['code', 'status']) + ['status' => DocumentStatus::Draft],
            );
            $this->replaceItems($pass, $items);

            return $pass->load('items');
        });
    }

    public function update(GatePass $pass, array $data): GatePass
    {
        $pass->project()->firstOrFail()->assertOperational('izin masuk/keluar material');

        $items = $this->pullItems($data);

        return DB::transaction(function () use ($pass, $data, $items): GatePass {
            $pass->fill(Arr::except($data, ['code', 'project_id', 'status', 'checked_by', 'checked_at']))->save();

            if ($items !== null) {
                $this->replaceItems($pass, $items);
            }

            return $pass->load('items');
        });
    }

    /**
     * The gate's own act — after management's, never instead of it.
     */
    public function periksa(GatePass $pass, User $by): GatePass
    {
        return DB::transaction(function () use ($pass, $by): GatePass {
            /** @var GatePass $locked */
            $locked = GatePass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DocumentStatus::Approved) {
                throw new LogicException(sprintf(
                    'Izin %s belum disetujui (status: %s) — pemeriksaan gerbang hanya untuk izin yang sudah disetujui manajemen.',
                    $locked->code,
                    $locked->status->label(),
                ));
            }

            if ($locked->checked_at !== null) {
                throw new LogicException(sprintf(
                    'Izin %s sudah diperiksa oleh %s pada %s — cap gerbang adalah bukti satu kejadian dan tidak ditimpa.',
                    $locked->code,
                    $locked->checkedBy?->name ?? '—',
                    $locked->checked_at->format('d-m-Y H:i'),
                ));
            }

            $locked->forceFill([
                'checked_by' => $by->id,
                'checked_at' => now(),
            ])->save();

            return $locked->load('items');
        });
    }

    // ---------------------------------------------------------------- lines

    /** @return list<array<string, mixed>>|null null = key absent, keep stored rows */
    private function pullItems(array &$data): ?array
    {
        if (! array_key_exists('items', $data)) {
            return null;
        }

        $value = Arr::pull($data, 'items');

        return is_array($value) ? array_values($value) : [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceItems(GatePass $pass, array $rows): void
    {
        $pass->items()->delete();

        foreach ($rows as $row) {
            $pass->items()->create([
                'item_id' => $row['item_id'] ?? null,
                'description' => $row['description'],
                'qty' => round((float) $row['qty'], 3),
                'unit' => $row['unit'],
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }
}
