<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'activity_type' => $this->activity_type,
            'body' => $this->body,
            'minutes_spent' => $this->minutes_spent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
