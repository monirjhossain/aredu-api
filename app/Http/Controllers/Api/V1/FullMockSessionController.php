<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\IELTS\FullMockExamService;
use App\Services\IELTS\FullMockSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FullMockSessionController extends Controller
{
    public function __construct(
        private readonly FullMockExamService $exams,
        private readonly FullMockSessionService $sessions,
    ) {}

    public function start(Request $request, string $slug): JsonResponse
    {
        $exam = $this->exams->findPublishedForStudent($request->user(), $slug);
        $payload = $this->sessions->startOrResume($request->user(), $exam);

        return ApiResponse::success($payload, 'Full mock session ready.', 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(
            $this->sessions->show($request->user(), $uuid),
            'Full mock session retrieved.',
        );
    }

    public function enterSection(Request $request, string $uuid, int $section): JsonResponse
    {
        return ApiResponse::success(
            $this->sessions->enterSection($request->user(), $uuid, $section),
            'Section started.',
        );
    }

    public function completeSection(Request $request, string $uuid, int $section): JsonResponse
    {
        return ApiResponse::success(
            $this->sessions->completeSection($request->user(), $uuid, $section),
            'Section completed.',
        );
    }

    public function heartbeat(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(
            $this->sessions->heartbeat($request->user(), $uuid),
            'Session heartbeat.',
        );
    }

    public function abandon(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(
            $this->sessions->abandon($request->user(), $uuid),
            'Session abandoned.',
        );
    }
}
