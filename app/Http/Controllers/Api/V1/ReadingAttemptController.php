<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TestAttempt;
use App\Services\MockTest\ReadingAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadingAttemptController extends Controller
{
    public function __construct(private readonly ReadingAttemptService $attempts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'exam_type' => ['nullable', 'in:academic,general'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->attempts->paginatePublished($filters);

        return ApiResponse::success(
            $page->getCollection()->map(fn ($test) => [
                'id' => $test->id,
                'title' => $test->title,
                'slug' => $test->slug,
                'exam_type' => $test->exam_type,
                'duration_seconds' => $test->duration_seconds,
                'instructions' => $test->instructions,
            ])->values()->all(),
            'Reading tests retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(string $slug): JsonResponse
    {
        $test = $this->attempts->publishedOverview($slug);

        return ApiResponse::success([
            'id' => $test->id,
            'title' => $test->title,
            'slug' => $test->slug,
            'exam_type' => $test->exam_type,
            'duration_seconds' => $test->duration_seconds,
            'instructions' => $test->instructions,
        ], 'Reading test retrieved.');
    }

    public function start(Request $request, string $slug): JsonResponse
    {
        return ApiResponse::success(
            $this->attempts->startOrResume($request->user(), $slug),
            'Reading attempt ready.',
            201,
        );
    }

    public function showAttempt(Request $request, TestAttempt $attempt): JsonResponse
    {
        return ApiResponse::success(
            $this->attempts->showAttempt($request->user(), $attempt),
            'Reading attempt retrieved.',
        );
    }

    public function saveAnswers(Request $request, TestAttempt $attempt): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.question_option_id' => ['nullable', 'integer'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success(
            $this->attempts->saveAnswers($request->user(), $attempt, $data['answers']),
            'Answers saved.',
        );
    }

    public function submit(Request $request, TestAttempt $attempt): JsonResponse
    {
        return ApiResponse::success(
            $this->attempts->submit($request->user(), $attempt),
            'Attempt submitted.',
        );
    }

    public function history(Request $request): JsonResponse
    {
        $page = $this->attempts->paginateHistory($request->user());

        return ApiResponse::success(
            $page->getCollection()->map(fn (TestAttempt $attempt) => [
                'id' => $attempt->id,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'test' => $attempt->mockTest ? [
                    'id' => $attempt->mockTest->id,
                    'title' => $attempt->mockTest->title,
                    'slug' => $attempt->mockTest->slug,
                    'exam_type' => $attempt->mockTest->exam_type,
                ] : null,
                'result' => $attempt->result ? [
                    'raw_score' => $attempt->result->raw_score,
                    'max_score' => $attempt->result->max_score,
                    'band_score' => $attempt->result->band_score,
                ] : null,
            ])->values()->all(),
            'Reading attempt history retrieved.',
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
