<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ResetAccountPasswordRequest;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Account\AdminAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccountController extends Controller
{
    public function __construct(private readonly AdminAccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $page = $this->accounts->paginate([
            'search' => $request->query('search'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page'),
        ]);

        return ApiResponse::success(
            UserResource::collection($page)->resolve(),
            'Accounts retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(int $account): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return ApiResponse::success(
            UserResource::make($this->accounts->find($account))->resolve(),
            'Account retrieved.',
        );
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $created = $this->accounts->create($request->user(), $request->validated());

        return ApiResponse::success(
            UserResource::make($created)->resolve(),
            'Account created.',
            201,
        );
    }

    public function update(UpdateAccountRequest $request, int $account): JsonResponse
    {
        $target = $this->accounts->find($account);
        $this->authorize('update', $target);

        $updated = $this->accounts->update($request->user(), $target, $request->validated());

        return ApiResponse::success(
            UserResource::make($updated)->resolve(),
            'Account updated.',
        );
    }

    public function setActive(Request $request, int $account): JsonResponse
    {
        $target = $this->accounts->find($account);
        $this->authorize('update', $target);

        $active = $request->boolean('is_active');
        $updated = $this->accounts->setActive($request->user(), $target, $active);

        return ApiResponse::success(
            UserResource::make($updated)->resolve(),
            $active ? 'Account activated.' : 'Account deactivated.',
        );
    }

    public function resetPassword(ResetAccountPasswordRequest $request, int $account): JsonResponse
    {
        $target = $this->accounts->find($account);
        $this->authorize('update', $target);

        $updated = $this->accounts->resetPassword(
            $request->user(),
            $target,
            $request->validated('password'),
        );

        return ApiResponse::success(
            UserResource::make($updated)->resolve(),
            'Password reset.',
        );
    }
}
