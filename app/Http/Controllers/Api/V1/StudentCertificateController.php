<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Certificate;
use App\Services\Progress\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentCertificateController extends Controller
{
    public function __construct(private readonly LessonProgressService $progress) {}

    public function show(Request $request, Certificate $certificate): JsonResponse
    {
        return ApiResponse::success(
            $this->progress->showOwned($request->user(), $certificate),
            'Certificate retrieved.',
        );
    }
}
