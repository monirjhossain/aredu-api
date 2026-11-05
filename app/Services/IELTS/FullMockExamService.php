<?php

namespace App\Services\IELTS;

use App\Models\FullMockExam;
use App\Models\FullMockExamSection;
use App\Models\FullMockSessionSection;
use App\Models\MockTest;
use App\Models\SpeakingTask;
use App\Models\User;
use App\Models\WritingTask;
use App\Services\Practice\PracticeAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FullMockExamService
{
    public const EXAM_TYPES = [FullMockExam::TYPE_ACADEMIC, FullMockExam::TYPE_GENERAL];

    private const MAX_SECTION_SECONDS = 21600;

    public function __construct(private readonly PracticeAccessService $practiceAccess) {}

    /**
     * @param  array{search?: string|null, exam_type?: string|null, published?: mixed, is_published?: mixed}  $filters
     */
    public function paginateAdmin(array $filters = []): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);
        $published = $filters['published'] ?? ($filters['is_published'] ?? null);

        return FullMockExam::query()
            ->withCount('sections')
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('slug', 'like', $search);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->when($published !== null && $published !== '', function ($query) use ($published) {
                $query->where('is_published', $this->truthy($published));
            })
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Published exams visible to the student. Students without full practice access only see free exams.
     *
     * @param  array{search?: string|null, exam_type?: string|null}  $filters
     */
    public function paginatePublishedForStudent(User $user, array $filters = []): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);
        $freeOnly = ! $this->practiceAccess->hasFullPracticeAccess($user);

        return FullMockExam::query()
            ->withCount('sections')
            ->where('is_published', true)
            ->whereHas('sections')
            ->when($freeOnly, fn ($query) => $query->where('is_free', true))
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('slug', 'like', $search);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(12);
    }

    /**
     * Published exam by slug with sections; enforces free-or-enrolled access.
     */
    public function findPublishedForStudent(User $user, string $slug): FullMockExam
    {
        $exam = FullMockExam::query()
            ->where('is_published', true)
            ->where('slug', $slug)
            ->first();

        if ($exam === null) {
            throw (new ModelNotFoundException)->setModel(FullMockExam::class, [$slug]);
        }

        $this->practiceAccess->assertCanAccessFullMock($user, $exam);

        return $exam->load(['sections' => fn ($query) => $query->orderBy('position')->orderBy('id')]);
    }

    public function findAdmin(FullMockExam $exam): FullMockExam
    {
        return FullMockExam::query()
            ->with([
                'sections.mockTest:id,module,exam_type,title,slug,duration_seconds,is_published',
                'sections.writingTask:id,exam_type,task_type,title,recommended_minutes,is_published',
                'sections.speakingTask:id,part,title,is_published',
            ])
            ->withCount('sections')
            ->findOrFail($exam->id);
    }

    /**
     * Admin detail payload: exam, ordered sections with related titles, and non-blocking warnings.
     *
     * @return array<string, mixed>
     */
    public function adminShow(FullMockExam $exam): array
    {
        $exam = $this->findAdmin($exam);
        $warnings = [];

        $sections = $exam->sections->map(function (FullMockExamSection $section) use ($exam, &$warnings) {
            $source = $this->sourcePayload($section);
            $label = '#'.$section->position.' '.$section->title;

            if ($source === null) {
                $warnings[] = "{$label}: linked content is missing or was deleted.";
            } else {
                if (! $source['is_published']) {
                    $warnings[] = "{$label}: linked content is not published.";
                }
                if (($source['exam_type'] ?? $exam->exam_type) !== $exam->exam_type) {
                    $warnings[] = "{$label}: linked content exam type ({$source['exam_type']}) differs from this exam ({$exam->exam_type}).";
                }
            }

            return [
                'id' => $section->id,
                'skill' => $section->skill,
                'title' => $section->title,
                'position' => $section->position,
                'duration_seconds' => $section->duration_seconds,
                'instructions' => $section->instructions,
                'mock_test_id' => $section->mock_test_id,
                'writing_task_id' => $section->writing_task_id,
                'speaking_task_id' => $section->speaking_task_id,
                'source' => $source,
            ];
        })->values()->all();

        $skills = collect($sections)->pluck('skill')->unique()->all();
        foreach (FullMockExamSection::SKILLS as $skill) {
            if (! in_array($skill, $skills, true)) {
                $warnings[] = ucfirst($skill).' section is not part of this exam.';
            }
        }

        return [
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'slug' => $exam->slug,
                'exam_type' => $exam->exam_type,
                'instructions' => $exam->instructions,
                'is_published' => $exam->is_published,
                'is_free' => $exam->is_free,
                'allow_resume' => $exam->allow_resume,
                'sections_count' => count($sections),
                'created_at' => $exam->created_at?->toIso8601String(),
                'updated_at' => $exam->updated_at?->toIso8601String(),
            ],
            'sections' => $sections,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  optional "sections" key is synced in the same transaction
     */
    public function create(array $data): FullMockExam
    {
        return DB::transaction(function () use ($data) {
            $payload = $this->normalizeExamPayload($data, null);
            $wantsPublished = (bool) $payload['is_published'];
            $payload['is_published'] = false;

            $exam = FullMockExam::query()->create($payload);

            if (array_key_exists('sections', $data) && is_array($data['sections'])) {
                $this->syncSectionsInternal($exam, $data['sections']);
            }

            if ($wantsPublished) {
                $this->assertPublishable($exam);
                $exam->is_published = true;
                $exam->save();
            }

            return $this->findAdmin($exam);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FullMockExam $exam, array $data): FullMockExam
    {
        return DB::transaction(function () use ($exam, $data) {
            $exam = FullMockExam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $payload = $this->normalizeExamPayload($data, $exam);
            $exam->fill($payload);

            if (array_key_exists('sections', $data) && is_array($data['sections'])) {
                $this->syncSectionsInternal($exam, $data['sections']);
            } elseif ($exam->isDirty('exam_type')) {
                $exam->load('sections');
                $this->assertSectionsMatchExamType($exam);
            }

            if ($exam->is_published) {
                $this->assertPublishable($exam);
            }

            $exam->save();

            return $this->findAdmin($exam);
        });
    }

    public function publish(FullMockExam $exam, bool $published): FullMockExam
    {
        return DB::transaction(function () use ($exam, $published) {
            $exam = FullMockExam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();

            if ($published) {
                $this->assertPublishable($exam);
            }

            $exam->is_published = $published;
            $exam->save();

            return $this->findAdmin($exam);
        });
    }

    /**
     * Soft delete. Existing student sessions keep their snapshot and are not affected.
     */
    public function destroy(FullMockExam $exam): void
    {
        $exam->delete();
    }

    /**
     * Replace the ordered section list. Items may carry an existing section "id" to update in place;
     * sections missing from the payload are removed (blocked if a student session already uses them).
     *
     * @param  list<array<string, mixed>>  $sections
     */
    public function syncSections(FullMockExam $exam, array $sections): FullMockExam
    {
        return DB::transaction(function () use ($exam, $sections) {
            $exam = FullMockExam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $this->syncSectionsInternal($exam, $sections);

            if ($exam->is_published) {
                $this->assertPublishable($exam->refresh());
            }

            return $this->findAdmin($exam);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncSectionsInternal(FullMockExam $exam, array $items): void
    {
        $normalized = $this->normalizeSectionItems($exam, $items);

        $existing = FullMockExamSection::query()
            ->where('full_mock_exam_id', $exam->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $keepIds = [];
        foreach ($normalized as $index => $row) {
            $id = $row['id'];
            unset($row['id']);

            if ($id !== null) {
                /** @var FullMockExamSection|null $section */
                $section = $existing->get($id);
                if ($section === null) {
                    throw ValidationException::withMessages([
                        "sections.$index.id" => ['Section does not belong to this exam.'],
                    ]);
                }

                $this->assertSectionEditable($section, $row, $index);
                $section->fill($row)->save();
                $keepIds[] = $section->id;

                continue;
            }

            $created = FullMockExamSection::query()->create($row + ['full_mock_exam_id' => $exam->id]);
            $keepIds[] = $created->id;
        }

        foreach ($existing as $section) {
            if (in_array($section->id, $keepIds, true)) {
                continue;
            }

            if (FullMockSessionSection::query()->where('full_mock_exam_section_id', $section->id)->exists()) {
                throw ValidationException::withMessages([
                    'sections' => ["Section \"{$section->title}\" is used by student sessions and cannot be removed."],
                ]);
            }

            $section->delete();
        }

        $exam->unsetRelation('sections');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeSectionItems(FullMockExam $exam, array $items): array
    {
        $items = array_values($items);
        $rows = [];
        $seenIds = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(["sections.$index" => ['Invalid section.']]);
            }

            $skill = (string) ($item['skill'] ?? '');
            if (! in_array($skill, FullMockExamSection::SKILLS, true)) {
                throw ValidationException::withMessages([
                    "sections.$index.skill" => ['Skill must be listening, reading, writing or speaking.'],
                ]);
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $title = ucfirst($skill);
            }

            $duration = $item['duration_seconds'] ?? null;
            if ($duration === '' || $duration === null) {
                $duration = null;
            } else {
                $duration = (int) $duration;
                if ($duration < 60 || $duration > self::MAX_SECTION_SECONDS) {
                    throw ValidationException::withMessages([
                        "sections.$index.duration_seconds" => ['Duration must be between 60 and '.self::MAX_SECTION_SECONDS.' seconds.'],
                    ]);
                }
            }

            $instructions = $item['instructions'] ?? null;
            $instructions = is_string($instructions) && trim($instructions) !== '' ? $instructions : null;

            $id = isset($item['id']) && $item['id'] !== '' ? (int) $item['id'] : null;
            if ($id !== null) {
                if (isset($seenIds[$id])) {
                    throw ValidationException::withMessages([
                        "sections.$index.id" => ['Section listed more than once.'],
                    ]);
                }
                $seenIds[$id] = true;
            }

            $row = [
                'id' => $id,
                'skill' => $skill,
                'title' => $title,
                'position' => isset($item['position']) && $item['position'] !== '' ? (int) $item['position'] : ($index + 1),
                'duration_seconds' => $duration,
                'instructions' => $instructions,
                'mock_test_id' => null,
                'writing_task_id' => null,
                'speaking_task_id' => null,
            ];

            $this->resolveSource($exam, $skill, $item, $row, $index);
            $row['_order'] = $index;
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => [$a['position'], $a['_order']] <=> [$b['position'], $b['_order']]);

        foreach ($rows as $i => &$row) {
            $row['position'] = $i + 1;
            unset($row['_order']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Validate the linked content for a section item and fill the matching FK on $row.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $row
     */
    private function resolveSource(FullMockExam $exam, string $skill, array $item, array &$row, int $index): void
    {
        if ($skill === FullMockExamSection::SKILL_LISTENING || $skill === FullMockExamSection::SKILL_READING) {
            $key = "sections.$index.mock_test_id";
            $test = isset($item['mock_test_id']) ? MockTest::query()->find((int) $item['mock_test_id']) : null;

            if ($test === null) {
                throw ValidationException::withMessages([$key => ["Select a {$skill} mock test."]]);
            }
            if ($test->module !== $skill) {
                throw ValidationException::withMessages([$key => ["The selected test is not a {$skill} test."]]);
            }
            if (! $test->is_published) {
                throw ValidationException::withMessages([$key => ['The selected test must be published.']]);
            }
            // Listening papers are shared between Academic and General Training, so only reading is strict.
            if ($skill === FullMockExamSection::SKILL_READING && $test->exam_type !== $exam->exam_type) {
                throw ValidationException::withMessages([
                    $key => ["Reading test exam type ({$test->exam_type}) must match this exam ({$exam->exam_type})."],
                ]);
            }

            $row['mock_test_id'] = $test->id;

            return;
        }

        if ($skill === FullMockExamSection::SKILL_WRITING) {
            $key = "sections.$index.writing_task_id";
            $task = isset($item['writing_task_id']) ? WritingTask::query()->find((int) $item['writing_task_id']) : null;

            if ($task === null) {
                throw ValidationException::withMessages([$key => ['Select a writing task.']]);
            }
            if (! $task->is_published) {
                throw ValidationException::withMessages([$key => ['The selected writing task must be published.']]);
            }
            if ($task->exam_type !== $exam->exam_type) {
                throw ValidationException::withMessages([
                    $key => ["Writing task exam type ({$task->exam_type}) must match this exam ({$exam->exam_type})."],
                ]);
            }

            $row['writing_task_id'] = $task->id;

            return;
        }

        $key = "sections.$index.speaking_task_id";
        $task = isset($item['speaking_task_id']) ? SpeakingTask::query()->find((int) $item['speaking_task_id']) : null;

        if ($task === null) {
            throw ValidationException::withMessages([$key => ['Select a speaking task.']]);
        }
        if (! $task->is_published) {
            throw ValidationException::withMessages([$key => ['The selected speaking task must be published.']]);
        }

        $row['speaking_task_id'] = $task->id;
    }

    /**
     * Sessions already started against a section must keep their skill and linked content.
     *
     * @param  array<string, mixed>  $row
     */
    private function assertSectionEditable(FullMockExamSection $section, array $row, int $index): void
    {
        $changed = $section->skill !== $row['skill']
            || (int) $section->mock_test_id !== (int) $row['mock_test_id']
            || (int) $section->writing_task_id !== (int) $row['writing_task_id']
            || (int) $section->speaking_task_id !== (int) $row['speaking_task_id'];

        if (! $changed) {
            return;
        }

        if (FullMockSessionSection::query()->where('full_mock_exam_section_id', $section->id)->exists()) {
            throw ValidationException::withMessages([
                "sections.$index.skill" => ['This section is used by student sessions; add a new section instead of changing its skill or content.'],
            ]);
        }
    }

    private function assertPublishable(FullMockExam $exam): void
    {
        $exam->load('sections');

        if ($exam->sections->isEmpty()) {
            throw ValidationException::withMessages([
                'is_published' => ['Add at least one section before publishing.'],
            ]);
        }

        $exam->loadMissing(['sections.mockTest', 'sections.writingTask', 'sections.speakingTask']);

        foreach ($exam->sections as $section) {
            $source = match ($section->skill) {
                FullMockExamSection::SKILL_LISTENING, FullMockExamSection::SKILL_READING => $section->mockTest,
                FullMockExamSection::SKILL_WRITING => $section->writingTask,
                default => $section->speakingTask,
            };

            if ($source === null || ! $source->is_published) {
                throw ValidationException::withMessages([
                    'is_published' => ["Section \"{$section->title}\" must link to published content before the exam can be published."],
                ]);
            }
        }

        $this->assertSectionsMatchExamType($exam);
    }

    private function assertSectionsMatchExamType(FullMockExam $exam): void
    {
        $exam->loadMissing(['sections.mockTest', 'sections.writingTask']);

        foreach ($exam->sections as $section) {
            $linked = match ($section->skill) {
                FullMockExamSection::SKILL_READING => $section->mockTest,
                FullMockExamSection::SKILL_WRITING => $section->writingTask,
                default => null,
            };

            if ($linked !== null && $linked->exam_type !== $exam->exam_type) {
                throw ValidationException::withMessages([
                    'exam_type' => ["Section \"{$section->title}\" uses {$linked->exam_type} content; change it before switching exam type."],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeExamPayload(array $data, ?FullMockExam $existing): array
    {
        $examType = (string) ($data['exam_type'] ?? $existing?->exam_type ?? '');
        if (! in_array($examType, self::EXAM_TYPES, true)) {
            throw ValidationException::withMessages([
                'exam_type' => ['Exam type must be academic or general.'],
            ]);
        }

        $title = trim((string) ($data['title'] ?? $existing?->title ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => ['Title is required.'],
            ]);
        }

        $slug = array_key_exists('slug', $data) ? (string) $data['slug'] : '';
        if (trim($slug) === '') {
            $slug = $existing !== null && ! array_key_exists('slug', $data) ? $existing->slug : $title;
        }
        $slug = Str::slug($slug);
        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => ['A valid slug is required.'],
            ]);
        }

        $query = FullMockExam::withTrashed()->where('slug', $slug);
        if ($existing !== null) {
            $query->whereKeyNot($existing->id);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['This slug is already used.'],
            ]);
        }

        $instructions = array_key_exists('instructions', $data)
            ? $data['instructions']
            : $existing?->instructions;

        return [
            'exam_type' => $examType,
            'title' => $title,
            'slug' => $slug,
            'instructions' => is_string($instructions) && trim($instructions) !== '' ? $instructions : null,
            'is_published' => array_key_exists('is_published', $data)
                ? $this->truthy($data['is_published'])
                : (bool) ($existing?->is_published ?? false),
            'is_free' => array_key_exists('is_free', $data)
                ? $this->truthy($data['is_free'])
                : (bool) ($existing?->is_free ?? false),
            'allow_resume' => array_key_exists('allow_resume', $data)
                ? $this->truthy($data['allow_resume'])
                : (bool) ($existing?->allow_resume ?? true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sourcePayload(FullMockExamSection $section): ?array
    {
        return match ($section->skill) {
            FullMockExamSection::SKILL_LISTENING, FullMockExamSection::SKILL_READING => $section->mockTest === null ? null : [
                'type' => 'mock_test',
                'id' => $section->mockTest->id,
                'title' => $section->mockTest->title,
                'slug' => $section->mockTest->slug,
                'exam_type' => $section->mockTest->exam_type,
                'duration_seconds' => $section->mockTest->duration_seconds,
                'is_published' => (bool) $section->mockTest->is_published,
            ],
            FullMockExamSection::SKILL_WRITING => $section->writingTask === null ? null : [
                'type' => 'writing_task',
                'id' => $section->writingTask->id,
                'title' => $section->writingTask->title,
                'exam_type' => $section->writingTask->exam_type,
                'task_type' => $section->writingTask->task_type,
                'recommended_minutes' => $section->writingTask->recommended_minutes,
                'is_published' => (bool) $section->writingTask->is_published,
            ],
            default => $section->speakingTask === null ? null : [
                'type' => 'speaking_task',
                'id' => $section->speakingTask->id,
                'title' => $section->speakingTask->title,
                'part' => $section->speakingTask->part,
                'is_published' => (bool) $section->speakingTask->is_published,
            ],
        };
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
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
