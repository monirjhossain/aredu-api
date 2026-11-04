<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_only_a_video_manager_can_save_an_embed(): void
    {
        $lesson = $this->lesson();

        $this->postJson($this->url($lesson), [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->postJson($this->url($lesson), [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->assertForbidden();

        Auth::forgetGuards();

        $this->withToken($this->tokenFor('teacher'))
            ->postJson($this->url($lesson), [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->assertForbidden();

        $this->assertSame(0, Video::query()->count());
    }

    public function test_youtube_embed_is_saved_as_ready(): void
    {
        $lesson = $this->lesson();
        $token = $this->tokenFor('super_admin');

        $this->withToken($token)
            ->postJson($this->url($lesson), [
                'url' => 'https://youtu.be/dQw4w9WgXcQ',
                'duration_seconds' => 212,
            ])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'youtube')
            ->assertJsonPath('data.external_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.duration_seconds', 212)
            ->assertJsonPath('data.source_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertSame(1, Video::query()->count());

        $this->withToken($token)
            ->postJson($this->url($lesson), [
                'url' => 'https://vimeo.com/123456789',
            ])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'vimeo')
            ->assertJsonPath('data.external_id', '123456789')
            ->assertJsonPath('data.status', 'ready');

        $this->assertSame(1, Video::query()->count());
    }

    public function test_invalid_url_is_rejected(): void
    {
        $lesson = $this->lesson();

        $this->withToken($this->tokenFor('super_admin'))
            ->postJson($this->url($lesson), [
                'url' => 'https://example.com/video.mp4',
            ])
            ->assertStatus(422);
    }

    public function test_cover_can_be_saved_for_an_embed(): void
    {
        Storage::fake('public');
        $lesson = $this->lesson();
        $token = $this->tokenFor('super_admin');

        $this->withToken($token)
            ->postJson($this->url($lesson), [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->assertCreated();

        $this->withToken($token)
            ->post('/api/v1/admin/lessons/'.$lesson->id.'/video/cover', [
                'cover' => UploadedFile::fake()->image('cover.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $video = Video::query()->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($video?->cover_path);
        Storage::disk('public')->assertExists($video->cover_path);
    }

    private function url(Lesson $lesson): string
    {
        return '/api/v1/admin/lessons/'.$lesson->id.'/video';
    }

    private function lesson(): Lesson
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );

        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Speaking Mastery',
            'slug' => 'speaking-mastery',
            'exam_type' => 'academic',
            'price' => 1500,
            'duration_minutes' => 90,
            'is_published' => true,
        ]);

        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'Part 1',
            'position' => 1,
        ]);

        return Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Warm up',
            'position' => 1,
            'duration_seconds' => 300,
            'is_free_preview' => false,
            'is_published' => true,
        ]);
    }

    private function tokenFor(string $roleSlug): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user->createToken('api')->plainTextToken;
    }
}
