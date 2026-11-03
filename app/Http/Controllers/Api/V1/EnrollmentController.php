<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PublicCourseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Payment;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Progress\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly LessonProgressService $progress,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->enrollments->paginateForStudent($request->user());

        return ApiResponse::success(
            EnrollmentResource::collection($page)->resolve(),
            'Enrollments retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function learning(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        $course = $this->enrollments->learningCourse($user, $slug);
        $course->setAttribute('viewer_learning', true);
        $course->setAttribute('lesson_progress', $this->progress->mapForCourse($user, $course));
        $course->setAttribute('course_progress', $this->progress->courseProgress($user, (int) $course->id));
        $course->setAttribute('certificate', $this->progress->certificateFor($user, (int) $course->id));

        return ApiResponse::success(
            PublicCourseResource::make($course)->resolve(),
            'Learning course retrieved.',
        );
    }

    public function refund(Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        $refunded = $this->enrollments->refundPayment($payment);

        return ApiResponse::success(
            PaymentResource::make($refunded)->resolve(),
            'Payment refunded and enrollment closed.',
        );
    }
}
