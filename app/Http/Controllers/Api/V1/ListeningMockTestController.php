<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MockTest\ListeningAudioRequest;
use App\Http\Requests\MockTest\ReadingMockTestIndexRequest;
use App\Http\Requests\MockTest\ReadingMockTestPublicationRequest;
use App\Http\Requests\MockTest\ReadingMockTestRequest;
use App\Http\Requests\MockTest\ReadingOptionRequest;
use App\Http\Requests\MockTest\ReadingQuestionGroupRequest;
use App\Http\Requests\MockTest\ReadingQuestionRequest;
use App\Http\Requests\MockTest\ReadingSectionRequest;
use App\Http\Resources\ListeningMockTestResource;
use App\Http\Responses\ApiResponse;
use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use App\Services\MockTest\ListeningMockTestService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListeningMockTestController extends Controller
{
    public function __construct(private readonly ListeningMockTestService $tests) {}

    public function index(ReadingMockTestIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', MockTest::class);
        $page = $this->tests->paginate($request->validated());

        return ApiResponse::success(
            ListeningMockTestResource::collection($page->getCollection())->resolve(),
            'Listening tests retrieved.',
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
            ListeningMockTestResource::make($this->tests->create($request->validated()))->resolve(),
            'Listening test created.',
            201,
        );
    }

    public function show(MockTest $listeningTest): JsonResponse
    {
        $this->authorize('view', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make($this->tests->find($listeningTest))->resolve(),
            'Listening test retrieved.',
        );
    }

    public function update(ReadingMockTestRequest $request, MockTest $listeningTest): JsonResponse
    {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make($this->tests->update($listeningTest, $request->validated()))->resolve(),
            'Listening test updated.',
        );
    }

    public function publication(ReadingMockTestPublicationRequest $request, MockTest $listeningTest): JsonResponse
    {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->publish($listeningTest, $request->boolean('is_published'))
            )->resolve(),
            'Listening test publication updated.',
        );
    }

    public function destroy(MockTest $listeningTest): JsonResponse
    {
        $this->authorize('delete', $listeningTest);
        $this->tests->delete($listeningTest);

        return ApiResponse::success(null, 'Listening test deleted.');
    }

    public function updateSection(ReadingSectionRequest $request, MockTest $listeningTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->updateSection($listeningTest, $section, $request->validated())
            )->resolve(),
            'Section updated.',
        );
    }

    public function storeAudio(ListeningAudioRequest $request, MockTest $listeningTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->storeAudio(
                    $listeningTest,
                    $section,
                    $request->file('audio'),
                    $request->validated('duration_seconds'),
                )
            )->resolve(),
            'Section audio uploaded.',
        );
    }

    public function destroyAudio(MockTest $listeningTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make($this->tests->destroyAudio($listeningTest, $section))->resolve(),
            'Section audio removed.',
        );
    }

    public function streamAudio(MockTest $listeningTest, MockTestSection $section): StreamedResponse
    {
        $this->authorize('view', $listeningTest);

        return $this->tests->streamAudio($listeningTest, $section);
    }

    public function storeGroup(ReadingQuestionGroupRequest $request, MockTest $listeningTest, MockTestSection $section): JsonResponse
    {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make($this->tests->addGroup($listeningTest, $section, $request->validated()))->resolve(),
            'Question group added.',
            201,
        );
    }

    public function updateGroup(
        ReadingQuestionGroupRequest $request,
        MockTest $listeningTest,
        MockTestSection $section,
        QuestionGroup $group,
    ): JsonResponse {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->updateGroup($listeningTest, $section, $group, $request->validated())
            )->resolve(),
            'Question group updated.',
        );
    }

    public function destroyGroup(MockTest $listeningTest, MockTestSection $section, QuestionGroup $group): JsonResponse
    {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make($this->tests->deleteGroup($listeningTest, $section, $group))->resolve(),
            'Question group deleted.',
        );
    }

    public function storeOption(
        ReadingOptionRequest $request,
        MockTest $listeningTest,
        MockTestSection $section,
        QuestionGroup $group,
    ): JsonResponse {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->addOption($listeningTest, $section, $group, $request->validated())
            )->resolve(),
            'Option added.',
            201,
        );
    }

    public function updateOption(
        ReadingOptionRequest $request,
        MockTest $listeningTest,
        MockTestSection $section,
        QuestionGroup $group,
        QuestionOption $option,
    ): JsonResponse {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->updateOption($listeningTest, $section, $group, $option, $request->validated())
            )->resolve(),
            'Option updated.',
        );
    }

    public function destroyOption(
        MockTest $listeningTest,
        MockTestSection $section,
        QuestionGroup $group,
        QuestionOption $option,
    ): JsonResponse {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->deleteOption($listeningTest, $section, $group, $option)
            )->resolve(),
            'Option deleted.',
        );
    }

    public function storeQuestion(
        ReadingQuestionRequest $request,
        MockTest $listeningTest,
        MockTestSection $section,
        QuestionGroup $group,
    ): JsonResponse {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->addQuestion($listeningTest, $section, $group, $request->validated())
            )->resolve(),
            'Question added.',
            201,
        );
    }

    public function updateQuestion(
        ReadingQuestionRequest $request,
        MockTest $listeningTest,
        MockTestSection $section,
        QuestionGroup $group,
        Question $question,
    ): JsonResponse {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->updateQuestion($listeningTest, $section, $group, $question, $request->validated())
            )->resolve(),
            'Question updated.',
        );
    }

    public function destroyQuestion(
        MockTest $listeningTest,
        MockTestSection $section,
        QuestionGroup $group,
        Question $question,
    ): JsonResponse {
        $this->authorize('update', $listeningTest);

        return ApiResponse::success(
            ListeningMockTestResource::make(
                $this->tests->deleteQuestion($listeningTest, $section, $group, $question)
            )->resolve(),
            'Question deleted.',
        );
    }
}
