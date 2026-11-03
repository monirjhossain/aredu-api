<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use App\Services\Video\VideoService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoUploadTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cf-token-secret-value';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'services.cloudflare.account_id' => 'account-1',
            'services.cloudflare.stream_token' => self::TOKEN,
            'services.cloudflare.max_duration_seconds' => 3600,
        ]);
    }

    public function test_only_a_video_manager_can_request_an_upload(): void
    {
        $lesson = $this->lesson();

        $this->postJson($this->url($lesson))->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->postJson($this->url($lesson))
            ->assertForbidden();

        Auth::forgetGuards();

        $this->withToken($this->tokenFor('teacher'))
            ->postJson($this->url($lesson))
            ->assertForbidden();

        $this->assertSame(0, Video::query()->count());

        $lesson->delete();

        Auth::forgetGuards();

        $this->withToken($this->tokenFor('super_admin'))
            ->postJson($this->url($lesson))
            ->assertNotFound();
    }

    public function test_direct_upload_stores_one_video_and_hides_the_api_token(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'uid' => 'video-uid-1',
                    'uploadURL' => 'https://upload.cloudflarestream.com/one-time',
                ],
            ]),
        ]);

        $lesson = $this->lesson();
        $token = $this->tokenFor('super_admin');

        $created = $this->withToken($token)
            ->postJson($this->url($lesson))
            ->assertCreated()
            ->assertJsonPath('data.cloudflare_uid', 'video-uid-1')
            ->assertJsonPath('data.status', 'uploading')
            ->assertJsonPath('data.duration_seconds', null)
            ->assertJsonPath('data.upload_url', 'https://upload.cloudflarestream.com/one-time');

        $created->assertDontSee(self::TOKEN);
        $this->assertSame(1, Video::query()->count());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/account-1/stream/direct_upload'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request['maxDurationSeconds'] === 3600
                && $request['requireSignedURLs'] === true;
        });

        $this->withToken($token)->postJson($this->url($lesson))->assertStatus(422);
        Http::assertSentCount(1);
        $this->assertSame(1, Video::query()->count());
        $this->assertSame('video-uid-1', Video::query()->first()->cloudflare_uid);

        $service = app(VideoService::class);
        $video = Video::query()->first();

        $processing = $service->markProcessing($video->fresh(), 120);
        $this->assertSame('processing', $processing->status);
        $this->assertSame(120, $processing->duration_seconds);

        $ready = $service->markReady($video->fresh(), 125);
        $this->assertSame('ready', $ready->status);
        $this->assertSame(125, $ready->duration_seconds);
        $this->assertNull($ready->error_message);

        $stillReady = $service->markFailed($ready->fresh(), 'Too late');
        $this->assertSame('ready', $stillReady->status);
        $this->assertNull($stillReady->error_message);
    }

    public function test_a_failed_upload_can_be_replaced_without_a_second_row(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::sequence()
                ->push([
                    'success' => true,
                    'result' => [
                        'uid' => 'video-uid-1',
                        'uploadURL' => 'https://upload.cloudflarestream.com/first',
                    ],
                ])
                ->push([
                    'success' => true,
                    'result' => [
                        'uid' => 'video-uid-2',
                        'uploadURL' => 'https://upload.cloudflarestream.com/second',
                    ],
                ])
                ->push(['success' => false, 'errors' => [['message' => 'rejected']]], 400),
        ]);

        $lesson = $this->lesson();
        $token = $this->tokenFor('super_admin');
        $this->withToken($token)->postJson($this->url($lesson))->assertCreated();

        $video = Video::query()->first();
        app(VideoService::class)->markFailed($video, 'The upload was interrupted.');
        $this->assertSame('The upload was interrupted.', $video->fresh()->error_message);

        $replaced = $this->withToken($token)
            ->postJson($this->url($lesson))
            ->assertCreated()
            ->assertJsonPath('data.id', $video->id)
            ->assertJsonPath('data.cloudflare_uid', 'video-uid-2')
            ->assertJsonPath('data.status', 'uploading')
            ->assertJsonPath('data.error_message', null);

        $replaced->assertDontSee(self::TOKEN);
        $this->assertSame(1, Video::query()->count());

        config(['services.cloudflare.stream_token' => '']);
        $other = $this->lesson('Second');
        $this->withToken($token)
            ->postJson($this->url($other))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Video upload is not configured.')
            ->assertDontSee(self::TOKEN);

        config([
            'services.cloudflare.stream_token' => self::TOKEN,
            'services.cloudflare.account_id' => 'account-1',
        ]);

        $this->withToken($token)
            ->postJson($this->url($other))
            ->assertStatus(502)
            ->assertJsonPath('message', 'Cloudflare Stream could not create an upload.');

        $this->assertNull(Video::query()->where('lesson_id', $other->id)->first());
        $this->assertSame('video-uid-2', Video::query()->where('lesson_id', $lesson->id)->value('cloudflare_uid'));
    }

    public function test_video_cover_can_be_set_with_an_existing_video(): void
    {
        Storage::fake('public');

        $lesson = $this->lesson('Cover lesson');
        Video::query()->create([
            'lesson_id' => $lesson->id,
            'cloudflare_uid' => 'cover-uid',
            'status' => Video::UPLOADING,
        ]);

        $token = $this->tokenFor('super_admin');

        $this->withToken($token)
            ->post('/api/v1/admin/lessons/'.$lesson->id.'/video/cover', [
                'cover' => UploadedFile::fake()->image('cover.jpg', 640, 360),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.lesson_id', $lesson->id)
            ->assertJsonPath('data.cover_url', fn ($url) => is_string($url) && str_contains($url, '/storage/video-covers/'));

        $path = Video::query()->where('lesson_id', $lesson->id)->value('cover_path');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->withToken($token)
            ->deleteJson('/api/v1/admin/lessons/'.$lesson->id.'/video/cover')
            ->assertOk()
            ->assertJsonPath('data.cover_url', null);

        Storage::disk('public')->assertMissing($path);
    }

    private function url(Lesson $lesson): string
    {
        return '/api/v1/admin/lessons/'.$lesson->id.'/video';
    }

    private function lesson(string $title = 'Welcome'): Lesson
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );
        $course = Course::query()->firstOrCreate(
            ['slug' => 'writing'],
            [
                'category_id' => $category->id,
                'title' => 'Writing',
                'exam_type' => 'academic',
                'price' => 1000,
                'duration_minutes' => 60,
                'is_published' => false,
            ],
        );
        $section = CourseSection::query()->firstOrCreate(
            ['course_id' => $course->id, 'title' => 'Introduction'],
            ['position' => 1],
        );

        return Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => $title,
            'position' => (int) Lesson::query()->where('course_section_id', $section->id)->max('position') + 1,
            'duration_seconds' => 300,
            'is_free_preview' => false,
            'is_published' => false,
        ]);
    }

    private function tokenFor(string $roleSlug): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user->createToken('api')->plainTextToken;
    }
}
