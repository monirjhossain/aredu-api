<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->auth->register($request->validated()),
            'Account created.',
            201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->auth->login($request->validated('email'), $request->validated('password')),
            'Logged in.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::success(null, 'Logged out.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->sendResetLink($request->validated('email'));

        return ApiResponse::success(null, 'If that email exists, a reset link has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->validated());

        return ApiResponse::success(null, 'Password has been reset.');
    }
}
