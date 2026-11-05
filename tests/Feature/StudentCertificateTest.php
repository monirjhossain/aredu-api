<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class StudentCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_owner_can_view_certificate_details_and_others_cannot(): void
    {
        $student = $this->student();
        $other = $this->student();
        $course = $this->course();

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => Enrollment::ACTIVE,
            'enrolled_at' => now(),
        ]);

        $certificate = Certificate::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'certificate_number' => 'ARTESTCERT0001',
            'issued_at' => now(),
        ]);

        Auth::forgetGuards();
        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/student/certificates/'.$certificate->id)
            ->assertOk()
            ->assertJsonPath('data.certificate_number', 'ARTESTCERT0001')
            ->assertJsonPath('data.student_name', $student->name)
            ->assertJsonPath('data.course.title', $course->title)
            ->assertJsonPath('data.course.slug', $course->slug);

        Auth::forgetGuards();
        $this->withToken($other->createToken('api')->plainTextToken)
            ->getJson('/api/v1/student/certificates/'.$certificate->id)
            ->assertForbidden();
    }

    private function course(): Course
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic'],
        );

        return Course::query()->create([
            'category_id' => $category->id,
            'title' => 'IELTS Academic Prep',
            'slug' => 'ielts-academic-prep-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 0,
            'is_published' => true,
        ]);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }
}
