<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PublicCourseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_public_catalog_lists_only_published_courses_and_lessons(): void
    {
        $academic = $this->category('Academic IELTS', 'academic');
        $general = $this->category('General Training', 'general-training');
        $removed = $this->category('Removed', 'removed');
        $removed->delete();

        $writing = $this->course($academic, 'IELTS Writing', 'ielts-writing', 'academic', true, 2000, 1500);
        $this->course($general, 'GT Speaking', 'gt-speaking', 'general', true, 1000, null);
        $this->course($academic, 'Hidden Draft', 'hidden-draft', 'academic', false, 500, null);

        $section = CourseSection::query()->create([
            'course_id' => $writing->id,
            'title' => 'Introduction',
            'position' => 1,
        ]);
        CourseSection::query()->create([
            'course_id' => $writing->id,
            'title' => 'Unpublished only',
            'position' => 2,
        ]);
        Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'How the course works',
            'position' => 1,
            'duration_seconds' => 300,
            'is_free_preview' => true,
            'is_published' => true,
        ]);
        Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Secret draft',
            'position' => 2,
            'duration_seconds' => 90,
            'is_free_preview' => false,
            'is_published' => false,
        ]);
        $teacher = User::factory()->create(['name' => 'Course Teacher', 'email' => 'teacher@areducation.test']);
        $writing->teachers()->attach($teacher->id, ['can_manage_content' => true]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Academic IELTS')
            ->assertJsonMissing(['slug' => 'removed']);

        $this->getJson('/api/v1/courses?category_id='.$removed->id)->assertStatus(422);

        $list = $this->getJson('/api/v1/courses?search=writing&exam_type=academic&category_id='.$academic->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('data.0.slug', 'ielts-writing')
            ->assertJsonPath('data.0.price', '2000.00')
            ->assertJsonPath('data.0.discount_price', '1500.00')
            ->assertJsonPath('data.0.is_enrolled', false)
            ->assertJsonPath('data.0.teachers.0.name', 'Course Teacher');

        $this->assertArrayNotHasKey('email', $list->json('data.0.teachers.0'));
        $this->getJson('/api/v1/courses?exam_type=general')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/courses?search=hidden')->assertOk()->assertJsonPath('meta.total', 0);

        $detail = $this->getJson('/api/v1/courses/ielts-writing')
            ->assertOk()
            ->assertJsonPath('data.sections.0.title', 'Introduction')
            ->assertJsonPath('data.sections.0.lessons.0.title', 'How the course works')
            ->assertJsonPath('data.sections.0.lessons.0.is_free_preview', true)
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonCount(1, 'data.sections.0.lessons');

        $detail->assertDontSee('Secret draft');
        $detail->assertDontSee('Unpublished only');
        $detail->assertDontSee('teacher@areducation.test');
        $this->assertArrayNotHasKey('can_manage_content', $detail->json('data.teachers.0'));

        $this->getJson('/api/v1/courses/hidden-draft')->assertNotFound();
    }

    public function test_active_enrollment_is_reported_for_the_signed_in_student(): void
    {
        $course = $this->course($this->category(), 'IELTS Writing', 'ielts-writing', 'academic', true, 2000, null);
        $student = User::factory()->create();
        $student->roles()->attach(Role::query()->where('slug', 'student')->value('id'));
        $token = $student->createToken('api')->plainTextToken;

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'refunded',
            'enrolled_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/courses/ielts-writing')
            ->assertOk()
            ->assertJsonPath('data.is_enrolled', false);

        Enrollment::query()->where('user_id', $student->id)->update(['status' => 'active']);
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/courses/ielts-writing')
            ->assertOk()
            ->assertJsonPath('data.is_enrolled', true);
    }

    private function category(string $name = 'Academic IELTS', string $slug = 'academic'): CourseCategory
    {
        return CourseCategory::query()->create(['name' => $name, 'slug' => $slug]);
    }

    private function course(
        CourseCategory $category,
        string $title,
        string $slug,
        string $examType,
        bool $published,
        float $price,
        ?float $discount,
    ): Course {
        return Course::query()->create([
            'category_id' => $category->id,
            'title' => $title,
            'slug' => $slug,
            'description' => 'Public description',
            'exam_type' => $examType,
            'price' => $price,
            'discount_price' => $discount,
            'duration_minutes' => 180,
            'is_published' => $published,
        ]);
    }
}
