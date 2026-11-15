<?php

namespace Tests\Feature;

use App\Models\AudioPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProbeMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function realFile(string $name, bool $audioOnly = false): string
    {
        $abs = MediaProbeFillTest::makeTestMp4('videos/' . $name . '-' . uniqid() . ($audioOnly ? '.mp3' : '.mp4'), $audioOnly);
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;

        return $abs;
    }

    public function test_command_fills_missing_media_info_for_videos_and_audio(): void
    {
        $video = MediaProbeFillTest::makeVideoPart(['video_id' => 1, 'video_download_path' => get_relative_path($this->realFile('cmd-v'))]);
        $audio = AudioPart::create(['video_id' => 2, 'sid' => 2, 'duration' => 1, 'audio_download_path' => get_relative_path($this->realFile('cmd-a', true))]);
        $notDownloaded = MediaProbeFillTest::makeVideoPart(['video_id' => 3, 'cid' => 3003]);

        $this->artisan('app:probe-media')
            ->expectsOutputToContain('probed: 2')
            ->assertExitCode(0);

        $this->assertSame(320, $video->fresh()->media_info['video']['width']);
        $this->assertSame('mp3', $audio->fresh()->media_info['audio']['codec']);
        $this->assertNull($notDownloaded->fresh()->media_info);
    }

    public function test_command_skips_existing_without_force(): void
    {
        $part = MediaProbeFillTest::makeVideoPart([
            'video_download_path' => get_relative_path($this->realFile('cmd-skip')),
            'media_info'          => ['container' => 'cached'],
        ]);

        $this->artisan('app:probe-media')
            ->expectsOutputToContain('probed: 0')
            ->assertExitCode(0);

        $this->assertSame('cached', $part->fresh()->media_info['container']);
    }

    public function test_command_force_reprobes_existing(): void
    {
        $part = MediaProbeFillTest::makeVideoPart([
            'video_download_path' => get_relative_path($this->realFile('cmd-force')),
            'media_info'          => ['container' => 'cached'],
        ]);

        $this->artisan('app:probe-media', ['--force' => true])
            ->expectsOutputToContain('probed: 1')
            ->assertExitCode(0);

        $this->assertSame(320, $part->fresh()->media_info['video']['width']);
    }

    public function test_command_reports_missing_files(): void
    {
        $part = MediaProbeFillTest::makeVideoPart(['video_download_path' => 'videos/gone-' . uniqid() . '.mp4']);

        $this->artisan('app:probe-media')
            ->expectsOutputToContain('missing/failed: 1')
            ->assertExitCode(0);

        $this->assertNull($part->fresh()->media_info);
    }

    public function test_command_limits_to_video_id(): void
    {
        $target = MediaProbeFillTest::makeVideoPart(['video_id' => 10, 'cid' => 1010, 'video_download_path' => get_relative_path($this->realFile('cmd-t'))]);
        $other  = MediaProbeFillTest::makeVideoPart(['video_id' => 11, 'cid' => 1011, 'video_download_path' => get_relative_path($this->realFile('cmd-o'))]);

        $this->artisan('app:probe-media', ['--video-id' => 10])
            ->expectsOutputToContain('probed: 1')
            ->assertExitCode(0);

        $this->assertNotNull($target->fresh()->media_info);
        $this->assertNull($other->fresh()->media_info);
    }
}
