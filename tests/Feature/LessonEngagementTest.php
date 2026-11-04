<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonFeedback;
use App\Models\LessonReaction;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_student_can_like_toggle_and_submit_feedback(): void
    {
        [$student, $lesson] = $this->enrolledLesson();
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/lessons/'.$lesson->id.'/reaction', ['type' => 'like'])
            ->assertOk()
            ->assertJsonPath('data.likes', 1)
            ->assertJsonPath('data.my_reaction', 'like');

        $this->assertSame(1, LessonReaction::query()->count());

        $this->withToken($token)
            ->postJson('/api/v1/lessons/'.$lesson->id.'/reaction', ['type' => 'like'])
            ->assertOk()
            ->assertJsonPath('data.likes', 0)
            ->assertJsonPath('data.my_reaction', null);

        $this->withToken($token)
            ->postJson('/api/v1/lessons/'.$lesson->id.'/reaction', ['type' => 'dislike'])
            ->assertOk()
            ->assertJsonPath('data.dislikes', 1)
            ->assertJsonPath('data.my_reaction', 'dislike');

        $this->withToken($token)
            ->postJson('/api/v1/lessons/'.$lesson->id.'/feedback', [
                'body' => 'Please add more examples in this lesson.',
            ])
            ->assertOk()
            ->assertJsonPath('data.engagement.feedback_count', 1);

        $this->assertSame(1, LessonFeedback::query()->count());
    }

    /**
     * @return array{0: User, 1: Lesson}
     */
    private function enrolledLesson(): array
    {
        $student = User::factory()->create();
        $student->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic'],
        );

        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Band Path',
            'slug' => 'band-path',
            'exam_type' => 'academic',
            'price' => 1000,
            'is_published' => true,
        ]);

        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'position' => 1,
        ]);

        $lesson = Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Class 1',
            'position' => 1,
            'duration_seconds' => 600,
            'is_free_preview' => false,
            'is_published' => true,
        ]);

        Video::query()->create([
            'lesson_id' => $lesson->id,
            'provider' => 'youtube',
            'external_id' => 'abc123',
            'embed_url' => 'https://www.youtube.com/embed/abc123',
            'status' => Video::READY,
            'duration_seconds' => 600,
        ]);

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        return [$student, $lesson];
    }
}
