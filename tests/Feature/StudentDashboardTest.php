<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\MockTest;
use App\Models\Role;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\TestAttempt;
use App\Models\TestResult;
use App\Models\User;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_guest_cannot_view_student_dashboard(): void
    {
        $this->getJson('/api/v1/student/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_shows_only_authenticated_student_data(): void
    {
        $student = $this->userWithRole('student');
        $other = $this->userWithRole('student');

        $category = CourseCategory::query()->create([
            'name' => 'Academic',
            'slug' => 'academic',
        ]);
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Band 7 Prep',
            'slug' => 'band-7-prep',
            'exam_type' => 'academic',
            'price' => 2000,
            'is_published' => true,
        ]);
        $otherCourse = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Other Course',
            'slug' => 'other-course',
            'exam_type' => 'general',
            'price' => 1000,
            'is_published' => true,
        ]);

        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'Intro',
            'position' => 1,
        ]);
        $lessonA = Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Lesson A',
            'position' => 1,
            'is_published' => true,
        ]);
        $lessonB = Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Lesson B',
            'position' => 2,
            'is_published' => true,
        ]);
        Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Draft lesson',
            'position' => 3,
            'is_published' => false,
        ]);

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now()->subDay(),
        ]);
        Enrollment::query()->create([
            'user_id' => $other->id,
            'course_id' => $otherCourse->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $otherCourse->id,
            'status' => 'refunded',
            'enrolled_at' => now()->subDays(2),
        ]);

        LessonProgress::query()->create([
            'user_id' => $student->id,
            'lesson_id' => $lessonA->id,
            'position_seconds' => 120,
            'completed_at' => now()->subHour(),
        ]);
        LessonProgress::query()->create([
            'user_id' => $student->id,
            'lesson_id' => $lessonB->id,
            'position_seconds' => 40,
            'completed_at' => null,
        ]);
        LessonProgress::query()->create([
            'user_id' => $other->id,
            'lesson_id' => $lessonA->id,
            'position_seconds' => 10,
            'completed_at' => now(),
        ]);

        $mock = MockTest::query()->create([
            'module' => 'reading',
            'exam_type' => 'academic',
            'title' => 'Reading Mock 1',
            'slug' => 'reading-mock-1',
            'duration_seconds' => 3600,
            'is_published' => true,
        ]);
        $ownAttempt = TestAttempt::query()->create([
            'mock_test_id' => $mock->id,
            'user_id' => $student->id,
            'started_at' => now()->subHours(2),
            'must_submit_at' => now()->subHour(),
            'submitted_at' => now()->subHour(),
            'status' => 'submitted',
        ]);
        TestResult::query()->create([
            'test_attempt_id' => $ownAttempt->id,
            'raw_score' => 30,
            'max_score' => 40,
            'band_score' => 7.0,
        ]);
        $otherAttempt = TestAttempt::query()->create([
            'mock_test_id' => $mock->id,
            'user_id' => $other->id,
            'started_at' => now()->subHours(3),
            'must_submit_at' => now()->subHours(2),
            'submitted_at' => now()->subHours(2),
            'status' => 'submitted',
        ]);
        TestResult::query()->create([
            'test_attempt_id' => $otherAttempt->id,
            'raw_score' => 20,
            'max_score' => 40,
            'band_score' => 5.5,
        ]);

        $writingTask = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => 'task_2',
            'title' => 'Essay task',
            'prompt' => 'Discuss both views.',
        ]);
        WritingSubmission::query()->create([
            'writing_task_id' => $writingTask->id,
            'user_id' => $student->id,
            'body' => 'My essay',
            'word_count' => 250,
            'status' => 'submitted',
            'submitted_at' => now()->subMinutes(30),
        ]);
        WritingSubmission::query()->create([
            'writing_task_id' => $writingTask->id,
            'user_id' => $other->id,
            'body' => 'Other essay',
            'word_count' => 260,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $speakingTask = SpeakingTask::query()->create([
            'part' => 1,
            'title' => 'Part 1 intro',
        ]);
        SpeakingRecording::query()->create([
            'speaking_task_id' => $speakingTask->id,
            'user_id' => $student->id,
            'disk' => 'local',
            'path' => 'speaking/student.webm',
            'mime' => 'audio/webm',
            'size_bytes' => 1200,
            'status' => 'submitted',
            'submitted_at' => now()->subMinutes(10),
        ]);
        SpeakingRecording::query()->create([
            'speaking_task_id' => $speakingTask->id,
            'user_id' => $other->id,
            'disk' => 'local',
            'path' => 'speaking/other.webm',
            'mime' => 'audio/webm',
            'size_bytes' => 900,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $student->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toArray(object $notifiable): array
            {
                return ['message' => 'Welcome student'];
            }
        });
        $other->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toArray(object $notifiable): array
            {
                return ['message' => 'Other only'];
            }
        });

        $response = $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.enrolled_courses.0.course.slug', 'band-7-prep')
            ->assertJsonPath('data.enrolled_courses.0.progress.published_lessons', 2)
            ->assertJsonPath('data.enrolled_courses.0.progress.completed_lessons', 1)
            ->assertJsonPath('data.enrolled_courses.0.progress.percent', 50)
            ->assertJsonPath('data.pending_writing.total', 1)
            ->assertJsonPath('data.pending_speaking.total', 1)
            ->assertJsonPath('data.recent_mock_results.0.result.band_score', '7.0')
            ->assertJsonPath('data.notifications.0.data.message', 'Welcome student');

        $payload = $response->json('data');
        $this->assertCount(1, $payload['enrolled_courses']);
        $this->assertCount(2, $payload['recent_lessons']);
        $this->assertCount(1, $payload['recent_mock_results']);
        $this->assertCount(1, $payload['notifications']);
        $this->assertSame('Lesson B', $payload['recent_lessons'][0]['title']);
        $this->assertFalse(Str::contains(json_encode($payload), 'Other only'));
        $this->assertFalse(Str::contains(json_encode($payload), 'other-course'));
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
