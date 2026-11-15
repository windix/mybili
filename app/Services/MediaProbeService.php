<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * 使用 ffprobe 读取本地媒体文件的实际规格（分辨率、编码、码率等）
 */
class MediaProbeService
{
    public function probe(string $absPath): ?array
    {
        if (! is_file($absPath)) {
            return null;
        }

        $command = sprintf(
            'ffprobe -v quiet -print_format json -show_streams -show_format %s 2>/dev/null',
            escapeshellarg($absPath)
        );
        exec($command, $output, $result);

        if ($result !== 0) {
            Log::warning('ffprobe failed', ['path' => $absPath, 'code' => $result]);
            return null;
        }

        $data = json_decode(implode("\n", $output), true);
        if (! is_array($data)) {
            Log::warning('ffprobe output is not valid json', ['path' => $absPath]);
            return null;
        }

        return $this->parse($data);
    }

    public function parse(array $ffprobe): array
    {
        $streams = $ffprobe['streams'] ?? [];
        $format  = $ffprobe['format'] ?? [];

        $video = null;
        $audio = null;
        foreach ($streams as $stream) {
            $type = $stream['codec_type'] ?? null;
            if ($type === 'video' && $video === null && empty($stream['disposition']['attached_pic'])) {
                $video = [
                    'codec'   => (string) ($stream['codec_name'] ?? ''),
                    'profile' => $stream['profile'] ?? null,
                    'width'   => (int) ($stream['width'] ?? 0),
                    'height'  => (int) ($stream['height'] ?? 0),
                    'fps'     => $this->parseFrameRate($stream['avg_frame_rate'] ?? null),
                    'bitrate' => $this->toInt($stream['bit_rate'] ?? null),
                ];
            } elseif ($type === 'audio' && $audio === null) {
                $audio = [
                    'codec'          => (string) ($stream['codec_name'] ?? ''),
                    'profile'        => $stream['profile'] ?? null,
                    'bitrate'        => $this->toInt($stream['bit_rate'] ?? null),
                    'sample_rate'    => $this->toInt($stream['sample_rate'] ?? null),
                    'channels'       => $this->toInt($stream['channels'] ?? null),
                    'channel_layout' => $stream['channel_layout'] ?? null,
                ];
            }
        }

        return [
            'container' => $format['format_name'] ?? null,
            'size'      => $this->toInt($format['size'] ?? null),
            'duration'  => isset($format['duration']) && is_numeric($format['duration']) ? (float) $format['duration'] : null,
            'bitrate'   => $this->toInt($format['bit_rate'] ?? null),
            'video'     => $video,
            'audio'     => $audio,
            'probed_at' => now()->toIso8601String(),
        ];
    }

    private function parseFrameRate(?string $rate): ?float
    {
        if (! $rate || ! str_contains($rate, '/')) {
            return null;
        }
        [$num, $den] = array_map('floatval', explode('/', $rate, 2));
        if ($den == 0.0 || $num == 0.0) {
            return null;
        }
        return round($num / $den, 2);
    }

    private function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
