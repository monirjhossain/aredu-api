<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(): JsonResponse
    {
        $this->authorize('viewDashboard');

        return ApiResponse::success($this->dashboard->summary(), 'Dashboard retrieved.');
    }
}
