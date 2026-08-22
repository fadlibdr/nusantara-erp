<?php

namespace Modules\Iam\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\ApiController;
use Modules\Iam\Http\Requests\StoreRoleRequest;
use Modules\Iam\Http\Requests\UpdateRoleRequest;
use Modules\Iam\Http\Resources\RoleResource;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends ApiController
{
    /**
     * Users-per-role counted straight off the pivot table instead of through
     * spatie's Role::users() relation.
     *
     * That relation resolves its related model from the guard named on the Role
     * instance, and withCount()/loadCount() build a fresh instance whose
     * guard_name falls back to the *default* guard. Behind auth:sanctum the
     * default guard is 'sanctum', which Sanctum registers with a null provider
     * — so the lookup returns no model class and the request 500s.
     */
    private function usersCountSubquery(): Builder
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->selectRaw('count(*)')
            ->whereColumn(app(PermissionRegistrar::class)->pivotRole, 'roles.id')
            ->where('model_type', (new User)->getMorphClass());
    }

    private function usersCountFor(Role $role): int
    {
        return (int) DB::table(config('permission.table_names.model_has_roles'))
            ->where(app(PermissionRegistrar::class)->pivotRole, $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->count();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Role::query()
            ->with('permissions')
            ->select('roles.*')
            ->selectSub($this->usersCountSubquery(), 'users_count');

        if ($q = $request->string('q')->trim()->value()) {
            $query->where('name', 'like', "%{$q}%");
        }

        $query->orderBy('name');

        return $this->listing($request, $query, RoleResource::class, sortable: ['name']);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::create([
                'name' => $request->validated('name'),
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($request->validated('permissions', []));

            return $role;
        });

        return $this->created(new RoleResource($role->load('permissions')));
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');
        $role->users_count = $this->usersCountFor($role);

        return $this->ok(new RoleResource($role));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        if ($role->name === 'admin') {
            return $this->error('Role admin tidak dapat diubah.', 422);
        }

        $role = DB::transaction(function () use ($request, $role) {
            if ($request->has('name')) {
                $role->update(['name' => $request->validated('name')]);
            }

            if ($request->has('permissions')) {
                $role->syncPermissions($request->validated('permissions', []));
            }

            return $role;
        });

        return $this->ok(new RoleResource($role->load('permissions')), 'Role diperbarui');
    }

    public function syncPermissions(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        if ($role->name === 'admin') {
            return $this->error('Role admin tidak dapat diubah.', 422);
        }

        $role->syncPermissions($request->validated('permissions', []));

        return $this->ok(new RoleResource($role->load('permissions')), 'Permission role diperbarui');
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->name === 'admin') {
            return $this->error('Role admin tidak dapat dihapus.', 422);
        }

        if ($role->users()->exists()) {
            return $this->error('Role masih dipakai oleh user — lepaskan dulu dari semua user.', 422);
        }

        $role->delete();

        return $this->ok(null, 'Role dihapus');
    }
}
