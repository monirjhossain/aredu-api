<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CloudflareStreamClient
{
    /**
     * @return array{uid: string, upload_url: string}
     */
    public function createDirectUpload(int $maxDurationSeconds): array
    {
        [$accountId, $token] = $this->credentials('Video upload is not configured.');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->post($this->directUploadUrl($accountId), [
                'maxDurationSeconds' => $maxDurationSeconds,
                'requireSignedURLs' => true,
            ]);

        $uid = $response->json('result.uid');
        $uploadUrl = $response->json('result.uploadURL');

        if (! $response->successful() || $response->json('success') !== true || ! is_string($uid) || $uid === '' || ! is_string($uploadUrl) || $uploadUrl === '') {
            throw new HttpException(502, 'Cloudflare Stream could not create an upload.');
        }

        return [
            'uid' => $uid,
            'upload_url' => $uploadUrl,
        ];
    }

    /**
     * @return array{token: string}
     */
    public function createPlaybackToken(string $uid, int $expiresInSeconds): array
    {
        [$accountId, $token] = $this->credentials('Video playback is not configured.');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->post($this->playbackTokenUrl($accountId, $uid), [
                'exp' => time() + max(60, $expiresInSeconds),
            ]);

        $playbackToken = $response->json('result.token');

        if (! $response->successful() || $response->json('success') !== true || ! is_string($playbackToken) || $playbackToken === '') {
            throw new HttpException(502, 'Cloudflare Stream could not create a playback token.');
        }

        return [
            'token' => $playbackToken,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(string $missingMessage): array
    {
        $accountId = config('services.cloudflare.account_id');
        $token = config('services.cloudflare.stream_token');

        if (! is_string($accountId) || $accountId === '' || ! is_string($token) || $token === '') {
            throw new HttpException(503, $missingMessage);
        }

        return [$accountId, $token];
    }

    private function directUploadUrl(string $accountId): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($accountId).'/stream/direct_upload';
    }

    private function playbackTokenUrl(string $accountId, string $uid): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($accountId).'/stream/'.rawurlencode($uid).'/token';
    }
}
