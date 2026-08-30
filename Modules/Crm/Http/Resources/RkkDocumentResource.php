<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Crm\Services\RkkService;

class RkkDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(RkkService::class);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'tender_package_id' => $this->tender_package_id,
            'tender_package' => $this->whenLoaded('tenderPackage', fn () => [
                'id' => $this->tenderPackage->id,
                'code' => $this->tenderPackage->code,
                'title' => $this->tenderPackage->title,
            ]),
            'project_id' => $this->project_id,
            'boq_id' => $this->boq_id,
            'title' => $this->title,
            'policy' => $this->policy,
            'program' => $this->program,
            // Baris IBPRP dibaca LIVE dari register (Projects), dan baris biaya
            // SMKK membawa rupiah TURUNAN dari baris RAB-nya — tidak ada salinan
            // yang bisa membeku, di sini maupun di tabelnya.
            'ibprp_rows' => $this->when(
                $request->boolean('with_rows', true),
                fn (): array => $service->ibprpRows($this->resource),
            ),
            'smkk_rows' => $this->when(
                $request->boolean('with_rows', true),
                fn (): array => $service->smkkRows($this->resource),
            ),
            'smkk_total' => $service->smkkTotal($this->resource),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
