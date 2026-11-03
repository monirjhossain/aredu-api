<?php

namespace App\Services\MockTest;

use App\Models\BandScoreConversion;
use App\Models\MockTest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BandConversionService
{
    /**
     * @return list<array{raw_score: int, band_score: string}>
     */
    public function list(string $module, string $examType): array
    {
        $this->assertModuleExam($module, $examType);

        return BandScoreConversion::query()
            ->where('module', $module)
            ->where('exam_type', $examType)
            ->orderBy('raw_score')
            ->get(['raw_score', 'band_score'])
            ->map(fn (BandScoreConversion $row) => [
                'raw_score' => (int) $row->raw_score,
                'band_score' => (string) $row->band_score,
            ])
            ->all();
    }

    /**
     * @param  list<array{raw_score: int|string, band_score: float|string}>  $rows
     * @return list<array{raw_score: int, band_score: string}>
     */
    public function sync(string $module, string $examType, array $rows): array
    {
        $this->assertModuleExam($module, $examType);

        $normalized = [];
        foreach ($rows as $index => $row) {
            $raw = (int) ($row['raw_score'] ?? -1);
            $band = (float) ($row['band_score'] ?? -1);
            if ($raw < 0 || $raw > 100) {
                throw ValidationException::withMessages([
                    "rows.$index.raw_score" => ['Raw score must be between 0 and 100.'],
                ]);
            }
            if ($band < 0 || $band > 9) {
                throw ValidationException::withMessages([
                    "rows.$index.band_score" => ['Band score must be between 0 and 9.'],
                ]);
            }
            $scaled = $band * 2;
            if (abs($scaled - round($scaled)) >= 0.00001) {
                throw ValidationException::withMessages([
                    "rows.$index.band_score" => ['Band score must use whole or half bands between 0 and 9.'],
                ]);
            }
            if (isset($normalized[$raw])) {
                throw ValidationException::withMessages([
                    "rows.$index.raw_score" => ['Duplicate raw score in the payload.'],
                ]);
            }
            $normalized[$raw] = number_format($band, 1, '.', '');
        }

        DB::transaction(function () use ($module, $examType, $normalized) {
            BandScoreConversion::query()
                ->where('module', $module)
                ->where('exam_type', $examType)
                ->delete();

            foreach ($normalized as $raw => $band) {
                BandScoreConversion::query()->create([
                    'module' => $module,
                    'exam_type' => $examType,
                    'raw_score' => $raw,
                    'band_score' => $band,
                ]);
            }
        });

        return $this->list($module, $examType);
    }

    private function assertModuleExam(string $module, string $examType): void
    {
        if (! in_array($module, [MockTest::MODULE_READING, MockTest::MODULE_LISTENING], true)) {
            throw ValidationException::withMessages([
                'module' => ['Module must be reading or listening.'],
            ]);
        }

        if (! in_array($examType, ['academic', 'general'], true)) {
            throw ValidationException::withMessages([
                'exam_type' => ['Exam type must be academic or general.'],
            ]);
        }
    }
}
