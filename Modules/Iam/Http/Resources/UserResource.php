<?php

namespace Modules\Iam\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'employee_id' => $this->employee_id,
            'is_active' => (bool) $this->is_active,
            'roles' => $this->roles->pluck('name')->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values(),
            // null = has never decided; the SPA opens the onboarding guide at
            // login on exactly that value (5 Sep 2026). Carried on auth/me so
            // the decision follows the person across browsers and devices.
            'onboarding_status' => $this->onboarding_status,
            'onboarding_seen_at' => $this->onboarding_seen_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
