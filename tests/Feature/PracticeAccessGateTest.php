<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\FullMockExam;
use App\Models\MockTest;
use App\Models\Role;
use App\Models\SpeakingTask;
use App\Models\User;
use App\Models\WritingTask;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeAccessGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_unenrolled_student_only_sees_free_reading_samples(): void
    {
        MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Free Sample',
            'slug' => 'free-sample-r',
            'duration_seconds' => 1200,
            'is_published' => true,
            'is_free_sample' => true,
        ]);
        MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Full Practice',
            'slug' => 'full-practice-r',
            'duration_seconds' => 1200,
            'is_published' => true,
            'is_free_sample' => false,
        ]);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/reading-tests')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.practice_access.full_unlocked', false)
            ->assertJsonPath('data.0.slug', 'free-sample-r');

        $this->withToken($token)
            ->getJson('/api/v1/reading-tests/full-practice-r')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/v1/reading-tests/full-practice-r/attempts')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/v1/reading-tests/free-sample-r/attempts')
            ->assertCreated();
    }

    public function test_enrolled_student_sees_all_published_reading_tests(): void
    {
        MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Free Sample',
            'slug' => 'free-sample-r2',
            'duration_seconds' => 1200,
            'is_published' => true,
            'is_free_sample' => true,
        ]);
        MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Full Practice',
            'slug' => 'full-practice-r2',
            'duration_seconds' => 1200,
            'is_published' => true,
            'is_free_sample' => false,
        ]);

        $student = $this->student();
        $this->enroll($student);
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/reading-tests')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.practice_access.full_unlocked', true);

        $this->withToken($token)
            ->postJson('/api/v1/reading-tests/full-practice-r2/attempts')
            ->assertCreated();
    }

    public function test_unenrolled_student_only_sees_free_listening_samples(): void
    {
        MockTest::query()->create([
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => 'academic',
            'title' => 'Free Listening',
            'slug' => 'free-sample-l',
            'duration_seconds' => 2400,
            'is_published' => true,
            'is_free_sample' => true,
        ]);
        MockTest::query()->create([
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => 'academic',
            'title' => 'Full Listening',
            'slug' => 'full-practice-l',
            'duration_seconds' => 2400,
            'is_published' => true,
            'is_free_sample' => false,
        ]);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/listening-tests')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.practice_access.full_unlocked', false)
            ->assertJsonPath('data.0.slug', 'free-sample-l');

        $this->withToken($token)
            ->getJson('/api/v1/listening-tests/full-practice-l')
            ->assertForbidden();
    }

    public function test_writing_requires_enrollment(): void
    {
        WritingTask::query()->create([
            'title' => 'Task Locked',
            'exam_type' => 'academic',
            'task_type' => 'task_2',
            'prompt' => 'Write an essay.',
            'min_words' => 50,
            'recommended_minutes' => 40,
            'is_published' => true,
        ]);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/writing-tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.practice_access.full_unlocked', false);

        $this->enroll($student);

        $this->withToken($token)
            ->getJson('/api/v1/writing-tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.practice_access.full_unlocked', true);
    }

    public function test_speaking_requires_enrollment(): void
    {
        SpeakingTask::query()->create([
            'title' => 'Speaking Locked',
            'part' => SpeakingTask::PART_1,
            'instructions' => 'Answer the questions.',
            'preparation_seconds' => 0,
            'response_seconds' => 60,
            'is_published' => true,
        ]);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/speaking-tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.practice_access.full_unlocked', false);

        $this->enroll($student);

        $this->withToken($token)
            ->getJson('/api/v1/speaking-tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.practice_access.full_unlocked', true);
    }

    public function test_unenrolled_student_only_sees_free_full_mocks(): void
    {
        $reading = MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'FM Reading',
            'slug' => 'fm-gate-reading',
            'duration_seconds' => 1200,
            'is_published' => true,
            'is_free_sample' => true,
        ]);

        $free = FullMockExam::query()->create([
            'exam_type' => FullMockExam::TYPE_ACADEMIC,
            'title' => 'Free Full Mock',
            'slug' => 'free-full-mock',
            'instructions' => 'Free demo.',
            'is_free' => true,
            'allow_resume' => true,
            'is_published' => true,
        ]);
        $free->sections()->create([
            'skill' => 'reading',
            'title' => 'Reading',
            'position' => 1,
            'duration_seconds' => 1200,
            'mock_test_id' => $reading->id,
        ]);

        $paid = FullMockExam::query()->create([
            'exam_type' => FullMockExam::TYPE_ACADEMIC,
            'title' => 'Paid Full Mock',
            'slug' => 'paid-full-mock-gate',
            'instructions' => 'Enrolled only.',
            'is_free' => false,
            'allow_resume' => true,
            'is_published' => true,
        ]);
        $paid->sections()->create([
            'skill' => 'reading',
            'title' => 'Reading',
            'position' => 1,
            'duration_seconds' => 1200,
            'mock_test_id' => $reading->id,
        ]);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/full-mocks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.practice_access.full_unlocked', false)
            ->assertJsonPath('data.0.slug', 'free-full-mock');

        $this->enroll($student);

        $this->withToken($token)
            ->getJson('/api/v1/full-mocks')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.practice_access.full_unlocked', true);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }

    private function enroll(User $student): void
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'practice-access'],
            ['name' => 'Practice Access'],
        );
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Access Course',
            'slug' => 'access-'.uniqid(),
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
