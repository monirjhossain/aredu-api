<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MockTest;
use App\Services\MockTest\ListeningAttemptService;
use App\Services\MockTest\ReadingAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicMockPracticeController extends Controller
{
    public function __construct(
        private readonly ReadingAttemptService $reading,
        private readonly ListeningAttemptService $listening,
    ) {}

    public function readingIndex(Request $request): JsonResponse
    {
        return $this->index($request, MockTest::MODULE_READING);
    }

    public function listeningIndex(Request $request): JsonResponse
    {
        return $this->index($request, MockTest::MODULE_LISTENING);
    }

    public function readingShow(string $slug): JsonResponse
    {
        return $this->show($this->reading->freeSampleOverview($slug));
    }

    public function listeningShow(string $slug): JsonResponse
    {
        return $this->show($this->listening->freeSampleOverview($slug));
    }

    private function index(Request $request, string $module): JsonResponse
    {
        $filters = $request->validate([
            'exam_type' => ['nullable', 'in:academic,general'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $module === MockTest::MODULE_LISTENING
            ? $this->listening->paginateFreeSamples($filters)
            : $this->reading->paginateFreeSamples($filters);

        $label = $module === MockTest::MODULE_LISTENING ? 'Listening' : 'Reading';

        return ApiResponse::success(
            $page->getCollection()->map(fn (MockTest $test) => $this->card($test))->values()->all(),
            $label.' free sample tests retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    private function show(MockTest $test): JsonResponse
    {
        return ApiResponse::success($this->card($test), 'Free sample test retrieved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function card(MockTest $test): array
    {
        return [
            'id' => $test->id,
            'module' => $test->module,
            'title' => $test->title,
            'slug' => $test->slug,
            'exam_type' => $test->exam_type,
            'duration_seconds' => $test->duration_seconds,
            'instructions' => $test->instructions,
            'is_free_sample' => true,
        ];
    }
}
