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

    public function test_command_disables_playlist_metafiles(): void
    {
        [$part, $action] = $this->prepare(205);

        $action->execute($part);

        $this->assertStringContainsString('--no-write-playlist-metafiles', $action->commands[0]);
    }

    public function test_412_suspend_requires_http_error(): void
    {
        $suspended = 0;
        $this->mock(\App\Services\BilibiliSuspendService::class, function ($mock) use (&$suspended) {
            $mock->shouldReceive('setSuspend')->andReturnUsing(function () use (&$suspended) { $suspended++; return true; });
        });
        [$part, $action]  = $this->prepare(206);
        $action->exitCode = 1;
        $action->fakeOutput = ['[download]  12.0% of ~ 412.30MiB at 1.00MiB/s', 'ERROR: unable to merge'];

        try {
            $action->execute($part);
        } catch (\Exception) {
        }
        $this->assertSame(0, $suspended);

        $action->fakeOutput = ['ERROR: [BiliBili] 206: Unable to download JSON metadata: HTTP Error 412: Precondition Failed'];
        try {
            $action->execute($part);
        } catch (\Exception) {
        }
        $this->assertSame(1, $suspended);
    }

    public function test_download_error_message_prefers_error_lines(): void
    {
        [$part, $action]    = $this->prepare(207);
        $action->exitCode   = 1;
        $action->fakeOutput = array_merge(array_fill(0, 50, '[download] progress noise line that is fairly long'), ['ERROR: [BiliBili] boom']);

        try {
            $action->execute($part);
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ERROR: [BiliBili] boom', $e->getMessage());
            $this->assertStringNotContainsString('progress noise', $e->getMessage());
        }
    }

    public function test_command_sets_retries(): void
    {
        [$part, $action] = $this->prepare(208);

        $action->execute($part);

        $this->assertStringContainsString('--retries 20', $action->commands[0]);
        $this->assertStringContainsString("--retry-sleep 'exp=1:10'", $action->commands[0]);
    }

    public function test_new_download_fixes_hev1_tag(): void
    {
        [$part, $action] = $this->prepare(209);
        $hev1 = HevcTagFixerTest::makeVideo('-c:v libx265 -tag:v hev1');
        if (! $hev1) {
            $this->markTestSkipped('libx265 not available');
        }
        $this->createdFiles[] = $hev1;
        $action->sourceFile   = $hev1;

        $action->execute($part);

        $part = $part->fresh();
        $this->assertSame('hvc1', $part->media_info['video']['tag']);
        $savePath = app(DownloadVideoService::class)->buildVideoPartFilePath($part);
        $this->assertSame(hash_file('sha256', $savePath), app(DownloadVideoService::class)->getDownloadHash($savePath));
    }
}
