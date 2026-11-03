<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\PermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Permission;
use App\Services\Authorization\RoleService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        $permissions = $this->roles->permissions();
        $modules = $permissions
            ->groupBy(fn ($permission) => explode('.', $permission->slug, 2)[0] ?: 'other')
            ->map(fn ($rows, $module) => [
                'key' => $module,
                'permissions' => PermissionResource::collection($rows->values())->resolve(),
            ])
            ->values()
            ->all();

        return ApiResponse::success(
            PermissionResource::collection($permissions)->resolve(),
            'Permissions retrieved.',
            200,
            ['modules' => $modules],
        );
    }

    public function store(PermissionRequest $request): JsonResponse
    {
        $this->authorize('create', Permission::class);

        return ApiResponse::success(
            PermissionResource::make($this->roles->createPermission($request->validated()))->resolve(),
            'Permission created.',
            201,
        );
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $this->authorize('delete', $permission);
        $this->roles->deletePermission($permission);

        return ApiResponse::success(null, 'Permission deleted.');
    }
}
