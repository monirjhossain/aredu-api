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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'services.cloudflare.account_id' => 'account-1',
            'services.cloudflare.stream_token' => 'cf-token-secret-value',
            'services.cloudflare.webhook_secret' => self::SECRET,
            'services.cloudflare.max_duration_seconds' => 3600,
        ]);
    }

    public function test_webhook_rejects_bad_signatures_and_is_idempotent(): void
    {
        $video = $this->video('video-uid-1', Video::UPLOADING);

        $readyBody = json_encode([
            'uid' => 'video-uid-1',
            'readyToStream' => true,
            'duration' => 5.5,
            'status' => [
                'state' => 'ready',
                'pctComplete' => '100',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            $this->headers($readyBody, 'bad-signature'),
            $readyBody,
        )->assertUnauthorized();

        $this->assertSame('uploading', $video->fresh()->status);

        $first = $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            $this->headers($readyBody),
            $readyBody,
        )->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.duration_seconds', 6);

        $this->assertSame('ready', $video->fresh()->status);
        $this->assertSame(6, $video->fresh()->duration_seconds);

        $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            $this->headers($readyBody),
            $readyBody,
        )->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $unknownBody = json_encode([
            'uid' => 'missing-uid',
            'status' => ['state' => 'ready'],
            'duration' => 10,
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            $this->headers($unknownBody),
            $unknownBody,
        )->assertOk()
            ->assertJsonPath('message', 'Webhook ignored.')
            ->assertJsonPath('data', null);

        $errorBody = json_encode([
            'uid' => 'video-uid-1',
            'readyToStream' => false,
            'status' => [
                'state' => 'error',
                'errReasonCode' => 'ERR_MALFORMED_VIDEO',
                'errReasonText' => 'The video was deemed to be corrupted or malformed.',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            $this->headers($errorBody),
            $errorBody,
        )->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.error_message', null);
    }

    public function test_webhook_marks_failed_uploads_and_admin_can_replace_them(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'uid' => 'video-uid-2',
                    'uploadURL' => 'https://upload.cloudflarestream.com/retry',
                ],
            ]),
        ]);

        $video = $this->video('video-uid-1', Video::PROCESSING);
        $lesson = $video->lesson;

        $errorBody = json_encode([
            'uid' => 'video-uid-1',
            'readyToStream' => false,
            'status' => [
                'state' => 'error',
                'errorReasonCode' => 'ERR_NON_VIDEO',
                'errorReasonText' => 'The upload is not a video.',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            $this->headers($errorBody),
            $errorBody,
        )->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'The upload is not a video.');

        $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            $this->headers($errorBody),
            $errorBody,
        )->assertOk()->assertJsonPath('data.status', 'failed');

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/v1/admin/lessons/'.$lesson->id.'/video')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->withToken($token)
            ->postJson('/api/v1/admin/lessons/'.$lesson->id.'/video')
            ->assertCreated()
            ->assertJsonPath('data.id', $video->id)
            ->assertJsonPath('data.cloudflare_uid', 'video-uid-2')
            ->assertJsonPath('data.status', 'uploading')
            ->assertJsonPath('data.error_message', null);

        $this->assertSame(1, Video::query()->count());
    }

    public function test_expired_signature_is_rejected(): void
    {
        $body = '{"uid":"video-uid-1","status":{"state":"ready"},"duration":1}';
        $time = (string) (time() - 301);
        $signature = hash_hmac('sha256', $time.'.'.$body, self::SECRET);

        $this->call(
            'POST',
            '/api/v1/webhooks/cloudflare/stream',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_WEBHOOK_SIGNATURE' => 'time='.$time.',sig1='.$signature,
            ],
            $body,
        )->assertUnauthorized()
            ->assertJsonPath('message', 'Webhook signature has expired.');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $body, ?string $forcedSignature = null): array
    {
        $time = (string) time();
        $signature = $forcedSignature ?? hash_hmac('sha256', $time.'.'.$body, self::SECRET);

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => 'time='.$time.',sig1='.$signature,
        ];
    }

    private function video(string $uid, string $status): Video
    {
        $category = CourseCategory::query()->create(['name' => 'Academic IELTS', 'slug' => 'academic']);
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Writing',
            'slug' => 'writing',
            'exam_type' => 'academic',
            'price' => 1000,
            'duration_minutes' => 60,
            'is_published' => false,
        ]);
        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'Introduction',
            'position' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Welcome',
            'position' => 1,
            'duration_seconds' => 0,
            'is_free_preview' => false,
            'is_published' => false,
        ]);

        return Video::query()->create([
            'lesson_id' => $lesson->id,
            'cloudflare_uid' => $uid,
            'status' => $status,
        ]);
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->value('id'));

        return $user->createToken('api')->plainTextToken;
    }
}
