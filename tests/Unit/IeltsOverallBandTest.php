<?php

namespace Tests\Unit;

use App\Support\IeltsOverallBand;
use PHPUnit\Framework\TestCase;

class IeltsOverallBandTest extends TestCase
{
    public function test_official_rounding_rules(): void
    {
        $this->assertSame(6.0, IeltsOverallBand::roundOfficial(6.0));
        $this->assertSame(6.0, IeltsOverallBand::roundOfficial(6.1));
        $this->assertSame(6.0, IeltsOverallBand::roundOfficial(6.24));
        $this->assertSame(6.5, IeltsOverallBand::roundOfficial(6.25));
        $this->assertSame(6.5, IeltsOverallBand::roundOfficial(6.5));
        $this->assertSame(6.5, IeltsOverallBand::roundOfficial(6.74));
        $this->assertSame(7.0, IeltsOverallBand::roundOfficial(6.75));
        $this->assertSame(7.0, IeltsOverallBand::roundOfficial(6.9));
        $this->assertSame(9.0, IeltsOverallBand::roundOfficial(8.75));
    }

    public function test_overall_requires_four_skill_bands(): void
    {
        $this->assertNull(IeltsOverallBand::fromSkillBands([6.5, 7.0, 6.0]));
        $this->assertNull(IeltsOverallBand::fromSkillBands([6.5, 7.0, 6.0, null]));
        $this->assertSame(6.5, IeltsOverallBand::fromSkillBands([6.5, 7.0, 6.0, 6.5]));
        // Mean 6.375 → rounds to 6.5 under official rules (.25 boundary uses up-to-half)
        $this->assertSame(6.5, IeltsOverallBand::fromSkillBands([6.0, 6.5, 6.5, 6.5]));
        // Mean 6.75 → 7.0
        $this->assertSame(7.0, IeltsOverallBand::fromSkillBands([6.5, 7.0, 6.5, 7.0]));
    }
}
