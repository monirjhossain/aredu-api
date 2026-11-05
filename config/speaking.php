<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Draft recording retention (hours)
    |--------------------------------------------------------------------------
    |
    | Uploaded-but-not-submitted speaking recordings older than this window
    | are removed by `php artisan speaking:cleanup-drafts` (scheduled daily).
    |
    */

    'draft_retention_hours' => (int) env('SPEAKING_DRAFT_RETENTION_HOURS', 48),

];
