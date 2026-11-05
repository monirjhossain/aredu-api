<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class ExamContext
{
    public static function normalize(?string $value): string
    {
        $key = trim((string) ($value ?: 'practice'));
        if ($key === '' || $key === 'practice') {
            return 'practice';
        }

        if (! preg_match('/^fmss:\d+$/', $key)) {
            throw ValidationException::withMessages([
                'context_key' => ['Invalid examination context.'],
            ]);
        }

        return $key;
    }

    public static function sessionSectionId(string $contextKey): ?int
    {
        if (! preg_match('/^fmss:(\d+)$/', $contextKey, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * API / UI label: practice vs full mock (fmss:*).
     */
    public static function label(string $contextKey): string
    {
        return self::sessionSectionId($contextKey) !== null ? 'full_mock' : 'practice';
    }

    public static function isFullMock(string $contextKey): bool
    {
        return self::label($contextKey) === 'full_mock';
    }
}
