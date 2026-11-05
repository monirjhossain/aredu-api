<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FullMockExam;
use App\Models\SpeakingTask;
use App\Models\WritingTask;
use App\Services\IELTS\FullMockExamService;
use App\Services\Practice\PracticeAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FullMockExamController extends Controller
{
    public function __construct(
        private readonly FullMockExamService $exams,
        private readonly PracticeAccessService $practiceAccess,
    ) {}

    /**
     * Read-only writing task picker for full-mock builders (mock_tests.manage only).
     * Does not grant writing.manage and does not expose write endpoints.
     */
    public function writingOptions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'exam_type' => ['nullable', 'in:academic,general'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $page = WritingTask::query()
            ->where('is_published', true)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like) {
                    $query->where('title', 'like', $like)->orWhere('prompt', 'like', $like);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(30);

        return ApiResponse::success(
            $page->getCollection()->map(fn (WritingTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'exam_type' => $task->exam_type,
                'task_type' => $task->task_type,
                'is_published' => true,
            ])->values()->all(),
            'Writing task options retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /**
     * Read-only speaking task picker for full-mock builders (mock_tests.manage only).
     */
    public function speakingOptions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'part' => ['nullable', 'integer', 'in:1,2,3'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $page = SpeakingTask::query()
            ->where('is_published', true)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like) {
                    $query->where('title', 'like', $like)->orWhere('instructions', 'like', $like);
                });
            })
            ->when(($filters['part'] ?? null) !== null && $filters['part'] !== '', function ($query) use ($filters) {
                $query->where('part', (int) $filters['part']);
            })
            ->orderBy('part')
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(30);

        return ApiResponse::success(
            $page->getCollection()->map(fn (SpeakingTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'part' => $task->part,
                'is_published' => true,
            ])->values()->all(),
            'Speaking task options retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function studentIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'exam_type' => ['nullable', 'in:academic,general'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->exams->paginatePublishedForStudent($request->user(), $filters);

        return ApiResponse::success(
            $page->getCollection()->map(fn (FullMockExam $exam) => [
                'id' => $exam->id,
                'title' => $exam->title,
                'slug' => $exam->slug,
                'exam_type' => $exam->exam_type,
                'instructions' => $exam->instructions,
                'is_free' => (bool) $exam->is_free,
                'allow_resume' => (bool) $exam->allow_resume,
                'sections_count' => (int) ($exam->sections_count ?? 0),
            ])->values()->all(),
            'Full mock exams retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'practice_access' => $this->practiceAccess->meta($request->user()),
            ],
        );
    }

    public function studentShow(Request $request, string $slug): JsonResponse
    {
        $exam = $this->exams->findPublishedForStudent($request->user(), $slug);

        return ApiResponse::success([
            'id' => $exam->id,
            'title' => $exam->title,
            'slug' => $exam->slug,
            'exam_type' => $exam->exam_type,
            'instructions' => $exam->instructions,
            'is_free' => (bool) $exam->is_free,
            'allow_resume' => (bool) $exam->allow_resume,
            'sections' => $exam->sections->map(fn ($section) => [
                'id' => $section->id,
                'skill' => $section->skill,
                'title' => $section->title,
                'position' => $section->position,
                'duration_seconds' => $section->duration_seconds,
                'instructions' => $section->instructions,
            ])->values()->all(),
        ], 'Full mock exam retrieved.');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'exam_type' => ['nullable', 'in:academic,general'],
            'published' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->exams->paginateAdmin($filters);

        return ApiResponse::success(
            $page->getCollection()->map(fn (FullMockExam $exam) => [
                'id' => $exam->id,
                'title' => $exam->title,
                'slug' => $exam->slug,
                'exam_type' => $exam->exam_type,
                'is_published' => (bool) $exam->is_published,
                'is_free' => (bool) $exam->is_free,
                'allow_resume' => (bool) $exam->allow_resume,
                'sections_count' => (int) ($exam->sections_count ?? 0),
            ])->values()->all(),
            'Admin full mock exams retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_type' => ['required', 'in:academic,general'],
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'instructions' => ['nullable', 'string'],
            'is_free' => ['sometimes', 'boolean'],
            'allow_resume' => ['sometimes', 'boolean'],
        ]);

        $exam = $this->exams->create($data);

        return ApiResponse::success($this->exams->adminShow($exam), 'Full mock exam created.', 201);
    }

    public function show(FullMockExam $fullMockExam): JsonResponse
    {
        return ApiResponse::success($this->exams->adminShow($fullMockExam), 'Full mock exam retrieved.');
    }

    public function update(Request $request, FullMockExam $fullMockExam): JsonResponse
    {
        $data = $request->validate([
            'exam_type' => ['sometimes', 'in:academic,general'],
            'title' => ['sometimes', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'instructions' => ['nullable', 'string'],
            'is_free' => ['sometimes', 'boolean'],
            'allow_resume' => ['sometimes', 'boolean'],
        ]);

        $exam = $this->exams->update($fullMockExam, $data);

        return ApiResponse::success($this->exams->adminShow($exam), 'Full mock exam updated.');
    }

    public function publication(Request $request, FullMockExam $fullMockExam): JsonResponse
    {
        $data = $request->validate([
            'is_published' => ['required', 'boolean'],
        ]);

        $exam = $this->exams->publish($fullMockExam, (bool) $data['is_published']);

        return ApiResponse::success($this->exams->adminShow($exam), 'Publication updated.');
    }

    public function destroy(FullMockExam $fullMockExam): JsonResponse
    {
        $this->exams->destroy($fullMockExam);

        return ApiResponse::success(null, 'Full mock exam deleted.');
    }

    public function syncSections(Request $request, FullMockExam $fullMockExam): JsonResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.skill' => ['required', 'in:listening,reading,writing,speaking'],
            'sections.*.title' => ['required', 'string', 'max:200'],
            'sections.*.duration_seconds' => ['nullable', 'integer', 'min:60', 'max:21600'],
            'sections.*.instructions' => ['nullable', 'string'],
            'sections.*.mock_test_id' => ['nullable', 'integer'],
            'sections.*.writing_task_id' => ['nullable', 'integer'],
            'sections.*.speaking_task_id' => ['nullable', 'integer'],
        ]);

        $this->exams->syncSections($fullMockExam, $data['sections']);

        return ApiResponse::success($this->exams->adminShow($fullMockExam->fresh()), 'Sections saved.');
    }
}
