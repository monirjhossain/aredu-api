<?php

namespace App\Services\Speaking;

use App\Models\FullMockSessionSection;
use App\Models\SpeakingRecording;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SpeakingDraftCleanupService
{
    public const DEFAULT_RETENTION_HOURS = 48;

    /**
     * Delete abandoned uploaded (not submitted) speaking drafts older than the retention window.
     *
     * @return array{deleted: int, skipped_linked: int, files_missing: int}
     */
    public function cleanup(?int $retentionHours = null, int $chunkSize = 100): array
    {
        $hours = $retentionHours ?? (int) config('speaking.draft_retention_hours', self::DEFAULT_RETENTION_HOURS);
        $hours = max(1, $hours);
        $cutoff = now()->subHours($hours);

        $deleted = 0;
        $skippedLinked = 0;
        $filesMissing = 0;

        SpeakingRecording::query()
            ->where('status', SpeakingRecording::STATUS_UPLOADED)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($recordings) use (&$deleted, &$skippedLinked, &$filesMissing) {
                foreach ($recordings as $recording) {
                    $result = $this->deleteDraft($recording);
                    if ($result === 'deleted') {
                        $deleted++;
                    } elseif ($result === 'linked') {
                        $skippedLinked++;
                    } elseif ($result === 'missing') {
                        $filesMissing++;
                        $deleted++;
                    }
                }
            });

        return [
            'deleted' => $deleted,
            'skipped_linked' => $skippedLinked,
            'files_missing' => $filesMissing,
        ];
    }

    /**
     * @return 'deleted'|'linked'|'missing'
     */
    private function deleteDraft(SpeakingRecording $recording): string
    {
        if ($recording->status !== SpeakingRecording::STATUS_UPLOADED) {
            return 'linked';
        }

        if (FullMockSessionSection::query()->where('speaking_recording_id', $recording->id)->exists()) {
            return 'linked';
        }

        if ($recording->evaluation()->exists()) {
            return 'linked';
        }

        return DB::transaction(function () use ($recording) {
            $locked = SpeakingRecording::query()
                ->whereKey($recording->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== SpeakingRecording::STATUS_UPLOADED) {
                return 'linked';
            }

            if (FullMockSessionSection::query()->where('speaking_recording_id', $locked->id)->exists()) {
                return 'linked';
            }

            $disk = $locked->disk ?: 'local';
            $path = $locked->path;
            $missing = ! Storage::disk($disk)->exists($path);

            if (! $missing) {
                Storage::disk($disk)->delete($path);
            } else {
                Log::info('Speaking draft cleanup: file already missing', [
                    'recording_id' => $locked->id,
                    'path' => $path,
                ]);
            }

            $locked->delete();

            return $missing ? 'missing' : 'deleted';
        });
    }
}
