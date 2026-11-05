<?php

namespace Tests\Feature;

use App\Models\BandScoreConversion;
use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionAcceptedAnswer;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use App\Models\QuestionPassage;
use App\Models\Role;
use App\Models\TestAttempt;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ReadingAttemptScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_guest_cannot_start_reading_attempt(): void
    {
        $this->createPublishedReadingTest();

        $this->postJson('/api/v1/reading-tests/park-reading/attempts')
            ->assertUnauthorized();
    }

    public function test_student_can_resume_autosave_submit_and_score(): void
    {
        $fixture = $this->createPublishedReadingTest();
        $student = $this->userWithRole('student');
        $token = $student->createToken('api')->plainTextToken;

        BandScoreConversion::query()->create([
            'module' => 'reading',
            'exam_type' => 'academic',
            'raw_score' => 2,
            'band_score' => 6.5,
        ]);

        $started = $this->withToken($token)
            ->postJson('/api/v1/reading-tests/park-reading/attempts')
            ->assertCreated()
            ->assertJsonPath('data.attempt.status', 'in_progress')
            ->assertJsonMissingPath('data.test.sections.0.groups.0.questions.0.correct_option_id');

        $attemptId = $started->json('data.attempt.id');
        $this->assertGreaterThan(0, $started->json('data.attempt.seconds_remaining'));

        $resumed = $this->withToken($token)
            ->postJson('/api/v1/reading-tests/park-reading/attempts')
            ->assertCreated()
            ->assertJsonPath('data.attempt.id', $attemptId);

        $questionsJson = collect($resumed->json('data.test.sections.0.groups'))
            ->flatMap(fn ($group) => collect($group['questions'])->map(fn ($q) => [
                'group' => $group,
                'question' => $q,
            ]));

        $mcq = $questionsJson->first(fn ($row) => $row['group']['type'] === 'multiple_choice');
        $completion = $questionsJson->first(fn ($row) => $row['group']['type'] === 'sentence_completion');

        $correctOptionId = $fixture['correct_option_id'];
        $wrongOptionId = $fixture['wrong_option_id'];

        $this->withToken($token)
            ->putJson("/api/v1/reading-attempts/{$attemptId}/answers", [
                'answers' => [
                    [
                        'question_id' => $mcq['question']['id'],
                        'question_option_id' => $correctOptionId,
                    ],
                    [
                        'question_id' => $completion['question']['id'],
                        'answer_text' => 'Visitor Numbers',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.test.sections.0.groups.0.questions.0.answer.question_option_id', $correctOptionId);

        $other = $this->userWithRole('student');
        $this->withToken($other->createToken('api')->plainTextToken)
            ->putJson("/api/v1/reading-attempts/{$attemptId}/answers", [
                'answers' => [
                    ['question_id' => $mcq['question']['id'], 'question_option_id' => $wrongOptionId],
                ],
            ])
            ->assertForbidden();

        Auth::forgetGuards();

        $submitted = $this->withToken($token)
            ->postJson("/api/v1/reading-attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('data.attempt.status', 'submitted')
            ->assertJsonPath('data.result.raw_score', '2.00')
            ->assertJsonPath('data.result.max_score', '2.00')
            ->assertJsonPath('data.result.band_score', '6.5');

        $this->assertNotNull($submitted->json('data.test.sections.0.groups.0.questions.0.correct_option_id'));

        $this->withToken($token)
            ->postJson("/api/v1/reading-attempts/{$attemptId}/submit")
            ->assertStatus(422);

        $this->withToken($token)
            ->getJson('/api/v1/student/reading-attempts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $attemptId)
            ->assertJsonPath('data.0.result.band_score', '6.5');
    }

    public function test_expired_attempt_rejects_late_answers_and_auto_submits(): void
    {
        $fixture = $this->createPublishedReadingTest();
        $student = $this->userWithRole('student');
        $token = $student->createToken('api')->plainTextToken;

        $attemptId = $this->withToken($token)
            ->postJson('/api/v1/reading-tests/park-reading/attempts')
            ->json('data.attempt.id');

        TestAttempt::query()->whereKey($attemptId)->update([
            'must_submit_at' => now()->subSecond(),
        ]);

        $this->withToken($token)
            ->putJson("/api/v1/reading-attempts/{$attemptId}/answers", [
                'answers' => [
                    [
                        'question_id' => $fixture['mcq_question_id'],
                        'question_option_id' => $fixture['correct_option_id'],
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('test_attempts', [
            'id' => $attemptId,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('test_results', [
            'test_attempt_id' => $attemptId,
        ]);
    }

    public function test_admin_can_manage_reading_band_conversions_without_invented_defaults(): void
    {
        $admin = $this->userWithRole('super_admin');
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/band-conversions?module=reading&exam_type=academic')
            ->assertOk()
            ->assertJsonPath('data.rows', []);

        $this->withToken($token)
            ->putJson('/api/v1/admin/band-conversions', [
                'module' => 'reading',
                'exam_type' => 'academic',
                'rows' => [
                    ['raw_score' => 39, 'band_score' => 9.0],
                    ['raw_score' => 37, 'band_score' => 8.5],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.rows');

        $this->withToken($this->userWithRole('student')->createToken('api')->plainTextToken)
            ->putJson('/api/v1/admin/band-conversions', [
                'module' => 'reading',
                'exam_type' => 'academic',
                'rows' => [['raw_score' => 1, 'band_score' => 1]],
            ])
            ->assertForbidden();
    }

    /**
     * @return array{correct_option_id: int, wrong_option_id: int, mcq_question_id: int}
     */
    private function createPublishedReadingTest(): array
    {
        $test = MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Park Reading',
            'slug' => 'park-reading',
            'instructions' => 'Read the passage and answer.',
            'duration_seconds' => 3600,
            'is_published' => true,
            'is_free_sample' => true,
        ]);

        $section = MockTestSection::query()->create([
            'mock_test_id' => $test->id,
            'title' => 'Section 1',
            'position' => 1,
        ]);

        $passage = QuestionPassage::query()->create([
            'mock_test_section_id' => $section->id,
            'title' => 'Parks',
            'body' => 'Cities expanded parks and visitor numbers rose.',
            'position' => 1,
        ]);

        $mcqGroup = QuestionGroup::query()->create([
            'mock_test_section_id' => $section->id,
            'question_passage_id' => $passage->id,
            'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
            'position' => 1,
        ]);
        $wrong = QuestionOption::query()->create([
            'question_group_id' => $mcqGroup->id,
            'label' => 'A',
            'content' => 'Visitor numbers fell',
            'position' => 1,
        ]);
        $correct = QuestionOption::query()->create([
            'question_group_id' => $mcqGroup->id,
            'label' => 'B',
            'content' => 'Visitor numbers rose',
            'position' => 2,
        ]);
        $mcq = Question::query()->create([
            'question_group_id' => $mcqGroup->id,
            'prompt' => 'What happened to visitor numbers?',
            'position' => 1,
            'marks' => 1,
            'correct_option_id' => $correct->id,
        ]);

        $completionGroup = QuestionGroup::query()->create([
            'mock_test_section_id' => $section->id,
            'question_passage_id' => $passage->id,
            'type' => QuestionGroup::TYPE_SENTENCE_COMPLETION,
            'position' => 2,
        ]);
        $completion = Question::query()->create([
            'question_group_id' => $completionGroup->id,
            'prompt' => 'Councils report higher ______.',
            'position' => 1,
            'marks' => 1,
        ]);
        QuestionAcceptedAnswer::query()->create([
            'question_id' => $completion->id,
            'answer_text' => 'visitor numbers',
        ]);

        return [
            'correct_option_id' => $correct->id,
            'wrong_option_id' => $wrong->id,
            'mcq_question_id' => $mcq->id,
        ];
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
