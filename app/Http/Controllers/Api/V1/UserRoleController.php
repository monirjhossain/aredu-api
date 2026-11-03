<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\SyncUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Authorization\RoleService;
use Illuminate\Http\JsonResponse;

class UserRoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->roles->users();

        return ApiResponse::success(
            UserResource::collection($users->getCollection())->resolve(),
            'Users retrieved.',
            200,
            [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        );
    }

    public function sync(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $updated = $this->roles->syncUserRoles(
            $request->user(),
            $user,
            $request->validated('roles'),
        );

        return ApiResponse::success(
            UserResource::make($updated)->resolve(),
            'User roles updated.',
        );
    }
}
