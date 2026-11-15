<?php

namespace Tests\Unit;

use App\Services\MediaProbeService;
use Tests\TestCase;

class MediaProbeServiceParseTest extends TestCase
{
    private function service(): MediaProbeService
    {
        return new MediaProbeService();
    }

    public function test_parse_mp4_hevc_aac(): void
    {
        $info = $this->service()->parse([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'hevc', 'profile' => 'Main', 'width' => 1920, 'height' => 1080, 'avg_frame_rate' => '60000/1001', 'bit_rate' => '2000000'],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'profile' => 'LC', 'bit_rate' => '192000', 'sample_rate' => '48000', 'channels' => 2, 'channel_layout' => 'stereo'],
            ],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'size' => '548405248', 'duration' => '123.45', 'bit_rate' => '2200000'],
        ]);

        $this->assertSame('mov,mp4,m4a,3gp,3g2,mj2', $info['container']);
        $this->assertSame(548405248, $info['size']);
        $this->assertSame(123.45, $info['duration']);
        $this->assertSame(2200000, $info['bitrate']);
        $this->assertSame('hevc', $info['video']['codec']);
        $this->assertSame('Main', $info['video']['profile']);
        $this->assertSame(1920, $info['video']['width']);
        $this->assertSame(1080, $info['video']['height']);
        $this->assertSame(59.94, $info['video']['fps']);
        $this->assertSame(2000000, $info['video']['bitrate']);
        $this->assertSame('aac', $info['audio']['codec']);
        $this->assertSame(192000, $info['audio']['bitrate']);
        $this->assertSame(48000, $info['audio']['sample_rate']);
        $this->assertSame(2, $info['audio']['channels']);
        $this->assertSame('stereo', $info['audio']['channel_layout']);
        $this->assertIsString($info['probed_at']);
    }

    public function test_parse_audio_only(): void
    {
        $info = $this->service()->parse([
            'streams' => [
                ['codec_type' => 'audio', 'codec_name' => 'flac', 'sample_rate' => '96000', 'channels' => 2],
            ],
            'format' => ['format_name' => 'flac', 'size' => '1000'],
        ]);

        $this->assertNull($info['video']);
        $this->assertSame('flac', $info['audio']['codec']);
        $this->assertNull($info['audio']['bitrate']);
    }

    public function test_parse_skips_attached_pic(): void
    {
        $info = $this->service()->parse([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'width' => 500, 'height' => 500, 'disposition' => ['attached_pic' => 1]],
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720, 'avg_frame_rate' => '30/1', 'disposition' => ['attached_pic' => 0]],
            ],
            'format' => [],
        ]);

        $this->assertSame('h264', $info['video']['codec']);
        $this->assertSame(30.0, $info['video']['fps']);
    }

    public function test_parse_handles_zero_frame_rate(): void
    {
        $info = $this->service()->parse([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 640, 'height' => 360, 'avg_frame_rate' => '0/0'],
            ],
            'format' => [],
        ]);

        $this->assertNull($info['video']['fps']);
    }

    public function test_parse_mkv_without_stream_bitrate(): void
    {
        $info = $this->service()->parse([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'av1', 'width' => 3840, 'height' => 2160, 'avg_frame_rate' => '30/1'],
            ],
            'format' => ['format_name' => 'matroska,webm', 'bit_rate' => '8000000'],
        ]);

        $this->assertNull($info['video']['bitrate']);
        $this->assertSame(8000000, $info['bitrate']);
    }

    public function test_probe_returns_null_for_missing_file(): void
    {
        $this->assertNull($this->service()->probe('/nonexistent.mp4'));
    }

    public function test_probe_real_file(): void
    {
        if (trim((string) shell_exec('which ffprobe')) === '' || trim((string) shell_exec('which ffmpeg')) === '') {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        $path = sys_get_temp_dir() . '/media-probe-test-' . uniqid() . '.mp4';
        exec(sprintf(
            'ffmpeg -v quiet -y -f lavfi -i testsrc=size=320x240:rate=30 -f lavfi -i sine -t 1 -c:v libx264 -c:a aac %s',
            escapeshellarg($path)
        ));

        try {
            $info = $this->service()->probe($path);
            $this->assertSame(320, $info['video']['width']);
            $this->assertSame(240, $info['video']['height']);
            $this->assertSame(30.0, $info['video']['fps']);
            $this->assertSame('aac', $info['audio']['codec']);
        } finally {
            @unlink($path);
        }
    }
}
