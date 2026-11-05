<?php

namespace App\Support;

/**
 * Official IELTS overall band calculation helpers.
 *
 * Overall band = mean of Listening, Reading, Writing, Speaking, then rounded:
 * - fraction < .25 → round down to the whole band
 * - .25 ≤ fraction < .75 → round to the .5 band
 * - fraction ≥ .75 → round up to the next whole band
 *
 * Examples: 6.1 → 6.0, 6.25 → 6.5, 6.5 → 6.5, 6.75 → 7.0
 *
 * Academic vs General Training use different Reading raw→band tables
 * (module=reading, exam_type=academic|general). Listening conversion is shared.
 * Combined overall must NOT be shown until every skill band is available.
 */
final class IeltsOverallBand
{
    /**
     * @param  list<float|int|string|null>  $skillBands  exactly the four skill bands when ready
     */
    public static function fromSkillBands(array $skillBands): ?float
    {
        if (count($skillBands) !== 4) {
            return null;
        }

        $values = [];
        foreach ($skillBands as $band) {
            if ($band === null || $band === '') {
                return null;
            }
            $value = (float) $band;
            if ($value < 0 || $value > 9) {
                return null;
            }
            $values[] = $value;
        }

        $average = array_sum($values) / 4;

        return self::roundOfficial($average);
    }

    public static function roundOfficial(float $average): float
    {
        if ($average < 0) {
            return 0.0;
        }
        if ($average > 9) {
            return 9.0;
        }

        $whole = (int) floor($average + 1e-9);
        $fraction = $average - $whole;

        if ($fraction < 0.25) {
            return (float) $whole;
        }
        if ($fraction < 0.75) {
            return round($whole + 0.5, 1);
        }

        return (float) min(9, $whole + 1);
    }
}
