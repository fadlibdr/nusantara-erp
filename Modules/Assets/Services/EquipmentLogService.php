<?php

namespace Modules\Assets\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;

/**
 * The BBM & hour-meter register: site fuel and meter readings captured on the
 * deployment, by the people already at the site (deviasi #13, owner decision
 * 22 Aug 2026).
 *
 * WHERE IS THE JOURNAL? THERE ISN'T ONE, ON PURPOSE. The fuel COST already
 * flows through petty cash under the BbmTol category — the drum was paid for
 * there, expensed there, and reconciled there. This register carries the
 * operational half only: hours run and litres consumed, the numbers
 * utilisation and own-vs-rent decisions are made from. Posting anything here
 * would count the same solar twice; recording stock would invent a fuel
 * warehouse nobody keeps. Forward-only, register-only.
 */
class EquipmentLogService
{
    /**
     * Record one reading. Throws LogicException with an operator-readable
     * sentence when the register would stop being honest.
     */
    public function record(Deployment $deployment, array $data, User $loggedBy): EquipmentLog
    {
        $logDate = Carbon::parse($data['log_date'] ?? now()->toDateString())->startOfDay();

        // A register records what has happened. A reading dated tomorrow is a
        // plan wearing a register's clothes, and the monotonic guard below
        // would then refuse tomorrow's REAL reading against it.
        if ($logDate->gt(Carbon::today())) {
            throw new LogicException(sprintf(
                'Tanggal log %s masih di masa depan — register mencatat pembacaan yang sudah terjadi, bukan rencana.',
                $logDate->toDateString(),
            ));
        }

        return DB::transaction(function () use ($deployment, $data, $loggedBy, $logDate): EquipmentLog {
            // Locked re-read, the same serialisation point DeploymentService
            // uses: a demobilisation racing this insert would otherwise be
            // checked against a stale span, and two readings racing each
            // other could both pass the monotonic check and land inverted.
            /** @var Deployment $deployment */
            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();

            // The deployment must have been ACTIVE on log_date: a reading on
            // a machine already demobilised — or not yet mobilised — is a
            // record about a machine that was not there. Late paperwork dated
            // WITHIN the span stays welcome (returnDeployment takes the same
            // stance on the storeman recording in July the machine that left
            // in June): on that date the machine WAS there.
            if ($logDate->lt($deployment->deployed_from)) {
                throw new LogicException(sprintf(
                    'Mobilisasi %s baru mulai %s; log bertanggal %s mencatat alat yang belum ada di lokasi.',
                    $deployment->code,
                    $deployment->deployed_from->toDateString(),
                    $logDate->toDateString(),
                ));
            }

            if ($deployment->returned_at !== null && $logDate->gt($deployment->returned_at)) {
                throw new LogicException(sprintf(
                    'Mobilisasi %s sudah demobilisasi %s; log bertanggal %s mencatat alat yang sudah tidak ada di lokasi.',
                    $deployment->code,
                    $deployment->returned_at->toDateString(),
                    $logDate->toDateString(),
                ));
            }

            if (array_key_exists('hour_meter', $data) && $data['hour_meter'] !== null) {
                $this->assertMeterRunsForward($deployment, $logDate, (float) $data['hour_meter']);
            }

            return $deployment->equipmentLogs()->create([
                'log_date' => $logDate->toDateString(),
                'hour_meter' => $data['hour_meter'] ?? null,
                'fuel_liters' => $data['fuel_liters'] ?? null,
                'notes' => $data['notes'] ?? null,
                'logged_by' => $loggedBy->id,
            ]);
        });
    }

    /**
     * Hour meters only run forward. A new reading below the latest earlier
     * one means a typo or the wrong machine, and silently accepting it
     * poisons every utilisation number derived from the trail later — so the
     * refusal quotes BOTH numbers, letting the operator see the slip without
     * opening the list. The same line read from the other side: a backfilled
     * reading ABOVE the next recorded one breaks the identical invariant, so
     * it is refused with the same two-number sentence.
     *
     * "Earlier" within one day is entry order (id): the afternoon top-up is
     * measured against the morning one, never the other way round.
     */
    private function assertMeterRunsForward(Deployment $deployment, Carbon $logDate, float $reading): void
    {
        $floor = $deployment->equipmentLogs()
            ->whereNotNull('hour_meter')
            ->whereDate('log_date', '<=', $logDate->toDateString())
            ->orderByDesc('log_date')->orderByDesc('id')
            ->first();

        if ($floor !== null && $reading < (float) $floor->hour_meter) {
            throw new LogicException(sprintf(
                'Hour meter %s lebih rendah dari pembacaan terakhir %s (%s) pada mobilisasi %s. '
                .'Meter hanya berjalan maju — angka yang lebih rendah berarti salah ketik atau salah alat.',
                $this->meter($reading),
                $this->meter((float) $floor->hour_meter),
                $floor->log_date->toDateString(),
                $deployment->code,
            ));
        }

        $ceiling = $deployment->equipmentLogs()
            ->whereNotNull('hour_meter')
            ->whereDate('log_date', '>', $logDate->toDateString())
            ->orderBy('log_date')->orderBy('id')
            ->first();

        if ($ceiling !== null && $reading > (float) $ceiling->hour_meter) {
            throw new LogicException(sprintf(
                'Hour meter %s bertanggal %s lebih tinggi dari pembacaan %s yang sudah tercatat pada %s '
                .'(mobilisasi %s). Meter hanya berjalan maju — log susulan harus lebih kecil dari pembacaan sesudahnya.',
                $this->meter($reading),
                $logDate->toDateString(),
                $this->meter((float) $ceiling->hour_meter),
                $ceiling->log_date->toDateString(),
                $deployment->code,
            ));
        }
    }

    /**
     * A reading, written the way the operator typed it: Indonesian separators
     * with trailing zeros trimmed (1.200,5 — never 1.200,500). Same trim as
     * FormPrintService's qty cast, so the refusal and the printed card spell
     * one number one way.
     */
    private function meter(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }
}
