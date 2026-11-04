<?php

namespace App\Services\Video;

use App\Models\Video;
use Illuminate\Validation\ValidationException;

class VideoEmbedParser
{
    /**
     * @return array{provider: string, external_id: string, embed_url: string, source_url: string}
     */
    public function parse(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            throw ValidationException::withMessages([
                'url' => ['A YouTube or Vimeo URL is required.'],
            ]);
        }

        if ($youtube = $this->parseYouTube($url)) {
            return $youtube;
        }

        if ($vimeo = $this->parseVimeo($url)) {
            return $vimeo;
        }

        throw ValidationException::withMessages([
            'url' => ['Only YouTube or Vimeo video URLs are supported.'],
        ]);
    }

    public function iframeUrl(string $provider, string $externalId, int $startSeconds = 0): string
    {
        $startSeconds = max(0, $startSeconds);

        if ($provider === Video::PROVIDER_YOUTUBE) {
            $query = [
                'rel' => 0,
                'modestbranding' => 1,
                'playsinline' => 1,
            ];
            if ($startSeconds > 0) {
                $query['start'] = $startSeconds;
            }

            return 'https://www.youtube.com/embed/'.$externalId.'?'.http_build_query($query);
        }

        if ($provider === Video::PROVIDER_VIMEO) {
            $base = 'https://player.vimeo.com/video/'.$externalId;
            if ($startSeconds > 0) {
                return $base.'#t='.$startSeconds.'s';
            }

            return $base;
        }

        throw ValidationException::withMessages([
            'provider' => ['Unsupported video provider.'],
        ]);
    }

    /**
     * @return array{provider: string, external_id: string, embed_url: string, source_url: string}|null
     */
    private function parseYouTube(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $id = null;

        if (str_contains($host, 'youtu.be')) {
            $id = trim($path, '/');
        } elseif (str_contains($host, 'youtube.com') || str_contains($host, 'youtube-nocookie.com')) {
            if (str_starts_with($path, '/embed/')) {
                $id = trim(substr($path, 7), '/');
            } elseif (str_starts_with($path, '/shorts/')) {
                $id = trim(substr($path, 8), '/');
            } elseif (str_starts_with($path, '/live/')) {
                $id = trim(substr($path, 6), '/');
            } else {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            }
        }

        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id)) {
            return null;
        }

        return [
            'provider' => Video::PROVIDER_YOUTUBE,
            'external_id' => $id,
            'embed_url' => $this->iframeUrl(Video::PROVIDER_YOUTUBE, $id),
            'source_url' => 'https://www.youtube.com/watch?v='.$id,
        ];
    }

    /**
     * @return array{provider: string, external_id: string, embed_url: string, source_url: string}|null
     */
    private function parseVimeo(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (! str_contains($host, 'vimeo.com')) {
            return null;
        }

        $path = trim($parts['path'] ?? '', '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        $id = null;
        foreach (array_reverse($segments) as $segment) {
            if (preg_match('/^\d{6,12}$/', $segment)) {
                $id = $segment;
                break;
            }
        }

        if ($id === null) {
            return null;
        }

        return [
            'provider' => Video::PROVIDER_VIMEO,
            'external_id' => $id,
            'embed_url' => $this->iframeUrl(Video::PROVIDER_VIMEO, $id),
            'source_url' => 'https://vimeo.com/'.$id,
        ];
    }
}
