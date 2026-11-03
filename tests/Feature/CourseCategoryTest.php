<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_only_a_category_manager_can_use_the_category_api(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->getJson('/api/v1/admin/categories')
            ->assertForbidden();

        $category = CourseCategory::query()->create([
            'name' => 'Temp',
            'slug' => 'temp',
        ]);

        Auth::forgetGuards();

        $this->withToken($this->tokenFor('teacher'))
            ->deleteJson('/api/v1/admin/categories/'.$category->id)
            ->assertForbidden();

        $this->assertNotNull(CourseCategory::query()->find($category->id));
    }

    public function test_admin_can_search_create_update_and_delete_categories(): void
    {
        $token = $this->tokenFor('super_admin');

        $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name' => 'Academic',
            'slug' => 'academic',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'academic')
            ->assertJsonPath('data.in_use', false);

        $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name' => 'Academic again',
            'slug' => 'academic',
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name' => 'General Training',
            'slug' => 'general-training',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/admin/categories?search=acad')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.slug', 'academic');

        $category = CourseCategory::query()->where('slug', 'academic')->firstOrFail();

        $this->withToken($token)->putJson('/api/v1/admin/categories/'.$category->id, [
            'name' => 'Academic IELTS',
            'slug' => 'academic',
        ])->assertOk()->assertJsonPath('data.name', 'Academic IELTS');

        $this->withToken($token)->deleteJson('/api/v1/admin/categories/'.$category->id)
            ->assertOk();

        $this->withToken($token)->getJson('/api/v1/admin/categories')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'general-training');

        $this->withToken($token)->getJson('/api/v1/admin/categories/'.$category->id)
            ->assertNotFound();
    }

    public function test_a_category_used_by_a_course_cannot_be_deleted(): void
    {
        $token = $this->tokenFor('super_admin');
        $category = CourseCategory::query()->create([
            'name' => 'Speaking',
            'slug' => 'speaking',
        ]);
        $categoryId = DB::table('course_categories')->insertGetId([
            'name' => 'Holder',
            'slug' => 'holder',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Speaking course',
            'slug' => 'speaking-course',
            'exam_type' => 'academic',
            'price' => 1000,
        ]);

        $this->withToken($token)
            ->deleteJson('/api/v1/admin/categories/'.$category->id)
            ->assertStatus(422);

        $course->delete();

        $this->withToken($token)
            ->deleteJson('/api/v1/admin/categories/'.$category->id)
            ->assertStatus(422);

        $course->forceDelete();

        $this->withToken($token)
            ->deleteJson('/api/v1/admin/categories/'.$category->id)
            ->assertOk();

        $this->assertNotNull(CourseCategory::withTrashed()->find($category->id)?->deleted_at);
        $this->assertNotNull(CourseCategory::query()->find($categoryId));
    }

    private function tokenFor(string $roleSlug): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user->createToken('api')->plainTextToken;
    }
}
