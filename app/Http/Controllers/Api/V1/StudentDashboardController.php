<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\StudentDashboardService;
use Illuminate\Http\JsonResponse;

class StudentDashboardController extends Controller
{
    public function __construct(private readonly StudentDashboardService $dashboard) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            $this->dashboard->summary(request()->user()),
            'Student dashboard retrieved.'
        );
    }
}
