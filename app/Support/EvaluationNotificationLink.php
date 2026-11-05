<?php

namespace App\Support;

use App\Models\FullMockSessionSection;

/**
 * Builds student deep links for evaluation notifications (practice vs full mock).
 */
final class EvaluationNotificationLink
{
    /**
     * @return array{url: string, context_key: string, context_label: string, session_uuid: string|null}
     */
    public static function forTask(string $pathPrefix, int $taskId, ?string $contextKey): array
    {
        $key = trim((string) ($contextKey ?: 'practice'));
        if ($key === '') {
            $key = 'practice';
        }

        $sessionUuid = null;
        $sectionId = ExamContext::sessionSectionId($key);
        if ($sectionId !== null) {
            $sessionUuid = FullMockSessionSection::query()
                ->whereKey($sectionId)
                ->with('session:id,uuid')
                ->first()
                ?->session
                ?->uuid;
        }

        $query = [];
        if ($key !== 'practice') {
            $query['context_key'] = $key;
            if ($sessionUuid !== null) {
                $query['session'] = $sessionUuid;
            }
        }

        $url = $pathPrefix.'/'.$taskId;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return [
            'url' => $url,
            'context_key' => $key,
            'context_label' => ExamContext::label($key),
            'session_uuid' => $sessionUuid,
        ];
    }
}
