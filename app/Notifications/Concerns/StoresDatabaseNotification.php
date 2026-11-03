<?php

namespace App\Notifications\Concerns;

trait StoresDatabaseNotification
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function databasePayload(string $event, string $title, string $message, array $payload = []): array
    {
        return array_merge([
            'event' => $event,
            'title' => $title,
            'message' => $message,
        ], $payload);
    }
}
