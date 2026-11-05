<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\FullMockExam;
use App\Models\FullMockSession;
use App\Models\MockTest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class FullMockExamOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_configure_and_student_can_start_resume_and_enter_reading_section(): void
    {
        $admin = $this->userWithRole('super_admin');
        $adminToken = $admin->createToken('api')->plainTextToken;

        $reading = MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Academic Reading Pack',
            'slug' => 'academic-reading-pack',
            'duration_seconds' => 3600,
            'is_published' => true,
            'is_free_sample' => true,
        ]);
        $listening = MockTest::query()->create([
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => 'academic',
            'title' => 'Academic Listening Pack',
            'slug' => 'academic-listening-pack',
            'duration_seconds' => 2400,
            'is_published' => true,
            'is_free_sample' => true,
        ]);

        $create = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/full-mocks', [
                'exam_type' => 'academic',
                'title' => 'Academic Full Mock A',
                'slug' => 'academic-full-mock-a',
                'instructions' => 'Complete all sections in order.',
                'is_free' => true,
                'allow_resume' => true,
            ])
            ->assertCreated()
            ->json('data');

        $examId = $create['exam']['id'];
        $this->assertNotNull($examId);

        $this->withToken($adminToken)
            ->putJson("/api/v1/admin/full-mocks/{$examId}/sections", [
                'sections' => [
                    [
                        'skill' => 'listening',
                        'title' => 'Listening',
                        'duration_seconds' => 1800,
                        'mock_test_id' => $listening->id,
                    ],
                    [
                        'skill' => 'reading',
                        'title' => 'Reading',
                        'duration_seconds' => 3600,
                        'mock_test_id' => $reading->id,
                    ],
                ],
            ])
            ->assertOk();

        $this->withToken($adminToken)
            ->putJson("/api/v1/admin/full-mocks/{$examId}/publication", ['is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.exam.is_published', true);

        unset($admin);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/full-mocks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'academic-full-mock-a');

        $start = $this->withToken($token)
            ->postJson('/api/v1/full-mocks/academic-full-mock-a/sessions')
            ->assertCreated()
            ->json('data');

        $uuid = $start['session']['uuid'];
        $this->assertSame('in_progress', $start['session']['status']);
        $this->assertCount(2, $start['sections']);
        $this->assertSame('ready', $start['sections'][0]['status']);
        $this->assertSame('locked', $start['sections'][1]['status']);

        $resume = $this->withToken($token)
            ->postJson('/api/v1/full-mocks/academic-full-mock-a/sessions')
            ->assertCreated()
            ->json('data');
        $this->assertSame($uuid, $resume['session']['uuid']);
        $session = FullMockSession::query()->where('uuid', $uuid)->first();
        $this->assertNotNull($session);
        $this->assertSame((int) $student->id, (int) $session->user_id);

        $sectionId = $start['sections'][0]['id'];
        $entered = $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/sections/{$sectionId}/enter")
            ->assertOk()
            ->json('data');

        $this->assertSame('listening', $entered['navigation']['skill']);
        $this->assertNotNull($entered['navigation']['attempt_id']);
        $this->assertStringStartsWith('fmss:', $entered['navigation']['context_key']);

        $this->withToken($token)
            ->getJson("/api/v1/listening-attempts/{$entered['navigation']['attempt_id']}")
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/full-mock-sessions/{$uuid}/heartbeat")
            ->assertOk();
    }

    public function test_paid_full_mock_requires_enrollment(): void
    {
        $admin = $this->userWithRole('super_admin');
        $reading = MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'general',
            'title' => 'GT Reading',
            'slug' => 'gt-reading',
            'duration_seconds' => 3600,
            'is_published' => true,
            'is_free_sample' => false,
        ]);

        $exam = FullMockExam::query()->create([
            'exam_type' => 'general',
            'title' => 'Paid Full Mock',
            'slug' => 'paid-full-mock',
            'instructions' => null,
            'is_published' => true,
            'is_free' => false,
            'allow_resume' => true,
        ]);
        $exam->sections()->create([
            'skill' => 'reading',
            'title' => 'Reading',
            'position' => 1,
            'duration_seconds' => 3600,
            'mock_test_id' => $reading->id,
        ]);

        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/full-mocks')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withToken($token)
            ->postJson('/api/v1/full-mocks/paid-full-mock/sessions')
            ->assertForbidden();

        $this->enroll($student);

        $this->withToken($token)
            ->getJson('/api/v1/full-mocks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)
            ->postJson('/api/v1/full-mocks/paid-full-mock/sessions')
            ->assertCreated();
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'student')->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $slug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function enroll(User $user): void
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'full-mock-access'],
            ['name' => 'Full Mock Access'],
        );
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Enrolled Course',
            'slug' => 'enrolled-fm-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 0,
            'is_published' => true,
        ]);
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => Enrollment::ACTIVE,
            'enrolled_at' => now(),
        ]);
    }
}
