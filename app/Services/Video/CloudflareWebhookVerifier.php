<?php

namespace App\Services\Video;

use Symfony\Component\HttpKernel\Exception\HttpException;

class CloudflareWebhookVerifier
{
    public function __construct(private readonly int $toleranceSeconds = 300) {}

    public function verify(string $signatureHeader, string $rawBody): void
    {
        $secret = config('services.cloudflare.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new HttpException(503, 'Video webhook is not configured.');
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $time = $parts['time'] ?? null;
        $signature = $parts['sig1'] ?? null;

        if (! is_string($time) || $time === '' || ! ctype_digit($time) || ! is_string($signature) || $signature === '') {
            throw new HttpException(401, 'Invalid webhook signature.');
        }

        if (abs(time() - (int) $time) > $this->toleranceSeconds) {
            throw new HttpException(401, 'Webhook signature has expired.');
        }

        $expected = hash_hmac('sha256', $time.'.'.$rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new HttpException(401, 'Invalid webhook signature.');
        }
    }
}
