<?php

namespace App\Services\MockTest;

use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionAcceptedAnswer;
use App\Models\QuestionAudio;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use App\Models\QuestionPassage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListeningMockTestService
{
    public const SECTION_COUNT = 4;

    /**
     * @param  array{search?: string|null, exam_type?: string|null, is_published?: string|null}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return $this->listeningQuery()
            ->withCount('sections')
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('slug', 'like', $search);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->when(array_key_exists('is_published', $filters) && $filters['is_published'] !== null && $filters['is_published'] !== '', function ($query) use ($filters) {
                $query->where('is_published', $filters['is_published'] === '1' || $filters['is_published'] === 1 || $filters['is_published'] === true);
            })
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function find(MockTest $test): MockTest
    {
        $this->assertListening($test);

        return $this->loadTree($test->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): MockTest
    {
        $payload = $this->normalizeTestPayload($data, null);

        $test = DB::transaction(function () use ($payload) {
            $test = MockTest::query()->create($payload);
            for ($i = 1; $i <= self::SECTION_COUNT; $i++) {
                MockTestSection::query()->create([
                    'mock_test_id' => $test->id,
                    'title' => 'Section '.$i,
                    'position' => $i,
                    'question_audio_id' => null,
                ]);
            }

            return $test;
        });

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MockTest $test, array $data): MockTest
    {
        $this->assertListening($test);
        $test->update($this->normalizeTestPayload($data, $test));

        return $this->find($test);
    }

    public function publish(MockTest $test, bool $published): MockTest
    {
        $this->assertListening($test);

        if ($published) {
            $sections = $test->sections()->with('audio')->orderBy('position')->get();
            if ($sections->count() !== self::SECTION_COUNT) {
                throw ValidationException::withMessages([
                    'is_published' => ['A listening test must have exactly four sections before publishing.'],
                ]);
            }
            foreach ($sections as $section) {
                if ($section->question_audio_id === null) {
                    throw ValidationException::withMessages([
                        'is_published' => ['Upload audio for every section before publishing.'],
                    ]);
                }
            }
        }

        $test->is_published = $published;
        $test->save();

        return $this->find($test);
    }

    public function delete(MockTest $test): void
    {
        $this->assertListening($test);
        $test->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSection(MockTest $test, MockTestSection $section, array $data): MockTest
    {
        $section = $this->sectionOf($test, $section);
        $section->fill([
            'title' => $data['title'] ?? $section->title,
            'position' => $data['position'] ?? $section->position,
        ])->save();

        return $this->find($test);
    }

    public function storeAudio(MockTest $test, MockTestSection $section, UploadedFile $file, ?int $durationSeconds = null): MockTest
    {
        $section = $this->sectionOf($test, $section);

        DB::transaction(function () use ($section, $file, $durationSeconds) {
            $section = MockTestSection::query()->whereKey($section->id)->lockForUpdate()->firstOrFail();
            $previous = $section->audio;

            $path = $file->store('listening-audio/'.$section->mock_test_id, 'local');
            $audio = QuestionAudio::query()->create([
                'disk' => 'local',
                'path' => $path,
                'duration_seconds' => $durationSeconds,
                'original_name' => $file->getClientOriginalName(),
            ]);

            $section->question_audio_id = $audio->id;
            $section->save();

            if ($previous !== null) {
                Storage::disk($previous->disk)->delete($previous->path);
                $previous->delete();
            }
        });

        return $this->find($test);
    }

    public function destroyAudio(MockTest $test, MockTestSection $section): MockTest
    {
        $section = $this->sectionOf($test, $section);

        DB::transaction(function () use ($section) {
            $section = MockTestSection::query()->whereKey($section->id)->lockForUpdate()->firstOrFail();
            $audio = $section->audio;
            if ($audio === null) {
                return;
            }

            $section->question_audio_id = null;
            $section->save();
            Storage::disk($audio->disk)->delete($audio->path);
            $audio->delete();
        });

        return $this->find($test);
    }

    public function streamAudio(MockTest $test, MockTestSection $section): StreamedResponse
    {
        $section = $this->sectionOf($test, $section);
        $audio = $section->audio;
        if ($audio === null || ! Storage::disk($audio->disk)->exists($audio->path)) {
            throw (new ModelNotFoundException)->setModel(QuestionAudio::class);
        }

        return Storage::disk($audio->disk)->response(
            $audio->path,
            $audio->original_name ?: 'section-audio',
            [
                'Content-Type' => Storage::disk($audio->disk)->mimeType($audio->path) ?: 'audio/mpeg',
                'Content-Disposition' => 'inline',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addGroup(MockTest $test, MockTestSection $section, array $data): MockTest
    {
        $section = $this->sectionOf($test, $section);
        $type = $data['type'];
        $passageId = $data['question_passage_id'] ?? null;

        if ($passageId !== null) {
            $this->passageOf($test, $section, QuestionPassage::query()->findOrFail($passageId));
        }

        $position = (int) ($data['position'] ?? (($section->groups()->max('position') ?? 0) + 1));

        DB::transaction(function () use ($section, $data, $type, $passageId, $position) {
            $group = QuestionGroup::query()->create([
                'mock_test_section_id' => $section->id,
                'question_passage_id' => $passageId,
                'type' => $type,
                'instructions' => $data['instructions'] ?? null,
                'position' => $position,
            ]);
            $this->seedDefaultOptions($group);
        });

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(MockTest $test, MockTestSection $section, QuestionGroup $group, array $data): MockTest
    {
        $group = $this->groupOf($test, $section, $group);

        if (isset($data['type']) && $data['type'] !== $group->type) {
            throw ValidationException::withMessages([
                'type' => ['Question group type cannot be changed after creation.'],
            ]);
        }

        if (array_key_exists('question_passage_id', $data)) {
            $passageId = $data['question_passage_id'];
            if ($passageId !== null) {
                $this->passageOf($test, $section, QuestionPassage::query()->findOrFail($passageId));
            }
            $group->question_passage_id = $passageId;
        }

        $group->fill([
            'instructions' => array_key_exists('instructions', $data) ? $data['instructions'] : $group->instructions,
            'position' => $data['position'] ?? $group->position,
        ])->save();

        return $this->find($test);
    }

    public function deleteGroup(MockTest $test, MockTestSection $section, QuestionGroup $group): MockTest
    {
        $group = $this->groupOf($test, $section, $group);
        $this->deleteGroupTree($group);

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addOption(MockTest $test, MockTestSection $section, QuestionGroup $group, array $data): MockTest
    {
        $group = $this->groupOf($test, $section, $group);
        if (! $group->usesOptions()) {
            throw ValidationException::withMessages([
                'option' => ['This question type does not use options.'],
            ]);
        }

        $position = (int) ($data['position'] ?? (($group->options()->max('position') ?? 0) + 1));
        QuestionOption::query()->create([
            'question_group_id' => $group->id,
            'label' => $data['label'] ?? null,
            'content' => $data['content'],
            'position' => $position,
        ]);

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOption(
        MockTest $test,
        MockTestSection $section,
        QuestionGroup $group,
        QuestionOption $option,
        array $data,
    ): MockTest {
        $option = $this->optionOf($test, $section, $group, $option);
        $option->fill([
            'label' => array_key_exists('label', $data) ? $data['label'] : $option->label,
            'content' => $data['content'] ?? $option->content,
            'position' => $data['position'] ?? $option->position,
        ])->save();

        return $this->find($test);
    }

    public function deleteOption(
        MockTest $test,
        MockTestSection $section,
        QuestionGroup $group,
        QuestionOption $option,
    ): MockTest {
        $option = $this->optionOf($test, $section, $group, $option);
        if (Question::query()->where('correct_option_id', $option->id)->exists()) {
            throw ValidationException::withMessages([
                'option' => ['Clear this option as a correct answer before deleting it.'],
            ]);
        }
        $option->delete();

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addQuestion(MockTest $test, MockTestSection $section, QuestionGroup $group, array $data): MockTest
    {
        $group = $this->groupOf($test, $section, $group);
        $position = (int) ($data['position'] ?? (($group->questions()->max('position') ?? 0) + 1));

        $question = Question::query()->create([
            'question_group_id' => $group->id,
            'prompt' => $data['prompt'],
            'position' => $position,
            'marks' => $data['marks'] ?? 1,
            'correct_option_id' => null,
        ]);

        if ($group->usesOptions() && ! empty($data['correct_option_id'])) {
            $this->assignCorrectOption($group, $question, (int) $data['correct_option_id']);
        }

        if ($group->usesAcceptedAnswers() && isset($data['accepted_answers']) && is_array($data['accepted_answers'])) {
            $this->syncAcceptedAnswers($question, $data['accepted_answers']);
        }

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateQuestion(
        MockTest $test,
        MockTestSection $section,
        QuestionGroup $group,
        Question $question,
        array $data,
    ): MockTest {
        $question = $this->questionOf($test, $section, $group, $question);
        $group = $question->group()->firstOrFail();

        $question->fill([
            'prompt' => $data['prompt'] ?? $question->prompt,
            'position' => $data['position'] ?? $question->position,
            'marks' => $data['marks'] ?? $question->marks,
        ])->save();

        if ($group->usesOptions() && array_key_exists('correct_option_id', $data)) {
            if ($data['correct_option_id'] === null) {
                $question->correct_option_id = null;
                $question->save();
            } else {
                $this->assignCorrectOption($group, $question, (int) $data['correct_option_id']);
            }
        }

        if ($group->usesAcceptedAnswers() && array_key_exists('accepted_answers', $data)) {
            $this->syncAcceptedAnswers($question, is_array($data['accepted_answers']) ? $data['accepted_answers'] : []);
        }

        return $this->find($test);
    }

    public function deleteQuestion(
        MockTest $test,
        MockTestSection $section,
        QuestionGroup $group,
        Question $question,
    ): MockTest {
        $question = $this->questionOf($test, $section, $group, $question);
        $question->correct_option_id = null;
        $question->save();
        $question->acceptedAnswers()->delete();
        $question->delete();

        return $this->find($test);
    }

    private function listeningQuery()
    {
        return MockTest::query()->where('module', MockTest::MODULE_LISTENING);
    }

    private function assertListening(MockTest $test): void
    {
        if ($test->module !== MockTest::MODULE_LISTENING) {
            throw (new ModelNotFoundException)->setModel(MockTest::class, [$test->id]);
        }
    }

    private function loadTree(int $id): MockTest
    {
        return $this->listeningQuery()
            ->with([
                'sections.audio',
                'sections.groups.options',
                'sections.groups.questions.acceptedAnswers',
                'sections.groups.questions.correctOption',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTestPayload(array $data, ?MockTest $existing): array
    {
        $title = $data['title'];
        $slug = $data['slug'] ?? null;
        if ($slug === null || $slug === '') {
            $slug = Str::slug($title);
        }
        $slug = Str::slug($slug);
        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => ['A valid slug is required.'],
            ]);
        }

        $query = MockTest::query()->where('slug', $slug);
        if ($existing) {
            $query->where('id', '!=', $existing->id);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['This slug is already used.'],
            ]);
        }

        return [
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => $data['exam_type'],
            'title' => $title,
            'slug' => $slug,
            'instructions' => $data['instructions'] ?? null,
            'duration_seconds' => (int) $data['duration_seconds'],
            'is_published' => (bool) ($data['is_published'] ?? false),
        ];
    }

    private function sectionOf(MockTest $test, MockTestSection $section): MockTestSection
    {
        $this->assertListening($test);
        if ((int) $section->mock_test_id !== (int) $test->id) {
            throw (new ModelNotFoundException)->setModel(MockTestSection::class, [$section->id]);
        }

        return $section->loadMissing('audio');
    }

    private function passageOf(MockTest $test, MockTestSection $section, QuestionPassage $passage): QuestionPassage
    {
        $section = $this->sectionOf($test, $section);
        if ((int) $passage->mock_test_section_id !== (int) $section->id) {
            throw (new ModelNotFoundException)->setModel(QuestionPassage::class, [$passage->id]);
        }

        return $passage;
    }

    private function groupOf(MockTest $test, MockTestSection $section, QuestionGroup $group): QuestionGroup
    {
        $section = $this->sectionOf($test, $section);
        if ((int) $group->mock_test_section_id !== (int) $section->id) {
            throw (new ModelNotFoundException)->setModel(QuestionGroup::class, [$group->id]);
        }

        return $group->loadMissing('options');
    }

    private function optionOf(
        MockTest $test,
        MockTestSection $section,
        QuestionGroup $group,
        QuestionOption $option,
    ): QuestionOption {
        $group = $this->groupOf($test, $section, $group);
        if ((int) $option->question_group_id !== (int) $group->id) {
            throw (new ModelNotFoundException)->setModel(QuestionOption::class, [$option->id]);
        }

        return $option;
    }

    private function questionOf(
        MockTest $test,
        MockTestSection $section,
        QuestionGroup $group,
        Question $question,
    ): Question {
        $group = $this->groupOf($test, $section, $group);
        if ((int) $question->question_group_id !== (int) $group->id) {
            throw (new ModelNotFoundException)->setModel(Question::class, [$question->id]);
        }

        return $question;
    }

    private function assignCorrectOption(QuestionGroup $group, Question $question, int $optionId): void
    {
        $option = QuestionOption::query()
            ->whereKey($optionId)
            ->where('question_group_id', $group->id)
            ->first();

        if ($option === null) {
            throw ValidationException::withMessages([
                'correct_option_id' => ['The correct option must belong to this question group.'],
            ]);
        }

        $question->correct_option_id = $option->id;
        $question->save();
    }

    /**
     * @param  list<string|array{answer_text?: string}>  $answers
     */
    private function syncAcceptedAnswers(Question $question, array $answers): void
    {
        $texts = collect($answers)
            ->map(function ($answer) {
                if (is_string($answer)) {
                    return trim($answer);
                }
                if (is_array($answer) && isset($answer['answer_text'])) {
                    return trim((string) $answer['answer_text']);
                }

                return '';
            })
            ->filter(fn ($text) => $text !== '')
            ->unique()
            ->values();

        $question->acceptedAnswers()->delete();
        foreach ($texts as $text) {
            QuestionAcceptedAnswer::query()->create([
                'question_id' => $question->id,
                'answer_text' => $text,
            ]);
        }
    }

    private function seedDefaultOptions(QuestionGroup $group): void
    {
        $defaults = match ($group->type) {
            QuestionGroup::TYPE_TRUE_FALSE_NOT_GIVEN => [
                ['label' => 'T', 'content' => 'True'],
                ['label' => 'F', 'content' => 'False'],
                ['label' => 'NG', 'content' => 'Not Given'],
            ],
            QuestionGroup::TYPE_YES_NO_NOT_GIVEN => [
                ['label' => 'Y', 'content' => 'Yes'],
                ['label' => 'N', 'content' => 'No'],
                ['label' => 'NG', 'content' => 'Not Given'],
            ],
            default => [],
        };

        foreach ($defaults as $index => $option) {
            QuestionOption::query()->create([
                'question_group_id' => $group->id,
                'label' => $option['label'],
                'content' => $option['content'],
                'position' => $index + 1,
            ]);
        }
    }

    private function deleteGroupTree(QuestionGroup $group): void
    {
        DB::transaction(function () use ($group) {
            $group->load('questions');
            foreach ($group->questions as $question) {
                $question->correct_option_id = null;
                $question->save();
                $question->acceptedAnswers()->delete();
                $question->delete();
            }
            $group->options()->delete();
            $group->delete();
        });
    }

    private function term(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }
        $search = trim($search);

        return $search === '' ? null : '%'.$search.'%';
    }
}
