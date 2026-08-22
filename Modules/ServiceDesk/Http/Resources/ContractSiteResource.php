<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_contract_id' => $this->service_contract_id,
            'site_name' => $this->site_name,
            'address' => $this->address,
            'city' => $this->city,
            'pic_name' => $this->pic_name,
            'pic_phone' => $this->pic_phone,
        ];
    }
}
