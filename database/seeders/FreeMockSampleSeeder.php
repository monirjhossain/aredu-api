<?php

namespace Database\Seeders;

use App\Models\MockTest;
use Illuminate\Database\Seeder;

class FreeMockSampleSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure demo mocks exist, then flag a few as website free samples.
        $this->call([
            DemoReadingMockSeeder::class,
            DemoListeningMockSeeder::class,
        ]);

        // Best-practice free lead-ins: 2 Reading + 2 Listening. Remaining published
        // mocks stay enrollment-gated via PracticeAccessService.
        $freeSlugs = [
            'reading-climate-01',
            'reading-library-02',
            'listening-demo-campus-tour-1',
            'listening-demo-library-orientation-2',
        ];

        // Everything else published becomes paid/enrolled practice.
        MockTest::query()
            ->whereIn('module', [MockTest::MODULE_READING, MockTest::MODULE_LISTENING])
            ->where('is_published', true)
            ->whereNotIn('slug', $freeSlugs)
            ->update(['is_free_sample' => false]);

        $updated = MockTest::query()
            ->whereIn('slug', $freeSlugs)
            ->update([
                'is_published' => true,
                'is_free_sample' => true,
            ]);

        $freeCount = MockTest::query()
            ->whereIn('module', [MockTest::MODULE_READING, MockTest::MODULE_LISTENING])
            ->where('is_published', true)
            ->where('is_free_sample', true)
            ->count();

        $paidCount = MockTest::query()
            ->whereIn('module', [MockTest::MODULE_READING, MockTest::MODULE_LISTENING])
            ->where('is_published', true)
            ->where('is_free_sample', false)
            ->count();

        $this->command?->info("Free mock samples ready: {$updated} tests flagged for /free-practice");
        foreach ($freeSlugs as $slug) {
            $this->command?->line(' - free: '.$slug);
        }
        $this->command?->info("Practice split — free: {$freeCount}, enrollment-only: {$paidCount}");
    }
}
