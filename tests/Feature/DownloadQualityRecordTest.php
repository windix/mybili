<?php

namespace Tests\Feature;

use App\Services\DownloadVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\QualityTestHelpers;
use Tests\TestCase;

class DownloadQualityRecordTest extends TestCase
{
    use RefreshDatabase, QualityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownQualityFiles();
        parent::tearDown();
    }

    private function prepare(int $videoId): array
    {
        $this->makeVideo($videoId);
        $part = $this->makePart($videoId, $videoId * 10);
        $this->trackPartFiles(app(DownloadVideoService::class)->buildVideoPartFilePath($part));
        $action             = $this->fakeAction();
        $action->sourceFile = $this->sourceMp4();

        return [$part, $action];
    }

    public function test_new_download_records_quality_ok(): void
    {
        [$part, $action]         = $this->prepare(201);
        $action->infoJsonFixture = $this->infoFixture();
        $action->fakeOutput      = ['[info] BV201: Downloading 1 format(s): 30077+30280'];

        $action->execute($part);

        $part = $part->fresh();
        $this->assertSame(80, $part->download_qn);
        $this->assertSame('30077+30280', $part->download_format_id);
        $this->assertSame('ok', $part->quality_status);
        $this->assertNull($part->missing_qualities);
        $savePath = app(DownloadVideoService::class)->buildVideoPartFilePath($part);
        $this->assertFileDoesNotExist(preg_replace('/\.mp4$/', '.info.json', $savePath));
    }

    public function test_new_download_records_downgraded(): void
    {
        [$part, $action]         = $this->prepare(202);
        $action->infoJsonFixture = $this->infoFixture();
        $action->fakeOutput      = [self::MISSING_LINE];

        $action->execute($part);

        $part = $part->fresh();
        $this->assertSame('downgraded', $part->quality_status);
        $this->assertSame([120, 116], $part->missing_qualities);
    }

    public function test_missing_info_json_still_judges_from_output(): void
    {
        [$part, $action]    = $this->prepare(203);
        $action->fakeOutput = [self::MISSING_LINE];

        $action->execute($part);

        $part = $part->fresh();
        $this->assertNull($part->download_qn);
        $this->assertSame('downgraded', $part->quality_status);
    }

    public function test_command_uses_default_preferences(): void
    {
        [$part, $action] = $this->prepare(204);

        $action->execute($part);

        $this->assertCount(1, $action->commands);
        $this->assertStringContainsString('bv*[dynamic_range=SDR]+ba', $action->commands[0]);
        $this->assertStringContainsString('vcodec:h265', $action->commands[0]);
        $this->assertStringContainsString('--write-info-json', $action->commands[0]);
    }
}
