<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\ActivityLogIndexRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ActivityLog;
use App\Services\Activity\ActivityLogService;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityLogService $logs) {}

    public function index(ActivityLogIndexRequest $request): JsonResponse
    {
        $page = $this->logs->paginate($request->validated());

        return ApiResponse::success(
            collect($page->items())->map(
                fn (ActivityLog $log) => $this->logs->transform($log)
            )->values()->all(),
            'Activity logs retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }
}
