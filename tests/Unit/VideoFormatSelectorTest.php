<?php

namespace Tests\Unit;

use App\Services\VideoFormatSelector;
use Tests\TestCase;

class VideoFormatSelectorTest extends TestCase
{
    private function selector(): VideoFormatSelector
    {
        return app(VideoFormatSelector::class);
    }

    public function test_build_unlimited_hevc(): void
    {
        $this->assertSame(
            "-f 'bv*[dynamic_range=SDR]+ba/bv*+ba/b' -S 'quality,vcodec:h265'",
            $this->selector()->buildFormatArgs('unlimited', 'hevc')
        );
    }

    public function test_build_1080_avc(): void
    {
        $this->assertSame(
            "-f 'bv*[dynamic_range=SDR][quality<=116]+ba/bv*[dynamic_range=SDR]+ba/bv*+ba/b' -S 'quality,vcodec:h264'",
            $this->selector()->buildFormatArgs('1080', 'avc')
        );
    }

    public function test_build_720_av1(): void
    {
        $args = $this->selector()->buildFormatArgs('720', 'av1');
        $this->assertStringContainsString('[quality<=74]', $args);
        $this->assertStringContainsString('vcodec:av01', $args);
    }

    public function test_build_unknown_values_fall_back_to_defaults(): void
    {
        $this->assertSame(
            $this->selector()->buildFormatArgs('unlimited', 'hevc'),
            $this->selector()->buildFormatArgs('foo', 'bar')
        );
    }
}
