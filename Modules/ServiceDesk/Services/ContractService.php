<?php

namespace Modules\ServiceDesk\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\ServiceDesk\Models\PreventiveSchedule;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Models\Ticket;

class ContractService
{
    private const SITE_FIELDS = ['site_name', 'address', 'city', 'pic_name', 'pic_phone'];

    public function create(array $data): ServiceContract
    {
        $sites = Arr::pull($data, 'sites', []);

        return DB::transaction(function () use ($data, $sites): ServiceContract {
            $contract = new ServiceContract(Arr::except($data, ['code']));
            $contract->save(); // HasDocumentNumber fills the SVC code

            foreach ($sites as $site) {
                $contract->sites()->create(Arr::only($site, self::SITE_FIELDS));
            }

            return $contract->load('sites');
        });
    }

    public function update(ServiceContract $contract, array $data): ServiceContract
    {
        return DB::transaction(function () use ($contract, $data): ServiceContract {
            $sites = Arr::pull($data, 'sites');

            $contract->fill(Arr::except($data, ['code']));
            $contract->save();

            if (is_array($sites)) {
                $this->syncSites($contract, $sites);
            }

            return $contract->load('sites');
        });
    }

    public function delete(ServiceContract $contract): void
    {
        if ($contract->tickets()->exists()) {
            throw new LogicException("Service contract {$contract->code} has tickets and cannot be deleted.");
        }

        DB::transaction(function () use ($contract): void {
            $contract->preventiveSchedules()->delete(); // soft delete
            $contract->delete();
        });
    }

    /**
     * Sites are matched by id when provided (update in place), created when new,
     * and removed when dropped from the payload — unless tickets or PM schedules
     * still point at them.
     */
    private function syncSites(ServiceContract $contract, array $sites): void
    {
        $keptIds = [];

        foreach ($sites as $siteData) {
            $existing = isset($siteData['id'])
                ? $contract->sites()->whereKey($siteData['id'])->first()
                : null;

            if ($existing) {
                $existing->update(Arr::only($siteData, self::SITE_FIELDS));
                $keptIds[] = $existing->id;
            } else {
                $keptIds[] = $contract->sites()->create(Arr::only($siteData, self::SITE_FIELDS))->id;
            }
        }

        $removable = $contract->sites()->whereNotIn('id', $keptIds)->get();

        foreach ($removable as $site) {
            $inUse = Ticket::query()->withTrashed()->where('site_id', $site->id)->exists()
                || PreventiveSchedule::query()->withTrashed()->where('site_id', $site->id)->exists();

            if ($inUse) {
                throw new LogicException(
                    "Site \"{$site->site_name}\" is still referenced by tickets or PM schedules and cannot be removed."
                );
            }

            $site->delete();
        }
    }
}
