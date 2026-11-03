<?php

namespace Tests\Feature;

use App\Models\BandScoreConversion;
use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionAcceptedAnswer;
use App\Models\QuestionAudio;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\TestAttempt;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListeningAttemptScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_guest_cannot_start_listening_attempt(): void
    {
        $this->createPublishedListeningTest();
        $this->postJson('/api/v1/listening-tests/campus-listening/attempts')->assertUnauthorized();
    }

    public function test_student_can_play_audio_autosave_submit_and_score(): void
    {
        $fixture = $this->createPublishedListeningTest();
        $student = $this->userWithRole('student');
        $token = $student->createToken('api')->plainTextToken;

        BandScoreConversion::query()->create([
            'module' => 'listening',
            'exam_type' => 'academic',
            'raw_score' => 2,
            'band_score' => 7.0,
        ]);

        $started = $this->withToken($token)
            ->postJson('/api/v1/listening-tests/campus-listening/attempts')
            ->assertCreated()
            ->assertJsonPath('data.attempt.status', 'in_progress')
            ->assertJsonCount(4, 'data.test.sections')
            ->assertJsonMissingPath('data.test.sections.0.groups.0.questions.0.correct_option_id');

        $attemptId = $started->json('data.attempt.id');
        $sectionId = $started->json('data.test.sections.0.id');
        $this->assertNotNull($started->json('data.test.sections.0.audio.url'));

        $this->withToken($token)
            ->get("/api/v1/listening-attempts/{$attemptId}/sections/{$sectionId}/audio")
            ->assertOk();

        $other = $this->userWithRole('student');
        $this->withToken($other->createToken('api')->plainTextToken)
            ->get("/api/v1/listening-attempts/{$attemptId}/sections/{$sectionId}/audio")
            ->assertForbidden();

        Auth::forgetGuards();

        $group = collect($started->json('data.test.sections.0.groups'))->first();
        $question = $group['questions'][0];
        $completion = collect($started->json('data.test.sections.0.groups'))
            ->firstWhere('type', 'sentence_completion')['questions'][0];

        $this->withToken($token)
            ->putJson("/api/v1/listening-attempts/{$attemptId}/answers", [
                'answers' => [
                    [
                        'question_id' => $question['id'],
                        'question_option_id' => $fixture['correct_option_id'],
                    ],
                    [
                        'question_id' => $completion['id'],
                        'answer_text' => 'Library',
                    ],
                ],
            ])
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/listening-attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('data.attempt.status', 'submitted')
            ->assertJsonPath('data.result.raw_score', '2.00')
            ->assertJsonPath('data.result.band_score', '7.0');

        $this->withToken($token)
            ->postJson("/api/v1/listening-attempts/{$attemptId}/submit")
            ->assertStatus(422);

        $this->withToken($token)
            ->getJson('/api/v1/student/listening-attempts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $attemptId);
    }

    public function test_expired_listening_attempt_auto_submits(): void
    {
        $fixture = $this->createPublishedListeningTest();
        $student = $this->userWithRole('student');
        $token = $student->createToken('api')->plainTextToken;

        $attemptId = $this->withToken($token)
            ->postJson('/api/v1/listening-tests/campus-listening/attempts')
            ->json('data.attempt.id');

        TestAttempt::query()->whereKey($attemptId)->update([
            'must_submit_at' => now()->subSecond(),
        ]);

        $this->withToken($token)
            ->putJson("/api/v1/listening-attempts/{$attemptId}/answers", [
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
    }

    /**
     * @return array{correct_option_id: int, mcq_question_id: int}
     */
    private function createPublishedListeningTest(): array
    {
        $test = MockTest::query()->create([
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => 'academic',
            'title' => 'Campus Listening',
            'slug' => 'campus-listening',
            'instructions' => 'Listen carefully.',
            'duration_seconds' => 2400,
            'is_published' => true,
        ]);

        $mcqQuestionId = 0;
        $correctOptionId = 0;

        for ($i = 1; $i <= 4; $i++) {
            $path = "listening-audio/{$test->id}/section-{$i}.mp3";
            Storage::disk('local')->put($path, 'fake-audio');
            $audio = QuestionAudio::query()->create([
                'disk' => 'local',
                'path' => $path,
                'duration_seconds' => 120,
                'original_name' => "section-{$i}.mp3",
            ]);
            $section = MockTestSection::query()->create([
                'mock_test_id' => $test->id,
                'title' => 'Section '.$i,
                'position' => $i,
                'question_audio_id' => $audio->id,
            ]);

            if ($i === 1) {
                $mcqGroup = QuestionGroup::query()->create([
                    'mock_test_section_id' => $section->id,
                    'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                    'position' => 1,
                ]);
                $wrong = QuestionOption::query()->create([
                    'question_group_id' => $mcqGroup->id,
                    'label' => 'A',
                    'content' => 'Cafeteria',
                    'position' => 1,
                ]);
                $correct = QuestionOption::query()->create([
                    'question_group_id' => $mcqGroup->id,
                    'label' => 'B',
                    'content' => 'Library',
                    'position' => 2,
                ]);
                $mcq = Question::query()->create([
                    'question_group_id' => $mcqGroup->id,
                    'prompt' => 'Where is the meeting?',
                    'position' => 1,
                    'marks' => 1,
                    'correct_option_id' => $correct->id,
                ]);
                $completionGroup = QuestionGroup::query()->create([
                    'mock_test_section_id' => $section->id,
                    'type' => QuestionGroup::TYPE_SENTENCE_COMPLETION,
                    'position' => 2,
                ]);
                $completion = Question::query()->create([
                    'question_group_id' => $completionGroup->id,
                    'prompt' => 'Meet at the ______.',
                    'position' => 1,
                    'marks' => 1,
                ]);
                QuestionAcceptedAnswer::query()->create([
                    'question_id' => $completion->id,
                    'answer_text' => 'library',
                ]);

                $mcqQuestionId = $mcq->id;
                $correctOptionId = $correct->id;
                unset($wrong);
            }
        }

        return [
            'correct_option_id' => $correctOptionId,
            'mcq_question_id' => $mcqQuestionId,
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
