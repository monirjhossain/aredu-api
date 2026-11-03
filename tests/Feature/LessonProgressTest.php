<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LessonProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'services.cloudflare.account_id' => 'account-1',
            'services.cloudflare.stream_token' => 'cf-token',
            'services.cloudflare.customer_code' => 'demo123',
            'services.cloudflare.playback_ttl_seconds' => 900,
        ]);
    }

    public function test_guest_and_unenrolled_student_cannot_update_progress(): void
    {
        [$course, $lesson] = $this->courseWithLessons(1);

        $this->putJson('/api/v1/lessons/'.$lesson->id.'/progress', [
            'position_seconds' => 30,
        ])->assertUnauthorized();

        $student = $this->userWithRole('student');

        $this->withToken($student->createToken('api')->plainTextToken)
            ->putJson('/api/v1/lessons/'.$lesson->id.'/progress', [
                'position_seconds' => 30,
            ])
            ->assertForbidden();

        $this->withToken($student->createToken('api')->plainTextToken)
            ->postJson('/api/v1/lessons/'.$lesson->id.'/progress/complete')
            ->assertForbidden();
    }

    public function test_student_cannot_update_another_students_progress_row(): void
    {
        [$course, $lesson] = $this->courseWithLessons(1);
        $owner = $this->userWithRole('student');
        $intruder = $this->userWithRole('student');

        Enrollment::query()->create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        Enrollment::query()->create([
            'user_id' => $intruder->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        LessonProgress::query()->create([
            'user_id' => $owner->id,
            'lesson_id' => $lesson->id,
            'position_seconds' => 50,
            'completed_at' => now(),
        ]);

        $this->withToken($intruder->createToken('api')->plainTextToken)
            ->putJson('/api/v1/lessons/'.$lesson->id.'/progress', [
                'position_seconds' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('data.progress.position_seconds', 10)
            ->assertJsonPath('data.progress.completed_at', null);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $owner->id,
            'lesson_id' => $lesson->id,
            'position_seconds' => 50,
        ]);
        $this->assertNotNull(
            LessonProgress::query()->where('user_id', $owner->id)->where('lesson_id', $lesson->id)->value('completed_at')
        );
    }

    public function test_position_updates_are_throttled_and_resume_is_returned(): void
    {
        [$course, $lesson] = $this->courseWithLessons(1);
        $student = $this->userWithRole('student');
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Video::query()->create([
            'lesson_id' => $lesson->id,
            'cloudflare_uid' => 'uid-progress-1',
            'status' => Video::READY,
            'duration_seconds' => 600,
        ]);

        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/lessons/'.$lesson->id.'/progress', ['position_seconds' => 20])
            ->assertOk()
            ->assertJsonPath('data.saved', true)
            ->assertJsonPath('data.progress.position_seconds', 20);

        $this->withToken($token)
            ->putJson('/api/v1/lessons/'.$lesson->id.'/progress', ['position_seconds' => 25])
            ->assertOk()
            ->assertJsonPath('data.saved', false)
            ->assertJsonPath('data.progress.position_seconds', 20);

        $this->withToken($token)
            ->putJson('/api/v1/lessons/'.$lesson->id.'/progress', ['position_seconds' => 40])
            ->assertOk()
            ->assertJsonPath('data.saved', true)
            ->assertJsonPath('data.progress.position_seconds', 40);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['token' => 'play-token'],
            ]),
        ]);

        $playback = $this->withToken($token)
            ->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertOk()
            ->assertJsonPath('data.progress.position_seconds', 40);

        $this->assertStringContainsString('startTime=40', $playback->json('data.playback.iframe_url'));
    }

    public function test_completing_all_published_lessons_issues_one_certificate(): void
    {
        [$course, $lessons] = $this->courseWithLessons(2, true);
        $student = $this->userWithRole('student');
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/lessons/'.$lessons[0]->id.'/progress/complete')
            ->assertOk()
            ->assertJsonPath('data.course_progress.completed_lessons', 1)
            ->assertJsonPath('data.course_progress.percent', 50)
            ->assertJsonPath('data.certificate', null);

        $complete = $this->withToken($token)
            ->postJson('/api/v1/lessons/'.$lessons[1]->id.'/progress/complete')
            ->assertOk()
            ->assertJsonPath('data.course_progress.completed_lessons', 2)
            ->assertJsonPath('data.course_progress.percent', 100);

        $this->assertNotEmpty($complete->json('data.certificate.certificate_number'));

        $this->assertSame(1, Certificate::query()->where('user_id', $student->id)->where('course_id', $course->id)->count());

        $this->withToken($token)
            ->postJson('/api/v1/lessons/'.$lessons[1]->id.'/progress/complete')
            ->assertOk();

        $this->assertSame(1, Certificate::query()->count());

        $learning = $this->withToken($token)
            ->getJson('/api/v1/student/courses/'.$course->slug)
            ->assertOk()
            ->assertJsonPath('data.course_progress.percent', 100)
            ->assertJsonPath('data.sections.0.lessons.0.is_completed', true)
            ->assertJsonPath('data.sections.0.lessons.1.is_completed', true);

        $this->assertNotNull($learning->json('data.certificate.certificate_number'));
    }

    public function test_refunded_enrollment_cannot_track_progress(): void
    {
        [$course, $lesson] = $this->courseWithLessons(1);
        $student = $this->userWithRole('student');
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'refunded',
            'enrolled_at' => now(),
        ]);

        $this->withToken($student->createToken('api')->plainTextToken)
            ->putJson('/api/v1/lessons/'.$lesson->id.'/progress', ['position_seconds' => 15])
            ->assertForbidden();
    }

    /**
     * @return array{0: Course, 1: Lesson|list<Lesson>}
     */
    private function courseWithLessons(int $count, bool $asList = false): array
    {
        $category = CourseCategory::query()->create([
            'name' => 'Academic',
            'slug' => 'academic-'.uniqid(),
        ]);
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Progress Course',
            'slug' => 'progress-course-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 1000,
            'is_published' => true,
        ]);
        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'position' => 1,
        ]);

        $lessons = [];
        for ($i = 1; $i <= $count; $i++) {
            $lessons[] = Lesson::query()->create([
                'course_section_id' => $section->id,
                'title' => 'Lesson '.$i,
                'position' => $i,
                'is_published' => true,
                'duration_seconds' => 300,
            ]);
        }

        Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Draft',
            'position' => $count + 1,
            'is_published' => false,
        ]);

        return [$course, $asList || $count > 1 ? $lessons : $lessons[0]];
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
