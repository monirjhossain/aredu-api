<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoPlaybackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'services.cloudflare.account_id' => 'account-1',
            'services.cloudflare.stream_token' => 'cf-token-secret-value',
            'services.cloudflare.customer_code' => 'demo123',
            'services.cloudflare.playback_ttl_seconds' => 900,
        ]);
    }

    public function test_guest_cannot_request_playback(): void
    {
        $lesson = $this->readyLesson();

        $this->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertUnauthorized();
    }

    public function test_student_needs_active_enrollment_unless_free_preview(): void
    {
        $paid = $this->readyLesson(freePreview: false, published: true, uid: 'uid-paid');
        $preview = $this->readyLesson(freePreview: true, published: true, slug: 'preview-course', uid: 'uid-preview');
        $unpublished = $this->readyLesson(freePreview: true, published: false, slug: 'draft-course', uid: 'uid-draft');
        $student = $this->student();

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/lessons/'.$paid->id.'/playback')
            ->assertForbidden();

        Auth::forgetGuards();

        Http::fake(function () {
            return Http::response([
                'success' => true,
                'result' => ['token' => 'signed-preview-token'],
            ]);
        });

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/lessons/'.$preview->id.'/playback')
            ->assertOk()
            ->assertJsonPath('data.lesson_id', $preview->id)
            ->assertJsonPath('data.expires_in', 900)
            ->assertJsonPath('data.playback.token', 'signed-preview-token')
            ->assertJsonPath('data.playback.iframe_url', 'https://customer-demo123.cloudflarestream.com/signed-preview-token/iframe')
            ->assertJsonMissingPath('data.cloudflare_uid')
            ->assertJsonMissingPath('data.playback.cloudflare_uid');

        Auth::forgetGuards();

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/lessons/'.$unpublished->id.'/playback')
            ->assertForbidden();
    }

    public function test_active_enrollment_gets_a_short_lived_token_and_refunded_does_not(): void
    {
        $lesson = $this->readyLesson(freePreview: false, published: true);
        $student = $this->student();
        $courseId = $lesson->section->course_id;

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'status' => 'refunded',
            'enrolled_at' => now(),
        ]);

        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertForbidden();

        Enrollment::query()->where('user_id', $student->id)->update(['status' => 'active']);

        Auth::forgetGuards();

        Http::fake(function () {
            return Http::response([
                'success' => true,
                'result' => ['token' => 'signed-enrolled-token'],
            ]);
        });

        $this->withToken($token)
            ->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertOk()
            ->assertJsonPath('data.playback.token', 'signed-enrolled-token')
            ->assertJsonPath('data.playback.hls_url', 'https://customer-demo123.cloudflarestream.com/signed-enrolled-token/manifest/video.m3u8');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/stream/video-uid-ready/token')
                && (int) ($data['exp'] ?? 0) >= time() + 800;
        });

        Auth::forgetGuards();

        $other = $this->readyLesson(freePreview: false, published: true, slug: 'other-course', uid: 'other-uid');

        $this->withToken($token)
            ->getJson('/api/v1/lessons/'.$other->id.'/playback')
            ->assertForbidden();
    }

    public function test_playback_requires_a_ready_video(): void
    {
        $lesson = $this->readyLesson();
        $student = $this->student();

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $lesson->section->course_id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Video::query()->where('lesson_id', $lesson->id)->update(['status' => Video::PROCESSING]);

        Http::fake();

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertNotFound();
    }

    private function readyLesson(
        bool $freePreview = false,
        bool $published = true,
        string $slug = 'writing',
        string $uid = 'video-uid-ready',
    ): Lesson {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );

        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Course '.$slug,
            'slug' => $slug,
            'exam_type' => 'academic',
            'price' => 1000,
            'duration_minutes' => 60,
            'is_published' => true,
        ]);

        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'Introduction',
            'position' => 1,
        ]);

        $lesson = Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Lesson',
            'position' => 1,
            'duration_seconds' => 120,
            'is_free_preview' => $freePreview,
            'is_published' => $published,
        ]);

        Video::query()->create([
            'lesson_id' => $lesson->id,
            'cloudflare_uid' => $uid,
            'status' => Video::READY,
            'duration_seconds' => 120,
        ]);

        return $lesson->load('section.course');
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }
}
