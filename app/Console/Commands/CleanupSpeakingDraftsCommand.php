<?php

namespace App\Console\Commands;

use App\Services\Speaking\SpeakingDraftCleanupService;
use Illuminate\Console\Command;

class CleanupSpeakingDraftsCommand extends Command
{
    protected $signature = 'speaking:cleanup-drafts
                            {--hours= : Override retention hours (default from config)}
                            {--chunk=100 : Chunk size for scanning drafts}';

    protected $description = 'Delete abandoned uploaded speaking drafts (not submitted) and their audio files';

    public function handle(SpeakingDraftCleanupService $cleanup): int
    {
        $hours = $this->option('hours');
        $chunk = max(10, (int) $this->option('chunk'));

        $result = $cleanup->cleanup(
            $hours !== null && $hours !== '' ? (int) $hours : null,
            $chunk,
        );

        $this->info(sprintf(
            'Speaking draft cleanup complete. deleted=%d skipped_linked=%d files_missing=%d',
            $result['deleted'],
            $result['skipped_linked'],
            $result['files_missing'],
        ));

        return self::SUCCESS;
    }
}
