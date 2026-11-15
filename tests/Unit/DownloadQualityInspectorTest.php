<?php

namespace Tests\Unit;

use App\Services\DownloadQualityInspector;
use Tests\TestCase;

class DownloadQualityInspectorTest extends TestCase
{
    private const MISSING_OUTPUT = '[BiliBili] Format(s) 4K 超高清, 1080P 60帧 are missing; you have to become a premium member to download them. Use --cookies-from-browser or --cookies for the authentication.';

    private function inspector(): DownloadQualityInspector
    {
        return new DownloadQualityInspector();
    }

    public function test_parse_info_json_fixture(): void
    {
        $info = json_decode(file_get_contents(base_path('tests/Fixtures/ytdlp-info-hevc-1080.json')), true);

        $this->assertSame(['qn' => 80, 'format_id' => '30077+30280'], $this->inspector()->parseInfoJson($info));
    }

    public function test_parse_info_json_without_formats(): void
    {
        $this->assertSame(['qn' => null, 'format_id' => 'x'], $this->inspector()->parseInfoJson(['format_id' => 'x']));
    }

    public function test_parse_missing_formats(): void
    {
        $this->assertSame([120, 116], $this->inspector()->parseMissingFormats("[info] foo\n" . self::MISSING_OUTPUT . "\n[info] bar"));
    }

    public function test_parse_missing_formats_none(): void
    {
        $this->assertSame([], $this->inspector()->parseMissingFormats('[info] BV1vAMX68Egb: Downloading 1 format(s): 30077+30280'));
    }

    public function test_judge_ok_when_nothing_missing(): void
    {
        $this->assertSame('ok', $this->inspector()->judge(80, [], 'unlimited'));
    }

    public function test_judge_downgraded_when_higher_missing(): void
    {
        $this->assertSame('downgraded', $this->inspector()->judge(32, [120, 116, 112, 80, 64], 'unlimited'));
    }

    public function test_judge_downgraded_when_selected_unknown(): void
    {
        $this->assertSame('downgraded', $this->inspector()->judge(null, [80], 'unlimited'));
    }

    public function test_judge_ok_when_missing_above_cap(): void
    {
        $this->assertSame('ok', $this->inspector()->judge(116, [120], '1080'));
    }

    public function test_judge_ignores_hdr_and_dv(): void
    {
        $this->assertSame('ok', $this->inspector()->judge(120, [126, 125], 'unlimited'));
    }

    public function test_judge_unknown_missing_name_is_downgraded(): void
    {
        $this->assertSame('downgraded', $this->inspector()->judge(120, ['神秘档位'], 'unlimited'));
    }

    public function test_quality_name(): void
    {
        $this->assertSame('1080P 高码率', $this->inspector()->qualityName(112));
        $this->assertNull($this->inspector()->qualityName(null));
        $this->assertNull($this->inspector()->qualityName(9999));
    }
}
