<?php

namespace Tests\Feature;

use App\Models\AudioPart;
use App\Models\VideoPart;
use App\Services\MediaProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MediaProbeFillTest extends TestCase
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

    public static function makeVideoPart(array $attrs = []): VideoPart
    {
        $part = new VideoPart();
        $part->forceFill(array_merge([
            'video_id' => 1, 'cid' => 1001, 'page' => 1, 'from' => 'vupload', 'part' => 'P1',
            'duration' => 1, 'vid' => '', 'weblink' => '',
        ], $attrs))->save();

        return $part;
    }

    public static function makeTestMp4(string $relativePath, bool $audioOnly = false): ?string
    {
        if (trim((string) shell_exec('which ffprobe')) === '' || trim((string) shell_exec('which ffmpeg')) === '') {
            return null;
        }
        $abs = Storage::disk('public')->path($relativePath);
        // DownloadVideoService::createDownloadDirectory 以 0644 创建目录（无执行位），这里确保可写入
        @mkdir(dirname($abs), 0755, true);
        @chmod(dirname($abs), 0755);
        $inputs = $audioOnly
            ? '-f lavfi -i sine -t 1'
            : '-f lavfi -i testsrc=size=320x240:rate=30 -f lavfi -i sine -t 1 -c:v libx264 -c:a aac';
        exec(sprintf('ffmpeg -v quiet -y %s %s', $inputs, escapeshellarg($abs)));

        return $abs;
    }

    public function test_media_info_cast_to_array(): void
    {
        $part = self::makeVideoPart(['media_info' => ['container' => 'x']]);

        $this->assertSame('x', $part->fresh()->media_info['container']);

        $audio = AudioPart::create(['video_id' => 5, 'sid' => 5, 'duration' => 1]);
        $audio->media_info = ['container' => 'y'];
        $audio->save();
        $this->assertSame('y', $audio->fresh()->media_info['container']);
    }

    public function test_fill_returns_null_when_file_missing(): void
    {
        $part = self::makeVideoPart(['video_download_path' => 'videos/nope-' . uniqid() . '.mp4']);

        $this->assertNull(app(MediaProbeService::class)->fillVideoPart($part));
        $this->assertNull($part->fresh()->media_info);
    }

    public function test_fill_returns_null_when_not_downloaded(): void
    {
        $part = self::makeVideoPart();

        $this->assertNull(app(MediaProbeService::class)->fillVideoPart($part));
    }

    public function test_fill_does_not_reprobe_when_present(): void
    {
        $abs = self::makeTestMp4('videos/test-present-' . uniqid() . '.mp4');
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $existing = ['container' => 'cached'];
        $part     = self::makeVideoPart([
            'video_download_path' => get_relative_path($abs),
            'media_info'          => $existing,
        ]);

        $service = Mockery::mock(MediaProbeService::class)->makePartial();
        $service->shouldNotReceive('probe');

        $this->assertSame($existing, $service->fillVideoPart($part));
    }

    public function test_fill_probes_and_saves(): void
    {
        $abs = self::makeTestMp4('videos/test-fill-' . uniqid() . '.mp4');
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $part = self::makeVideoPart(['video_download_path' => get_relative_path($abs)]);

        $result = app(MediaProbeService::class)->fillVideoPart($part);

        $this->assertSame(320, $result['video']['width']);
        $this->assertSame(320, $part->fresh()->media_info['video']['width']);
    }

    public function test_fill_audio_part_probes_and_saves(): void
    {
        $abs = self::makeTestMp4('videos/au-test-' . uniqid() . '.mp3', true);
        if (! $abs) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->createdFiles[] = $abs;
        $audio = AudioPart::create([
            'video_id' => 7, 'sid' => 7, 'duration' => 1,
            'audio_download_path' => get_relative_path($abs),
        ]);

        $result = app(MediaProbeService::class)->fillAudioPart($audio);

        $this->assertNull($result['video']);
        $this->assertSame('mp3', $result['audio']['codec']);
        $this->assertSame('mp3', $audio->fresh()->media_info['audio']['codec']);
    }

    public function test_resolve_path_handles_storage_prefix(): void
    {
        $service  = app(MediaProbeService::class);
        $expected = Storage::disk('public')->path('videos/a.mp3');

        $this->assertSame($expected, $service->resolvePath('/storage/videos/a.mp3'));
        $this->assertSame($expected, $service->resolvePath('videos/a.mp3'));
        $this->assertNull($service->resolvePath(null));
        $this->assertNull($service->resolvePath(''));
    }
}
