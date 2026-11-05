<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Analytics\StudentSkillAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentSkillAnalyticsController extends Controller
{
    public function __construct(private readonly StudentSkillAnalyticsService $analytics) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->analytics->summary($request->user()),
            'Student skill analytics retrieved.',
        );
    }
}
