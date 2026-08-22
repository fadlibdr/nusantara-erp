<?php

namespace Modules\Iam\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Iam\Http\Requests\StoreUserRequest;
use Modules\Iam\Http\Requests\SyncUserRolesRequest;
use Modules\Iam\Http\Requests\UpdateUserRequest;
use Modules\Iam\Http\Resources\UserResource;
use Modules\Iam\Services\UserService;

class UserController extends ApiController
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('roles');

        if ($q = $request->string('q')->trim()->value()) {
            $query->where(function ($inner) use ($q): void {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($role = $request->string('role')->trim()->value()) {
            $query->role($role);
        }

        $query->orderBy('name');

        // email hanya sub-baris kolom name — tidak ada header untuk diklik.
        return $this->listing($request, $query, UserResource::class, sortable: ['name']);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->validated());

        return $this->created(new UserResource($user));
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok(new UserResource($user->load('roles')));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->users->update($user, $request->validated());

        return $this->ok(new UserResource($user), 'User diperbarui');
    }

    /**
     * Users are deactivated, never hard-deleted — their id is referenced by
     * approvals and documents across the ERP.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            return $this->error('Tidak dapat menonaktifkan akun sendiri.', 422);
        }

        $this->users->deactivate($user);

        return $this->ok(null, 'User dinonaktifkan');
    }

    public function syncRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $user = $this->users->syncRoles($user, $request->validated('roles'));

        return $this->ok(new UserResource($user), 'Role user diperbarui');
    }
}
