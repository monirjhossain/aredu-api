<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\RoleRequest;
use App\Http\Requests\Authorization\SyncRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Services\Authorization\RoleService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return ApiResponse::success(
            RoleResource::collection($this->roles->roles())->resolve(),
            'Roles retrieved.',
        );
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        return ApiResponse::success(
            RoleResource::make($this->roles->createRole($request->validated()))->resolve(),
            'Role created.',
            201,
        );
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        return ApiResponse::success(
            RoleResource::make($this->roles->updateRole($role, $request->validated()))->resolve(),
            'Role updated.',
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);
        $this->roles->deleteRole($role);

        return ApiResponse::success(null, 'Role deleted.');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->authorize('syncPermissions', $role);

        $role = $this->roles->syncRolePermissions($role, $request->validated('permissions'));

        return ApiResponse::success(
            RoleResource::make($role)->resolve(),
            'Role permissions updated.',
        );
    }
}
