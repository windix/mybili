<?php

namespace Tests\Support;

use App\Models\Video;
use App\Models\VideoPart;
use App\Services\BilibiliService;
use Tests\Feature\MediaProbeFillTest;

trait QualityTestHelpers
{
    protected array $createdFiles = [];

    public const MISSING_LINE = '[BiliBili] Format(s) 4K 超高清, 1080P 60帧 are missing; you have to become a premium member to download them.';

    protected function tearDownQualityFiles(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
    }

    protected function makeVideo(int $id, int $type = 2): Video
    {
        $video = new Video();
        $video->forceFill([
            'id' => $id, 'link' => '', 'title' => 't' . $id, 'intro' => '', 'cover' => '', 'bvid' => 'BV' . $id,
            'pubtime' => now(), 'cache_image' => '', 'fav_time' => now(), 'type' => $type,
        ])->save();

        return $video;
    }

    protected function makePart(int $videoId, int $cid, array $attrs = []): VideoPart
    {
        return MediaProbeFillTest::makeVideoPart(array_merge(['video_id' => $videoId, 'cid' => $cid], $attrs));
    }

    /** 生成一个真实的 320x240 测试 mp4（作为“下载结果”的来源） */
    protected function sourceMp4(): string
    {
        $abs = MediaProbeFillTest::makeTestMp4('videos/src-' . uniqid() . '.mp4');
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;

        return $abs;
    }

    protected function fakeAction(): FakeYtDlpDownloadAction
    {
        app(\App\Services\SettingsService::class)->put('name_exclude', ['type' => 'off', 'contains' => '', 'regex' => '']);
        $this->mock(BilibiliService::class, function ($mock) {
            $mock->shouldReceive('getVideoFestivalJumpUrl')->andReturn(null);
        });

        return app(FakeYtDlpDownloadAction::class);
    }

    protected function infoFixture(): string
    {
        return base_path('tests/Fixtures/ytdlp-info-hevc-1080.json');
    }

    /** 记录分P可能产生的所有文件，测试结束时清理 */
    protected function trackPartFiles(string $finalPath): void
    {
        foreach (['', '.hash'] as $suffix) {
            $this->createdFiles[] = $finalPath . $suffix;
        }
        $base = preg_replace('/\.mp4$/', '', $finalPath);
        foreach (['.info.json', '.redownload.mp4', '.redownload.info.json'] as $suffix) {
            $this->createdFiles[] = $base . $suffix;
        }
    }
}
