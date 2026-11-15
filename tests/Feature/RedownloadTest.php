<?php

namespace Tests\Feature;

use App\Exceptions\RedownloadNotImprovedException;
use App\Jobs\DownloadVideoJob;
use App\Models\DownloadQueue;
use App\Services\DownloadQueueService;
use App\Services\DownloadVideoService;
use App\Services\VideoManager\Actions\Video\DownloadVideoPartFileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QualityTestHelpers;
use Tests\TestCase;

class RedownloadTest extends TestCase
{
    use RefreshDatabase, QualityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownQualityFiles();
        parent::tearDown();
    }

    /** 已下载的旧文件：内容为一段文本，带 hash 与旧的画质记录 */
    private function downloadedPart(int $videoId): array
    {
        $this->makeVideo($videoId);
        $part      = $this->makePart($videoId, $videoId * 10);
        $finalPath = app(DownloadVideoService::class)->buildVideoPartFilePath($part);
        $this->trackPartFiles($finalPath);
        file_put_contents($finalPath, 'old-file');
        app(DownloadVideoService::class)->saveDownloadHash($finalPath, hash('sha256', 'old-file'));
        $part->forceFill([
            'video_download_path' => get_relative_path($finalPath),
            'download_qn'         => 32,
            'quality_status'      => 'downgraded',
        ])->save();

        return [$part, $finalPath];
    }

    public function test_redownload_replaces_when_ok(): void
    {
        [$part, $finalPath]      = $this->downloadedPart(301);
        $action                  = $this->fakeAction();
        $action->sourceFile      = $this->sourceMp4();
        $action->infoJsonFixture = $this->infoFixture();

        $action->execute($part, true);

        $part = $part->fresh();
        $this->assertSame(hash_file('sha256', $action->sourceFile), hash_file('sha256', $finalPath));
        $this->assertSame(80, $part->download_qn);
        $this->assertSame('ok', $part->quality_status);
        $this->assertSame(320, $part->media_info['video']['width']);
        $this->assertFileDoesNotExist(preg_replace('/\.mp4$/', '.redownload.mp4', $finalPath));
    }

    public function test_redownload_keeps_old_when_downgraded(): void
    {
        [$part, $finalPath] = $this->downloadedPart(302);
        $action             = $this->fakeAction();
        $action->sourceFile = $this->sourceMp4();
        $action->fakeOutput = [self::MISSING_LINE];

        try {
            $action->execute($part, true);
            $this->fail('expected RedownloadNotImprovedException');
        } catch (RedownloadNotImprovedException $e) {
            $this->assertStringContainsString('4K 超高清', $e->getMessage());
        }

        $this->assertSame('old-file', file_get_contents($finalPath));
        $this->assertSame(32, $part->fresh()->download_qn);
        $this->assertFileDoesNotExist(preg_replace('/\.mp4$/', '.redownload.mp4', $finalPath));
    }

    public function test_redownload_installs_when_old_file_missing(): void
    {
        $this->makeVideo(303);
        $part      = $this->makePart(303, 3030, ['video_download_path' => 'videos/gone-' . uniqid() . '.mp4']);
        $finalPath = app(DownloadVideoService::class)->buildVideoPartFilePath($part);
        $this->trackPartFiles($finalPath);
        $action             = $this->fakeAction();
        $action->sourceFile = $this->sourceMp4();

        $action->execute($part, true);

        $this->assertFileExists($finalPath);
        $this->assertSame(get_relative_path($finalPath), $part->fresh()->video_download_path);
    }

    public function test_redownload_removes_stale_temp_file_before_running(): void
    {
        [$part, $finalPath] = $this->downloadedPart(304);
        $tmpPath            = preg_replace('/\.mp4$/', '.redownload.mp4', $finalPath);
        file_put_contents($tmpPath, 'stale');
        $action             = $this->fakeAction();
        $action->sourceFile = $this->sourceMp4();
        $seen               = null;
        $action->beforeRun  = function (string $outPath) use (&$seen) {
            $seen = ['path' => $outPath, 'exists' => file_exists($outPath)];
        };

        $action->execute($part, true);

        $this->assertSame($tmpPath, $seen['path']);
        $this->assertFalse($seen['exists']);
    }

    public function test_enqueue_redownload_upgrades_pending_item(): void
    {
        $this->makeVideo(305);
        $part    = $this->makePart(305, 3050);
        $service = app(DownloadQueueService::class);

        $service->enqueueVideo($part);
        $service->enqueueVideo($part, 0, true);

        $this->assertSame(1, DownloadQueue::count());
        $this->assertTrue(DownloadQueue::first()->redownload);
    }

    public function test_enqueue_redownload_on_finished_item_resets_it(): void
    {
        $this->makeVideo(306);
        $part    = $this->makePart(306, 3060);
        $service = app(DownloadQueueService::class);
        $service->enqueueVideo($part);
        $service->markDoneByVideoPart($part->id);

        $service->enqueueVideo($part, 0, true);

        $item = DownloadQueue::first();
        $this->assertSame(DownloadQueue::STATUS_PENDING, $item->status);
        $this->assertTrue($item->redownload);
    }

    public function test_job_marks_failed_without_retry_on_not_improved(): void
    {
        $this->makeVideo(307);
        $part = $this->makePart(307, 3070);
        app(DownloadQueueService::class)->enqueueVideo($part, 0, true);
        $this->mock(DownloadVideoPartFileAction::class, function ($mock) {
            $mock->shouldReceive('execute')->withArgs(fn ($p, $redownload) => $redownload === true)
                ->andThrow(new RedownloadNotImprovedException('重新下载仍被降级'));
        });

        (new DownloadVideoJob($part, true))->process();

        $item = DownloadQueue::first();
        $this->assertSame(DownloadQueue::STATUS_FAILED, $item->status);
        $this->assertSame(0, $item->retry_count);
        $this->assertStringContainsString('重新下载仍被降级', $item->error_msg);
    }

    public function test_redownload_keeps_old_when_new_file_unplayable(): void
    {
        [$part, $finalPath] = $this->downloadedPart(308);
        $garbage            = tempnam(sys_get_temp_dir(), 'garbage');
        file_put_contents($garbage, 'not a video');
        $this->createdFiles[] = $garbage;
        $action             = $this->fakeAction();
        $action->sourceFile = $garbage;

        try {
            $action->execute($part, true);
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertNotInstanceOf(RedownloadNotImprovedException::class, $e);
        }

        $this->assertSame('old-file', file_get_contents($finalPath));
        $this->assertFileDoesNotExist(preg_replace('/\.mp4$/', '.redownload.mp4', $finalPath));
    }

    public function test_redownload_keeps_old_when_no_file_produced(): void
    {
        [$part, $finalPath] = $this->downloadedPart(309);
        $action             = $this->fakeAction();
        $action->sourceFile = null;

        try {
            $action->execute($part, true);
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('未找到', $e->getMessage());
        }

        $this->assertSame('old-file', file_get_contents($finalPath));
    }

    public function test_redownload_keeps_old_when_duration_mismatch(): void
    {
        [$part, $finalPath] = $this->downloadedPart(310);
        $part->duration     = 600;
        $part->save();
        $action             = $this->fakeAction();
        $action->sourceFile = $this->sourceMp4();

        try {
            $action->execute($part, true);
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('时长', $e->getMessage());
        }

        $this->assertSame('old-file', file_get_contents($finalPath));
    }
}
