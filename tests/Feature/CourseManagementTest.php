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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_students_and_teachers_cannot_manage_courses(): void
    {
        $category = $this->category();
        $course = $this->course($category, 'Locked', 'locked', false);

        $this->getJson('/api/v1/admin/courses')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->getJson('/api/v1/admin/courses/'.$course->id)
            ->assertForbidden();

        Auth::forgetGuards();

        $this->withToken($this->tokenFor('teacher'))
            ->putJson('/api/v1/admin/courses/'.$course->id.'/publication', ['is_published' => true])
            ->assertForbidden();

        $this->assertFalse($course->fresh()->is_published);
    }

    public function test_admin_can_manage_a_course_and_pricing_is_enforced(): void
    {
        $token = $this->tokenFor('super_admin');
        $category = $this->category();
        $removed = $this->category('Removed', 'removed');
        $removed->delete();

        $payload = $this->payload($category->id, 'Writing Course', 'writing-course');

        $this->withToken($token)->postJson('/api/v1/admin/courses', [
            ...$payload,
            'discount_price' => 1500,
        ])->assertStatus(422);

        $created = $this->withToken($token)->postJson('/api/v1/admin/courses', [
            ...$payload,
            'discount_price' => 1200,
            'is_published' => false,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'writing-course')
            ->assertJsonPath('data.price', '1500.00')
            ->assertJsonPath('data.discount_price', '1200.00')
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.category.name', 'Academic IELTS');

        $courseId = $created->json('data.id');

        $this->withToken($token)->postJson('/api/v1/admin/courses', [
            ...$this->payload($removed->id, 'Other', 'other-course'),
        ])->assertStatus(422);

        $this->withToken($token)->putJson('/api/v1/admin/courses/'.$courseId.'/publication', [
            'is_published' => true,
        ])->assertOk()->assertJsonPath('data.is_published', true);

        $this->withToken($token)->getJson('/api/v1/admin/courses?search=writing&exam_type=academic&is_published=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'writing-course');

        $this->withToken($token)->getJson('/api/v1/admin/courses?exam_type=general')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $section = CourseSection::query()->create([
            'course_id' => $courseId,
            'title' => 'Intro',
            'position' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Welcome',
            'position' => 1,
        ]);

        $this->withToken($token)->deleteJson('/api/v1/admin/courses/'.$courseId)->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/courses/'.$courseId)->assertNotFound();
        $this->assertNotNull(Course::withTrashed()->find($courseId)?->deleted_at);
        $this->assertNotNull(CourseSection::withTrashed()->find($section->id)?->deleted_at);
        $this->assertNotNull(Lesson::withTrashed()->find($lesson->id)?->deleted_at);
    }

    public function test_only_teachers_can_be_assigned_and_thumbnails_are_stored(): void
    {
        Storage::fake('public');

        $token = $this->tokenFor('super_admin');
        $category = $this->category();
        $course = $this->course($category, 'Speaking Course', 'speaking-course', false);
        $teacher = $this->userWithRole('teacher');
        $student = $this->userWithRole('student');

        $this->withToken($token)->putJson('/api/v1/admin/courses/'.$course->id.'/teachers', [
            'teachers' => [
                ['user_id' => $student->id, 'can_manage_content' => true],
            ],
        ])->assertStatus(422);

        $this->withToken($token)->putJson('/api/v1/admin/courses/'.$course->id.'/teachers', [
            'teachers' => [
                ['user_id' => $teacher->id, 'can_manage_content' => true],
            ],
        ])->assertOk()
            ->assertJsonPath('data.teachers.0.id', $teacher->id)
            ->assertJsonPath('data.teachers.0.can_manage_content', true);

        $this->withToken($token)->post('/api/v1/admin/courses/'.$course->id.'/thumbnail', [
            'thumbnail' => UploadedFile::fake()->image('cover.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.thumbnail_path', fn ($path) => is_string($path) && str_starts_with($path, 'course-thumbnails/'));

        Storage::disk('public')->assertExists(Course::query()->find($course->id)->thumbnail_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $categoryId, string $title, string $slug): array
    {
        return [
            'category_id' => $categoryId,
            'title' => $title,
            'slug' => $slug,
            'description' => 'Course description',
            'exam_type' => 'academic',
            'price' => 1500,
            'duration_minutes' => 600,
            'is_published' => false,
        ];
    }

    private function category(string $name = 'Academic IELTS', string $slug = 'academic'): CourseCategory
    {
        return CourseCategory::query()->create(['name' => $name, 'slug' => $slug]);
    }

    private function course(CourseCategory $category, string $title, string $slug, bool $published): Course
    {
        return Course::query()->create([
            'category_id' => $category->id,
            'title' => $title,
            'slug' => $slug,
            'exam_type' => 'academic',
            'price' => 1000,
            'duration_minutes' => 60,
            'is_published' => $published,
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
