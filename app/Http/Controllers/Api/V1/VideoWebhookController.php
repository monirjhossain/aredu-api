<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Video\CloudflareWebhookVerifier;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoWebhookController extends Controller
{
    public function __construct(
        private readonly CloudflareWebhookVerifier $verifier,
        private readonly VideoService $videos,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $this->verifier->verify((string) $request->header('Webhook-Signature', ''), $rawBody);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true) ?? [];

        if (! is_array($payload)) {
            $payload = [];
        }

        $video = $this->videos->applyCloudflareNotification($payload);

        if ($video === null) {
            return ApiResponse::success(null, 'Webhook ignored.');
        }

        return ApiResponse::success([
            'id' => $video->id,
            'lesson_id' => $video->lesson_id,
            'cloudflare_uid' => $video->cloudflare_uid,
            'status' => $video->status,
            'duration_seconds' => $video->duration_seconds,
            'error_message' => $video->error_message,
        ], 'Webhook processed.');
    }
}
