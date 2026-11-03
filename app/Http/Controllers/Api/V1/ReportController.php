<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportIndexRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $payload = $this->reports->availableFor($request->user());

        return ApiResponse::success(
            $payload['reports'],
            'Available reports retrieved.',
            200,
            ['courses' => $payload['courses']],
        );
    }

    public function show(ReportIndexRequest $request, string $report): JsonResponse
    {
        $payload = $this->reports->paginate($request->user(), $report, $request->validated());

        return ApiResponse::success(
            [
                'columns' => $payload['columns'],
                'rows' => $payload['rows'],
            ],
            'Report retrieved.',
            200,
            $payload['meta'],
        );
    }

    public function export(ReportIndexRequest $request, string $report): StreamedResponse
    {
        return $this->reports->exportCsv($request->user(), $report, $request->validated());
    }
}
