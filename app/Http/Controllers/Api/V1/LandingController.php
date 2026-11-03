<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Landing\LandingService;
use Illuminate\Http\JsonResponse;

class LandingController extends Controller
{
    public function __construct(private readonly LandingService $landing) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            $this->landing->payload(),
            'Landing content retrieved.',
        );
    }
}
