<?php

namespace Modules\Iam\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\ApiController;
use Spatie\Permission\Models\Permission;

class PermissionController extends ApiController
{
    /**
     * All permissions grouped by module prefix, e.g.
     * { "crm": ["crm.view", "crm.create", ...], "prj": [...] }.
     */
    public function index(): JsonResponse
    {
        $grouped = Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => explode('.', $permission->name, 2)[0])
            ->map(fn ($permissions) => $permissions->pluck('name')->values())
            ->sortKeys();

        return $this->ok($grouped);
    }
}
