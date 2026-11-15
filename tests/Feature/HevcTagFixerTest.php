<?php

namespace Tests\Feature;

use App\Services\HevcTagFixer;
use App\Services\MediaProbeService;
use Tests\TestCase;

class HevcTagFixerTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** 生成指定编码/标记的 1 秒测试视频 */
    public static function makeVideo(string $codecArgs): ?string
    {
        if (trim((string) shell_exec('which ffmpeg')) === '') {
            return null;
        }
        $path = sys_get_temp_dir() . '/hevc-tag-' . uniqid() . '.mp4';
        exec(sprintf('ffmpeg -v quiet -y -f lavfi -i testsrc=size=320x240:rate=30 -f lavfi -i sine -t 1 %s -c:a aac %s', $codecArgs, escapeshellarg($path)), $o, $code);

        return $code === 0 && is_file($path) ? $path : null;
    }

    private function video(string $codecArgs): string
    {
        $path = self::makeVideo($codecArgs);
        if (! $path) {
            $this->markTestSkipped('ffmpeg with required encoder not available');
        }
        $this->files[] = $path;

        return $path;
    }

    public function test_parse_includes_codec_tag(): void
    {
        $info = app(MediaProbeService::class)->parse([
            'streams' => [['codec_type' => 'video', 'codec_name' => 'hevc', 'codec_tag_string' => 'hev1', 'width' => 1, 'height' => 1]],
            'format'  => [],
        ]);

        $this->assertSame('hev1', $info['video']['tag']);
    }

    public function test_fixes_hev1_to_hvc1(): void
    {
        $path = $this->video('-c:v libx265 -tag:v hev1');
        $this->assertSame('hev1', app(MediaProbeService::class)->probe($path)['video']['tag']);

        $this->assertTrue(app(HevcTagFixer::class)->fix($path));

        $info = app(MediaProbeService::class)->probe($path);
        $this->assertSame('hvc1', $info['video']['tag']);
        $this->assertSame('aac', $info['audio']['codec']);
        $this->assertFileDoesNotExist($path . '.hvc1.mp4');
    }

    public function test_leaves_hvc1_untouched(): void
    {
        $path = $this->video('-c:v libx265 -tag:v hvc1');
        $hash = hash_file('sha256', $path);

        $this->assertFalse(app(HevcTagFixer::class)->fix($path));
        $this->assertSame($hash, hash_file('sha256', $path));
    }

    public function test_leaves_avc_untouched(): void
    {
        $path = $this->video('-c:v libx264');
        $hash = hash_file('sha256', $path);

        $this->assertFalse(app(HevcTagFixer::class)->fix($path));
        $this->assertSame($hash, hash_file('sha256', $path));
    }

    public function test_missing_file_is_noop(): void
    {
        $this->assertFalse(app(HevcTagFixer::class)->fix('/nonexistent-' . uniqid() . '.mp4'));
    }
}
