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

        $readingSlugs = [
            'reading-climate-01',
            'reading-library-02',
        ];

        $listeningSlugs = MockTest::query()
            ->where('module', MockTest::MODULE_LISTENING)
            ->where('is_published', true)
            ->orderBy('id')
            ->limit(2)
            ->pluck('slug')
            ->all();

        $slugs = array_values(array_unique([...$readingSlugs, ...$listeningSlugs]));

        $updated = MockTest::query()
            ->whereIn('slug', $slugs)
            ->update([
                'is_published' => true,
                'is_free_sample' => true,
            ]);

        $this->command?->info("Free mock samples ready: {$updated} tests flagged for /free-practice");
        foreach ($slugs as $slug) {
            $this->command?->line(' - '.$slug);
        }
    }
}
