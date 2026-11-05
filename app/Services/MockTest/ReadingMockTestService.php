<?php

namespace App\Services\MockTest;

use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionAcceptedAnswer;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use App\Models\QuestionPassage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReadingMockTestService
{
    /**
     * @param  array{search?: string|null, exam_type?: string|null, is_published?: string|null}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return $this->readingQuery()
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
        $this->assertReading($test);

        return $this->loadTree($test->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): MockTest
    {
        $payload = $this->normalizeTestPayload($data, null);
        $test = MockTest::query()->create($payload);

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MockTest $test, array $data): MockTest
    {
        $this->assertReading($test);
        $test->update($this->normalizeTestPayload($data, $test));

        return $this->find($test);
    }

    public function publish(MockTest $test, bool $published): MockTest
    {
        $this->assertReading($test);
        $test->is_published = $published;
        $test->save();

        return $this->find($test);
    }

    public function delete(MockTest $test): void
    {
        $this->assertReading($test);
        $test->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addSection(MockTest $test, array $data): MockTest
    {
        $this->assertReading($test);

        $position = (int) ($data['position'] ?? (($test->sections()->max('position') ?? 0) + 1));

        MockTestSection::query()->create([
            'mock_test_id' => $test->id,
            'title' => $data['title'],
            'position' => $position,
            'question_audio_id' => null,
        ]);

        return $this->find($test);
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

    public function deleteSection(MockTest $test, MockTestSection $section): MockTest
    {
        $section = $this->sectionOf($test, $section);

        DB::transaction(function () use ($section) {
            foreach ($section->groups as $group) {
                $this->deleteGroupTree($group);
            }
            $section->passages()->delete();
            $section->delete();
        });

        return $this->find($test);
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderSections(MockTest $test, array $orderedIds): MockTest
    {
        $this->assertReading($test);
        $existing = $test->sections()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $incoming = collect($orderedIds)->map(fn ($id) => (int) $id)->values()->all();
        sort($incoming);

        if ($existing !== $incoming) {
            throw ValidationException::withMessages([
                'section_ids' => ['Section order must include every section exactly once.'],
            ]);
        }

        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $index => $id) {
                MockTestSection::query()->whereKey($id)->update(['position' => $index + 1]);
            }
        });

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addPassage(MockTest $test, MockTestSection $section, array $data): MockTest
    {
        $section = $this->sectionOf($test, $section);
        $position = (int) ($data['position'] ?? (($section->passages()->max('position') ?? 0) + 1));

        QuestionPassage::query()->create([
            'mock_test_section_id' => $section->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'position' => $position,
        ]);

        return $this->find($test);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePassage(MockTest $test, MockTestSection $section, QuestionPassage $passage, array $data): MockTest
    {
        $passage = $this->passageOf($test, $section, $passage);
        $passage->fill([
            'title' => array_key_exists('title', $data) ? $data['title'] : $passage->title,
            'body' => $data['body'] ?? $passage->body,
            'position' => $data['position'] ?? $passage->position,
        ])->save();

        return $this->find($test);
    }

    public function deletePassage(MockTest $test, MockTestSection $section, QuestionPassage $passage): MockTest
    {
        $passage = $this->passageOf($test, $section, $passage);

        if ($passage->groups()->exists()) {
            throw ValidationException::withMessages([
                'passage' => ['Detach or move question groups before deleting this passage.'],
            ]);
        }

        $passage->delete();

        return $this->find($test);
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

    private function readingQuery()
    {
        return MockTest::query()->where('module', MockTest::MODULE_READING);
    }

    private function assertReading(MockTest $test): void
    {
        if ($test->module !== MockTest::MODULE_READING) {
            throw (new ModelNotFoundException)->setModel(MockTest::class, [$test->id]);
        }
    }

    private function loadTree(int $id): MockTest
    {
        return $this->readingQuery()
            ->with([
                'sections.passages',
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
            'module' => MockTest::MODULE_READING,
            'exam_type' => $data['exam_type'],
            'title' => $title,
            'slug' => $slug,
            'instructions' => $data['instructions'] ?? null,
            'duration_seconds' => (int) $data['duration_seconds'],
            'is_published' => (bool) ($data['is_published'] ?? false),
            'is_free_sample' => (bool) ($data['is_free_sample'] ?? false),
        ];
    }

    private function sectionOf(MockTest $test, MockTestSection $section): MockTestSection
    {
        $this->assertReading($test);

        if ((int) $section->mock_test_id !== (int) $test->id) {
            throw (new ModelNotFoundException)->setModel(MockTestSection::class, [$section->id]);
        }

        return $section;
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
