<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CourseContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_content_changes_require_lesson_permission_on_that_course(): void
    {
        $course = $this->course('Writing', 'writing');

        $this->getJson('/api/v1/admin/courses/'.$course->id.'/sections')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->getJson('/api/v1/admin/courses/'.$course->id.'/sections')
            ->assertForbidden();

        Auth::forgetGuards();

        $unassigned = $this->userWithRole('teacher');
        $this->withToken($unassigned->createToken('api')->plainTextToken)
            ->postJson($this->sections($course), ['title' => 'Blocked'])
            ->assertForbidden();

        Auth::forgetGuards();

        $viewer = $this->userWithRole('teacher');
        $course->teachers()->attach($viewer->id, ['can_manage_content' => false]);
        $this->withToken($viewer->createToken('api')->plainTextToken)
            ->postJson($this->sections($course), ['title' => 'Blocked'])
            ->assertForbidden();

        $this->assertSame(0, CourseSection::query()->count());

        Auth::forgetGuards();

        $editor = $this->userWithRole('teacher');
        $course->teachers()->attach($editor->id, ['can_manage_content' => true]);
        $token = $editor->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson($this->sections($course), ['title' => 'Assigned'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Assigned')
            ->assertJsonPath('data.position', 1);

        Auth::forgetGuards();

        $this->withToken($token)
            ->putJson('/api/v1/admin/courses/'.$course->id, ['title' => 'Hijack'])
            ->assertForbidden();

        $this->assertSame('Writing', $course->fresh()->title);
    }

    public function test_admin_can_order_sections_and_lessons(): void
    {
        $token = $this->tokenFor('super_admin');
        $course = $this->course('Writing', 'writing');
        $other = $this->course('Other', 'other');

        $this->withToken($token)->postJson($this->sections($course), [])->assertStatus(422);

        $first = $this->withToken($token)->postJson($this->sections($course), [
            'title' => 'Introduction',
        ])->assertCreated()->assertJsonPath('data.position', 1);

        $second = $this->withToken($token)->postJson($this->sections($course), [
            'title' => 'Practice',
        ])->assertCreated()->assertJsonPath('data.position', 2);

        $firstId = $first->json('data.id');
        $secondId = $second->json('data.id');

        $this->withToken($token)->putJson($this->sections($course).'/order', [
            'section_ids' => [$secondId, $firstId],
        ])->assertOk()
            ->assertJsonPath('data.course.title', 'Writing')
            ->assertJsonPath('data.sections.0.id', $secondId)
            ->assertJsonPath('data.sections.0.position', 1)
            ->assertJsonPath('data.sections.1.id', $firstId)
            ->assertJsonPath('data.sections.1.position', 2);

        $this->withToken($token)->putJson($this->sections($course).'/order', [
            'section_ids' => [$firstId],
        ])->assertStatus(422);

        $this->assertSame(1, CourseSection::query()->find($secondId)->position);

        $welcome = $this->withToken($token)->postJson($this->lessons($course, $secondId), [
            'title' => 'Welcome',
            'position' => 50,
            'duration_seconds' => 300,
            'is_free_preview' => true,
            'is_published' => false,
        ])->assertCreated()
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.duration_seconds', 300)
            ->assertJsonPath('data.is_free_preview', true)
            ->assertJsonPath('data.is_published', false);

        $review = $this->withToken($token)->postJson($this->lessons($course, $secondId), [
            'title' => 'Review',
            'duration_seconds' => 0,
            'is_free_preview' => false,
            'is_published' => true,
        ])->assertCreated()->assertJsonPath('data.position', 2);

        $welcomeId = $welcome->json('data.id');
        $reviewId = $review->json('data.id');

        $this->withToken($token)->putJson($this->lessons($course, $secondId).'/order', [
            'lesson_ids' => [$reviewId, $welcomeId],
        ])->assertOk()
            ->assertJsonPath('data.sections.0.lessons.0.id', $reviewId)
            ->assertJsonPath('data.sections.0.lessons.0.position', 1)
            ->assertJsonPath('data.sections.0.lessons.1.id', $welcomeId);

        $this->withToken($token)->putJson($this->lessons($course, $secondId).'/'.$welcomeId, [
            'title' => 'Welcome back',
            'duration_seconds' => 90,
            'is_free_preview' => false,
            'is_published' => true,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Welcome back')
            ->assertJsonPath('data.duration_seconds', 90)
            ->assertJsonPath('data.position', 2);

        $this->withToken($token)->postJson($this->lessons($course, $secondId), [
            'title' => 'Too long',
            'duration_seconds' => 86401,
            'is_free_preview' => false,
            'is_published' => false,
        ])->assertStatus(422);

        $foreign = CourseSection::query()->create([
            'course_id' => $other->id,
            'title' => 'Elsewhere',
            'position' => 1,
        ]);
        $foreignLesson = Lesson::query()->create([
            'course_section_id' => $foreign->id,
            'title' => 'Other lesson',
            'position' => 1,
        ]);

        $this->withToken($token)->putJson($this->sections($course).'/'.$foreign->id, [
            'title' => 'Moved',
        ])->assertNotFound();

        $this->withToken($token)->putJson($this->lessons($course, $secondId).'/'.$foreignLesson->id, [
            'title' => 'Moved',
            'duration_seconds' => 10,
            'is_free_preview' => false,
            'is_published' => false,
        ])->assertNotFound();

        $this->withToken($token)->putJson($this->lessons($course, $secondId).'/order', [
            'lesson_ids' => [$foreignLesson->id, $welcomeId],
        ])->assertStatus(422);

        $this->assertSame(1, Lesson::query()->find($reviewId)->position);
        $this->assertSame('Other lesson', $foreignLesson->fresh()->title);

        $this->withToken($token)->deleteJson($this->lessons($course, $secondId).'/'.$reviewId)->assertOk();
        $this->assertNotNull(Lesson::withTrashed()->find($reviewId)?->deleted_at);
        $this->assertSame(1, Lesson::query()->find($welcomeId)->position);

        $this->withToken($token)->deleteJson($this->sections($course).'/'.$secondId)->assertOk();
        $this->assertNotNull(CourseSection::withTrashed()->find($secondId)?->deleted_at);
        $this->assertNotNull(Lesson::withTrashed()->find($welcomeId)?->deleted_at);
        $this->assertSame(1, CourseSection::query()->find($firstId)->position);

        $this->withToken($token)->getJson($this->sections($course))
            ->assertOk()
            ->assertJsonPath('data.sections.0.id', $firstId)
            ->assertJsonPath('data.sections.0.position', 1)
            ->assertJsonCount(1, 'data.sections');
    }

    private function sections(Course $course): string
    {
        return '/api/v1/admin/courses/'.$course->id.'/sections';
    }

    private function lessons(Course $course, int $sectionId): string
    {
        return $this->sections($course).'/'.$sectionId.'/lessons';
    }

    private function course(string $title, string $slug): Course
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );

        return Course::query()->create([
            'category_id' => $category->id,
            'title' => $title,
            'slug' => $slug,
            'exam_type' => 'academic',
            'price' => 1000,
            'duration_minutes' => 60,
            'is_published' => false,
        ]);
    }

    private function tokenFor(string $roleSlug): string
    {
        return $this->userWithRole($roleSlug)->createToken('api')->plainTextToken;
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user;
    }
}
