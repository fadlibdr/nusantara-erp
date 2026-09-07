<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'contract_id' => $this->contract_id,
            'contract_code' => $this->whenLoaded('contract', fn () => $this->contract?->code),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            // Konsultan MK / pengawas — the party the printed house forms need
            // a box for and the ERP had nowhere to keep.
            'consultant_name' => $this->consultant_name,
            'consultant_role' => $this->consultant_role,
            'boq_id' => $this->boq_id,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'location' => $this->location,
            'city' => $this->city,
            'province' => $this->province,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'actual_start_date' => $this->actual_start_date?->toDateString(),
            'actual_end_date' => $this->actual_end_date?->toDateString(),
            'contract_value' => $this->contract_value,
            'retention_pct' => $this->retention_pct,
            'retention_amount' => $this->retentionAmount(),
            'warranty_months' => (int) $this->warranty_months,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closed_by,
            'closure_override_reason' => $this->closure_override_reason,
            'project_manager_id' => $this->project_manager_id,
            'site_manager_id' => $this->site_manager_id,
            'planned_progress_pct' => $this->planned_progress_pct,
            'actual_progress_pct' => $this->actual_progress_pct,
            'deviation_pct' => $this->progressDeviation(),
            // `wbs_tasks` SENGAJA TIDAK ADA DI SINI. Pohon WBS punya satu pintu,
            // `GET projects/{project}/wbs-tasks`, dan pintu kedua yang dulu ada
            // di muatan ini adalah pintu yang lebih buruk dalam dua hal
            // sekaligus (diukur 7 Sep 2026 pada proyek berpohon empat tingkat,
            // 13 baris tersimpan):
            //
            //   * ia MEMENDEKKAN jadwalnya — `rootWbsTasks.children` mengirim
            //     11 dari 13 baris; B.3.1 dan B.3.1.1 hilang sepenuhnya,
            //     kegagalan yang sama yang df0bd21 tutup di pintu sebelah, dan
            //     setiap simpul tingkat satu dikirim TANPA kunci `children`
            //     (termasuk B.3 yang justru punya anak), jadi "tidak punya
            //     anak" dan "anaknya tidak dimuat" terlihat sama di klien;
            //   * ia MEMBOCORKANNYA — rutenya (`GET projects/{project}`)
            //     berjalan di bawah `auth:sanctum` saja, jadi peran tanpa satu
            //     pun izin `prj.*` (diukur: finance, teknisi, hr, procurement)
            //     mendapat 403 dari `{project}/wbs-tasks` sejak 4df5959 dan
            //     tetap 200 di sini — lengkap dengan kode, uraian, bobot,
            //     tanggal rencana dan progres setiap paket pekerjaan, yaitu
            //     persis daftar medan yang gerbang itu dipasang untuk menutupi.
            //
            // Tidak ada satu pun pembaca di SPA (grep `wbs_tasks` atas
            // public/app/js: 0 hasil) dan tidak ada satu pun uji yang
            // memakainya; layar Ringkasan memuat pohonnya dari endpoint pohon.
            'milestones' => MilestoneResource::collection($this->whenLoaded('milestones')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
