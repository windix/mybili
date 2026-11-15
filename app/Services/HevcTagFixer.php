<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Apple 设备（Safari / AVFoundation）只播放 hvc1 标记的 HEVC MP4。
 * B 站部分 HEVC 流为 hev1，下载后无损重新封装为 hvc1（-strict unofficial 保留杜比视界配置）。
 */
class HevcTagFixer
{
    public function __construct(public MediaProbeService $mediaProbeService)
    {
    }

    /**
     * @return bool 是否进行了重新封装
     */
    public function fix(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $info = $this->mediaProbeService->probe($path);
        if (($info['video']['codec'] ?? null) !== 'hevc' || ($info['video']['tag'] ?? null) !== 'hev1') {
            return false;
        }

        $tmp = $path . '.hvc1.mp4';
        exec(sprintf(
            'ffmpeg -v error -y -i %s -map 0 -c copy -tag:v hvc1 -strict unofficial -movflags +faststart %s 2>&1',
            escapeshellarg($path),
            escapeshellarg($tmp)
        ), $output, $code);

        if ($code !== 0 || ! is_file($tmp)) {
            @unlink($tmp);
            Log::warning('Failed to remux hev1 to hvc1, keep original', ['path' => $path, 'output' => $output]);
            return false;
        }

        rename($tmp, $path);
        Log::info('Remuxed hev1 to hvc1', ['path' => $path]);

        return true;
    }
}
