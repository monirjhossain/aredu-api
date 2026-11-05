<?php

namespace Tests\Feature;

use App\Models\BandScoreConversion;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\FullMockExam;
use App\Models\FullMockExamSection;
use App\Models\FullMockSession;
use App\Models\FullMockSessionSection;
use App\Models\MockTest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\TestAttempt;
use App\Models\TestResult;
use App\Models\User;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use App\Support\IeltsOverallBand;
use Database\Seeders\DemoFullMockExamSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FullMockExamHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_mock_manager_can_load_writing_and_speaking_selectors_without_manage_permissions(): void
    {
        WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Selector Essay',
            'prompt' => 'Prompt',
            'recommended_minutes' => 40,
            'min_words' => 250,
            'is_published' => true,
        ]);
        SpeakingTask::query()->create([
            'part' => 2,
            'title' => 'Selector Cue',
            'instructions' => null,
            'preparation_seconds' => 60,
            'response_seconds' => 120,
            'is_published' => true,
        ]);

        $token = $this->tokenWithPermissions(['mock_tests.manage']);

        $this->withToken($token)
            ->getJson('/api/v1/admin/writing-tasks')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/admin/speaking-tasks')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/admin/full-mocks/options/writing-tasks?exam_type=academic')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Selector Essay');

        $this->withToken($token)
            ->getJson('/api/v1/admin/full-mocks/options/speaking-tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Selector Cue');
    }

    public function test_demo_full_mock_seeder_is_idempotent_and_links_four_skills(): void
    {
        $this->seed(DemoFullMockExamSeeder::class);
        $this->seed(DemoFullMockExamSeeder::class);

        $this->assertSame(1, FullMockExam::query()->where('slug', DemoFullMockExamSeeder::SLUG)->count());
        $exam = FullMockExam::query()->where('slug', DemoFullMockExamSeeder::SLUG)->firstOrFail();
        $this->assertTrue($exam->is_published);
        $this->assertTrue($exam->is_free);
        $this->assertSame(4, $exam->sections()->count());
        $this->assertEqualsCanonicalizing(
            ['listening', 'reading', 'writing', 'speaking'],
            $exam->sections()->pluck('skill')->all(),
        );
    }

    public function test_four_skill_flow_isolation_timers_resume_teacher_eval_and_pending_overall(): void
    {
        [$listening, $reading, $writing, $speaking] = $this->seedSkillContent();
        $exam = $this->makeFullMock($listening, $reading, $writing, $speaking);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        // Practice writing draft (isolated from full mock context)
        Auth::forgetGuards();
        $this->enroll($student);
        $this->withToken($token)
            ->putJson("/api/v1/writing-tasks/{$writing->id}/submission", [
                'body' => implode(' ', array_fill(0, 60, 'practice')),
            ])
            ->assertOk();

        $start = $this->withToken($token)
            ->postJson('/api/v1/full-mocks/'.$exam->slug.'/sessions')
            ->assertCreated()
            ->json('data');
        $uuid = $start['session']['uuid'];
        $this->assertSame(4, count($start['sections']));
        $this->assertTrue($start['scorecard']['overall_pending']);
        $this->assertNull($start['scorecard']['overall_band']);

        // Resume after "refresh"
        $resume = $this->withToken($token)
            ->postJson('/api/v1/full-mocks/'.$exam->slug.'/sessions')
            ->assertCreated()
            ->json('data');
        $this->assertSame($uuid, $resume['session']['uuid']);
        $this->assertSame(1, FullMockSession::query()->where('user_id', $student->id)->count());

        $bySkill = collect($start['sections'])->keyBy('skill');

        // Listening enter + heartbeat + submit attempt + complete
        $listenEnter = $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['listening']['id']}/enter")
            ->assertOk()
            ->json('data');
        $listeningAttemptId = $listenEnter['navigation']['attempt_id'];
        $this->assertNotNull($listeningAttemptId);
        $this->assertSame(
            'fmss:'.$bySkill['listening']['id'],
            TestAttempt::query()->findOrFail($listeningAttemptId)->context_key,
        );

        $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.session.uuid', $uuid);

        TestAttempt::query()->whereKey($listeningAttemptId)->update([
            'status' => TestAttempt::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        TestResult::query()->create([
            'test_attempt_id' => $listeningAttemptId,
            'raw_score' => 3,
            'max_score' => 3,
            'band_score' => 7.0,
        ]);
        FullMockSessionSection::query()->whereKey($bySkill['listening']['id'])->update([
            'test_attempt_id' => $listeningAttemptId,
        ]);
        $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['listening']['id']}/complete")
            ->assertOk();

        // Reading
        $readEnter = $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['reading']['id']}/enter")
            ->assertOk()
            ->json('data');
        $readingAttemptId = $readEnter['navigation']['attempt_id'];
        TestAttempt::query()->whereKey($readingAttemptId)->update([
            'status' => TestAttempt::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        TestResult::query()->create([
            'test_attempt_id' => $readingAttemptId,
            'raw_score' => 2,
            'max_score' => 3,
            'band_score' => 6.5,
        ]);
        $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['reading']['id']}/complete")
            ->assertOk();

        // Writing via context_key
        $writeEnter = $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['writing']['id']}/enter")
            ->assertOk()
            ->json('data');
        $writeCtx = $writeEnter['navigation']['context_key'];
        $mockBody = implode(' ', array_fill(0, 55, 'examword'));
        $this->withToken($token)
            ->putJson("/api/v1/writing-tasks/{$writing->id}/submission?context_key={$writeCtx}", [
                'body' => $mockBody,
                'context_key' => $writeCtx,
            ])
            ->assertOk();
        $this->withToken($token)
            ->postJson("/api/v1/writing-tasks/{$writing->id}/submission/submit", [
                'body' => $mockBody,
                'context_key' => $writeCtx,
            ])
            ->assertOk();

        $practice = WritingSubmission::query()
            ->where('user_id', $student->id)
            ->where('writing_task_id', $writing->id)
            ->where('context_key', 'practice')
            ->firstOrFail();
        $examSubmission = WritingSubmission::query()
            ->where('user_id', $student->id)
            ->where('writing_task_id', $writing->id)
            ->where('context_key', $writeCtx)
            ->firstOrFail();
        $this->assertNotSame($practice->id, $examSubmission->id);
        $this->assertStringContainsString('practice', (string) $practice->body);
        $this->assertStringContainsString('examword', (string) $examSubmission->body);

        FullMockSessionSection::query()->whereKey($bySkill['writing']['id'])->update([
            'writing_submission_id' => $examSubmission->id,
        ]);
        $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['writing']['id']}/complete")
            ->assertOk();

        // Speaking
        $speakEnter = $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['speaking']['id']}/enter")
            ->assertOk()
            ->json('data');
        $speakCtx = $speakEnter['navigation']['context_key'];
        $file = UploadedFile::fake()->create('answer.webm', 20, 'audio/webm');
        $this->withToken($token)
            ->post("/api/v1/speaking-tasks/{$speaking->id}/recording", [
                'recording' => $file,
                'duration_seconds' => 30,
                'context_key' => $speakCtx,
            ])
            ->assertCreated();
        $this->withToken($token)
            ->postJson("/api/v1/speaking-tasks/{$speaking->id}/recording/submit", [
                'context_key' => $speakCtx,
            ])
            ->assertOk();

        $recording = SpeakingRecording::query()
            ->where('user_id', $student->id)
            ->where('speaking_task_id', $speaking->id)
            ->where('context_key', $speakCtx)
            ->firstOrFail();
        FullMockSessionSection::query()->whereKey($bySkill['speaking']['id'])->update([
            'speaking_recording_id' => $recording->id,
        ]);
        $finished = $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$bySkill['speaking']['id']}/complete")
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $finished['session']['status']);
        $this->assertTrue($finished['scorecard']['overall_pending']);
        $this->assertNull($finished['scorecard']['overall_band']);
        $this->assertSame('pending', $finished['scorecard']['skills']['writing']['status']);
        $this->assertSame('pending', $finished['scorecard']['skills']['speaking']['status']);
        $this->assertEqualsWithDelta(7.0, (float) $finished['scorecard']['skills']['listening']['band_score'], 0.01);
        $this->assertEqualsWithDelta(6.5, (float) $finished['scorecard']['skills']['reading']['band_score'], 0.01);

        // Teacher evaluates full-mock writing + speaking
        $teacher = $this->userWithRole('teacher');
        $teacherToken = $teacher->createToken('api')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($teacherToken)
            ->postJson("/api/v1/teacher/writing-submissions/{$examSubmission->id}/evaluate", [
                'task_response' => 6.5,
                'coherence' => 6.5,
                'lexical' => 6.5,
                'grammar' => 6.5,
                'overall_band' => 6.5,
                'feedback' => 'Full mock writing OK',
            ])
            ->assertSuccessful();

        $this->withToken($teacherToken)
            ->postJson("/api/v1/teacher/speaking-recordings/{$recording->id}/evaluate", [
                'fluency' => 6.0,
                'lexical' => 6.0,
                'grammar' => 6.0,
                'pronunciation' => 6.0,
                'overall_band' => 6.0,
                'feedback' => 'Full mock speaking OK',
            ])
            ->assertSuccessful();

        Auth::forgetGuards();
        $scored = $this->withToken($token)
            ->getJson("/api/v1/full-mock-sessions/{$uuid}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($scored['scorecard']['overall_pending']);
        $this->assertEqualsWithDelta(
            (float) IeltsOverallBand::fromSkillBands([7.0, 6.5, 6.5, 6.0]),
            (float) $scored['scorecard']['overall_band'],
            0.01,
        );
        $this->assertSame(1, WritingEvaluation::query()->count());
    }

    public function test_reading_band_conversion_is_scoped_by_academic_vs_general(): void
    {
        BandScoreConversion::query()->updateOrCreate(
            ['module' => 'reading', 'exam_type' => 'academic', 'raw_score' => 30],
            ['band_score' => 7.0],
        );
        BandScoreConversion::query()->updateOrCreate(
            ['module' => 'reading', 'exam_type' => 'general', 'raw_score' => 30],
            ['band_score' => 6.0],
        );

        $academic = BandScoreConversion::query()
            ->where('module', 'reading')
            ->where('exam_type', 'academic')
            ->where('raw_score', 30)
            ->value('band_score');
        $general = BandScoreConversion::query()
            ->where('module', 'reading')
            ->where('exam_type', 'general')
            ->where('raw_score', 30)
            ->value('band_score');

        $this->assertSame('7.0', (string) $academic);
        $this->assertSame('6.0', (string) $general);
        $this->assertNotSame((float) $academic, (float) $general);
    }

    /**
     * @return array{0: MockTest, 1: MockTest, 2: WritingTask, 3: SpeakingTask}
     */
    private function seedSkillContent(): array
    {
        $listening = MockTest::query()->create([
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => 'academic',
            'title' => 'Hardening Listening',
            'slug' => 'hardening-listening',
            'duration_seconds' => 1200,
            'is_published' => true,
            'is_free_sample' => true,
        ]);
        $reading = MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Hardening Reading',
            'slug' => 'hardening-reading',
            'duration_seconds' => 1200,
            'is_published' => true,
            'is_free_sample' => true,
        ]);
        $writing = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Hardening Writing',
            'prompt' => 'Discuss both views.',
            'recommended_minutes' => 40,
            'min_words' => 50,
            'is_published' => true,
        ]);
        $speaking = SpeakingTask::query()->create([
            'part' => 2,
            'title' => 'Hardening Speaking',
            'instructions' => 'Describe the topic.',
            'preparation_seconds' => 60,
            'response_seconds' => 120,
            'is_published' => true,
        ]);

        return [$listening, $reading, $writing, $speaking];
    }

    private function makeFullMock(MockTest $listening, MockTest $reading, WritingTask $writing, SpeakingTask $speaking): FullMockExam
    {
        $exam = FullMockExam::query()->create([
            'exam_type' => 'academic',
            'title' => 'Hardening Full Mock',
            'slug' => 'hardening-full-mock',
            'instructions' => 'Four skills',
            'is_published' => true,
            'is_free' => true,
            'allow_resume' => true,
        ]);

        foreach ([
            [FullMockExamSection::SKILL_LISTENING, 'Listening', $listening->id, null, null, 900],
            [FullMockExamSection::SKILL_READING, 'Reading', $reading->id, null, null, 900],
            [FullMockExamSection::SKILL_WRITING, 'Writing', null, $writing->id, null, 1200],
            [FullMockExamSection::SKILL_SPEAKING, 'Speaking', null, null, $speaking->id, 300],
        ] as $index => $row) {
            FullMockExamSection::query()->create([
                'full_mock_exam_id' => $exam->id,
                'skill' => $row[0],
                'title' => $row[1],
                'position' => $index + 1,
                'duration_seconds' => $row[5],
                'mock_test_id' => $row[2],
                'writing_task_id' => $row[3],
                'speaking_task_id' => $row[4],
            ]);
        }

        return $exam->fresh('sections');
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $slug)->value('id')]);

        return $user;
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function tokenWithPermissions(array $permissionSlugs): string
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Mock Manager '.Str::random(4),
            'slug' => 'mock-manager-'.Str::lower(Str::random(6)),
        ]);
        $permissionIds = Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        return $user->createToken('api')->plainTextToken;
    }

    private function enroll(User $student): void
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'full-mock-hardening'],
            ['name' => 'Full Mock Hardening'],
        );
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Hardening Course',
            'slug' => 'hardening-course-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 0,
            'is_published' => true,
        ]);
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => Enrollment::ACTIVE,
            'enrolled_at' => now(),
        ]);
    }
}
