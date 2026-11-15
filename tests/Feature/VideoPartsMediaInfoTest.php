<?php

namespace Tests\Feature;

use App\Models\AudioPart;
use App\Models\Video;
use App\Services\VideoManager\Actions\Audio\DownloadAudioPartFileAction;
use App\Services\VideoManager\Actions\Video\DownloadVideoPartFileAction;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class VideoPartsMediaInfoTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function makeVideo(int $id, int $type = 2): Video
    {
        $video = new Video();
        $video->forceFill([
            'id' => $id, 'link' => '', 'title' => 't', 'intro' => '', 'cover' => '', 'bvid' => 'BV' . $id,
            'pubtime' => now(), 'cache_image' => '', 'fav_time' => now(), 'type' => $type,
        ])->save();

        return $video;
    }

    public function test_parts_include_media_info_key(): void
    {
        $video = $this->makeVideo(100);
        MediaProbeFillTest::makeVideoPart(['video_id' => 100]);

        $parts = app(VideoService::class)->getAllPartsVideoForUser($video->fresh());

        $this->assertArrayHasKey('media_info', $parts[0]);
        $this->assertNull($parts[0]['media_info']);
    }

    public function test_parts_lazy_fill_media_info(): void
    {
        $abs = MediaProbeFillTest::makeTestMp4('videos/lazy-' . uniqid() . '.mp4');
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $video = $this->makeVideo(101);
        $part  = MediaProbeFillTest::makeVideoPart(['video_id' => 101, 'video_download_path' => get_relative_path($abs)]);

        $parts = app(VideoService::class)->getAllPartsVideoForUser($video->fresh());

        $this->assertSame(240, $parts[0]['media_info']['video']['height']);
        $this->assertSame(240, $part->fresh()->media_info['video']['height']);
    }

    public function test_audio_parts_lazy_fill_media_info(): void
    {
        $abs = MediaProbeFillTest::makeTestMp4('videos/au-lazy-' . uniqid() . '.mp3', true);
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $video = $this->makeVideo(102, 12);
        AudioPart::create(['video_id' => 102, 'sid' => 102, 'duration' => 1, 'audio_download_path' => get_relative_path($abs)]);

        $parts = app(VideoService::class)->getAllPartsVideoForUser($video->fresh());

        $this->assertSame('mp3', $parts[0]['media_info']['audio']['codec']);
    }

    public function test_reset_clears_media_info(): void
    {
        $this->makeVideo(103);
        $part  = MediaProbeFillTest::makeVideoPart(['video_id' => 103, 'media_info' => ['container' => 'x']]);
        $audio = AudioPart::create(['video_id' => 103, 'sid' => 103, 'duration' => 1]);
        $audio->media_info = ['container' => 'y'];
        $audio->save();

        app(VideoService::class)->deleteVideos([103], ['permanent' => false]);

        $this->assertNull($part->fresh()->media_info);
        $this->assertNull($audio->fresh()->media_info);
    }

    public function test_fresh_video_download_overwrites_media_info(): void
    {
        $abs = MediaProbeFillTest::makeTestMp4('videos/dl-' . uniqid() . '.mp4');
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $this->createdFiles[] = $abs . '.hash';
        $this->makeVideo(104);
        $part = MediaProbeFillTest::makeVideoPart(['video_id' => 104, 'media_info' => ['container' => 'stale']]);

        app(DownloadVideoPartFileAction::class)->updateVideoPartDownloaded($part, $abs, true);

        $this->assertSame(320, $part->fresh()->media_info['video']['width']);
    }

    public function test_existing_video_file_fills_missing_media_info(): void
    {
        $abs = MediaProbeFillTest::makeTestMp4('videos/dl2-' . uniqid() . '.mp4');
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $this->createdFiles[] = $abs . '.hash';
        $this->makeVideo(105);
        $part = MediaProbeFillTest::makeVideoPart(['video_id' => 105]);

        app(DownloadVideoPartFileAction::class)->updateVideoPartDownloaded($part, $abs);

        $this->assertSame(320, $part->fresh()->media_info['video']['width']);
    }

    public function test_video_download_missing_file_clears_media_info(): void
    {
        $this->makeVideo(106);
        $part = MediaProbeFillTest::makeVideoPart(['video_id' => 106, 'media_info' => ['container' => 'stale']]);

        app(DownloadVideoPartFileAction::class)->updateVideoPartDownloaded($part, '/nonexistent-' . uniqid() . '.mp4');

        $this->assertNull($part->fresh()->media_info);
    }

    public function test_fresh_audio_download_overwrites_media_info(): void
    {
        $abs = MediaProbeFillTest::makeTestMp4('videos/au-dl-' . uniqid() . '.mp3', true);
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $this->makeVideo(107, 12);
        $audio = AudioPart::create(['video_id' => 107, 'sid' => 107, 'duration' => 1]);
        $audio->media_info = ['container' => 'stale'];
        $audio->save();

        app(DownloadAudioPartFileAction::class)->updateAudioPartDownloaded($audio, $abs, true);

        $this->assertSame('mp3', $audio->fresh()->media_info['audio']['codec']);
    }

    public function test_audio_download_missing_file_clears_media_info(): void
    {
        $this->makeVideo(108, 12);
        $audio = AudioPart::create(['video_id' => 108, 'sid' => 108, 'duration' => 1]);
        $audio->media_info = ['container' => 'stale'];
        $audio->save();

        app(DownloadAudioPartFileAction::class)->updateAudioPartDownloaded($audio, '/nonexistent-' . uniqid() . '.mp3');

        $this->assertNull($audio->fresh()->media_info);
    }
}
