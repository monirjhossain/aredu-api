<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SettingBkashQrRequest;
use App\Http\Requests\Settings\SettingLogoRequest;
use App\Http\Requests\Settings\UpdateSettingRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Settings\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return ApiResponse::success(
            $this->settings->allForAdmin(),
            'Settings retrieved.',
        );
    }

    public function update(UpdateSettingRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->settings->update($request->user(), $request->validated()),
            'Settings updated.',
        );
    }

    public function storeLogo(SettingLogoRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->settings->storeLogo($request->user(), $request->file('logo')),
            'Logo updated.',
        );
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return ApiResponse::success(
            $this->settings->deleteLogo($request->user()),
            'Logo removed.',
        );
    }

    public function storeBkashQr(SettingBkashQrRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->settings->storeBkashQr($request->user(), $request->file('qr')),
            'bKash QR updated.',
        );
    }

    public function destroyBkashQr(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return ApiResponse::success(
            $this->settings->deleteBkashQr($request->user()),
            'bKash QR removed.',
        );
    }

    public function publicBranding(): JsonResponse
    {
        return ApiResponse::success(
            $this->settings->publicBranding(),
            'Public branding retrieved.',
        );
    }
}
