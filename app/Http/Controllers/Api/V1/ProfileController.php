<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateAvatarRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            UserResource::make($request->user()->load('roles.permissions'))->resolve(),
            'Authenticated user.',
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->auth->updateProfile($request->user(), $request->validated());

        return ApiResponse::success(
            UserResource::make($user)->resolve(),
            'Profile updated.',
        );
    }

    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->auth->updateAvatar($request->user(), $request->file('avatar'));

        return ApiResponse::success(
            UserResource::make($user)->resolve(),
            'Avatar updated.',
        );
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $this->auth->deleteAvatar($request->user());

        return ApiResponse::success(
            UserResource::make($user)->resolve(),
            'Avatar removed.',
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return ApiResponse::success(null, 'Password changed.');
    }
}
