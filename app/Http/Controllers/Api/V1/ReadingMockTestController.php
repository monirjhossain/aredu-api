<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MockTest\ReadingMockTestIndexRequest;
use App\Http\Requests\MockTest\ReadingMockTestPublicationRequest;
use App\Http\Requests\MockTest\ReadingMockTestRequest;
use App\Http\Requests\MockTest\ReadingOptionRequest;
use App\Http\Requests\MockTest\ReadingPassageRequest;
use App\Http\Requests\MockTest\ReadingQuestionGroupRequest;
use App\Http\Requests\MockTest\ReadingQuestionRequest;
use App\Http\Requests\MockTest\ReadingSectionOrderRequest;
use App\Http\Requests\MockTest\ReadingSectionRequest;
use App\Http\Resources\ReadingMockTestResource;
use App\Http\Responses\ApiResponse;
use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use App\Models\QuestionPassage;
use App\Services\MockTest\ReadingMockTestService;
use Illuminate\Http\JsonResponse;

class ReadingMockTestController extends Controller
{
    public function __construct(private readonly ReadingMockTestService $tests) {}

    public function index(ReadingMockTestIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', MockTest::class);
        $page = $this->tests->paginate($request->validated());

        return ApiResponse::success(
            ReadingMockTestResource::collection($page->getCollection())->resolve(),
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

    public function store(ReadingMockTestRequest $request): JsonResponse
    {
        $this->authorize('create', MockTest::class);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->create($request->validated()))->resolve(),
            'Reading test created.',
            201,
        );
    }

    public function show(MockTest $readingTest): JsonResponse
    {
        $this->authorize('view', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->find($readingTest))->resolve(),
            'Reading test retrieved.',
        );
    }

    public function update(ReadingMockTestRequest $request, MockTest $readingTest): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->update($readingTest, $request->validated()))->resolve(),
            'Reading test updated.',
        );
    }

    public function publication(ReadingMockTestPublicationRequest $request, MockTest $readingTest): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->publish($readingTest, $request->boolean('is_published'))
            )->resolve(),
            'Reading test publication updated.',
        );
    }

    public function destroy(MockTest $readingTest): JsonResponse
    {
        $this->authorize('delete', $readingTest);
        $this->tests->delete($readingTest);

        return ApiResponse::success(null, 'Reading test deleted.');
    }

    public function storeSection(ReadingSectionRequest $request, MockTest $readingTest): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->addSection($readingTest, $request->validated()))->resolve(),
            'Section added.',
            201,
        );
    }

    public function updateSection(ReadingSectionRequest $request, MockTest $readingTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->updateSection($readingTest, $section, $request->validated()))->resolve(),
            'Section updated.',
        );
    }

    public function destroySection(MockTest $readingTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->deleteSection($readingTest, $section))->resolve(),
            'Section deleted.',
        );
    }

    public function reorderSections(ReadingSectionOrderRequest $request, MockTest $readingTest): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->reorderSections($readingTest, $request->validated('section_ids'))
            )->resolve(),
            'Sections reordered.',
        );
    }

    public function storePassage(ReadingPassageRequest $request, MockTest $readingTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->addPassage($readingTest, $section, $request->validated()))->resolve(),
            'Passage added.',
            201,
        );
    }

    public function updatePassage(
        ReadingPassageRequest $request,
        MockTest $readingTest,
        MockTestSection $section,
        QuestionPassage $passage,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->updatePassage($readingTest, $section, $passage, $request->validated())
            )->resolve(),
            'Passage updated.',
        );
    }

    public function destroyPassage(MockTest $readingTest, MockTestSection $section, QuestionPassage $passage): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->deletePassage($readingTest, $section, $passage))->resolve(),
            'Passage deleted.',
        );
    }

    public function storeGroup(ReadingQuestionGroupRequest $request, MockTest $readingTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->addGroup($readingTest, $section, $request->validated()))->resolve(),
            'Question group added.',
            201,
        );
    }

    public function updateGroup(
        ReadingQuestionGroupRequest $request,
        MockTest $readingTest,
        MockTestSection $section,
        QuestionGroup $group,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->updateGroup($readingTest, $section, $group, $request->validated())
            )->resolve(),
            'Question group updated.',
        );
    }

    public function destroyGroup(MockTest $readingTest, MockTestSection $section, QuestionGroup $group): JsonResponse
    {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make($this->tests->deleteGroup($readingTest, $section, $group))->resolve(),
            'Question group deleted.',
        );
    }

    public function storeOption(
        ReadingOptionRequest $request,
        MockTest $readingTest,
        MockTestSection $section,
        QuestionGroup $group,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->addOption($readingTest, $section, $group, $request->validated())
            )->resolve(),
            'Option added.',
            201,
        );
    }

    public function updateOption(
        ReadingOptionRequest $request,
        MockTest $readingTest,
        MockTestSection $section,
        QuestionGroup $group,
        QuestionOption $option,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->updateOption($readingTest, $section, $group, $option, $request->validated())
            )->resolve(),
            'Option updated.',
        );
    }

    public function destroyOption(
        MockTest $readingTest,
        MockTestSection $section,
        QuestionGroup $group,
        QuestionOption $option,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->deleteOption($readingTest, $section, $group, $option)
            )->resolve(),
            'Option deleted.',
        );
    }

    public function storeQuestion(
        ReadingQuestionRequest $request,
        MockTest $readingTest,
        MockTestSection $section,
        QuestionGroup $group,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->addQuestion($readingTest, $section, $group, $request->validated())
            )->resolve(),
            'Question added.',
            201,
        );
    }

    public function updateQuestion(
        ReadingQuestionRequest $request,
        MockTest $readingTest,
        MockTestSection $section,
        QuestionGroup $group,
        Question $question,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->updateQuestion($readingTest, $section, $group, $question, $request->validated())
            )->resolve(),
            'Question updated.',
        );
    }

    public function destroyQuestion(
        MockTest $readingTest,
        MockTestSection $section,
        QuestionGroup $group,
        Question $question,
    ): JsonResponse {
        $this->authorize('update', $readingTest);

        return ApiResponse::success(
            ReadingMockTestResource::make(
                $this->tests->deleteQuestion($readingTest, $section, $group, $question)
            )->resolve(),
            'Question deleted.',
        );
    }
}
