<?php
namespace App\Services;

use App\Enums\SettingKey;

/**
 * 根据画质偏好生成 yt-dlp 的格式选择参数（主文件只取 SDR）
 */
class VideoFormatSelector
{
    public const DEFAULT_RESOLUTION = 'unlimited';
    public const DEFAULT_CODEC      = 'hevc';

    /** 分辨率上限 → B 站档位（qn）上限；按档位过滤可避免竖屏视频按高度被误降级 */
    public const QN_CAP = [
        '2160' => 120,
        '1440' => 116,
        '1080' => 116,
        '720'  => 74,
    ];

    /** 编码偏好 → yt-dlp -S 写法（实测：首选编码优先，其余按 hevc > avc > av1） */
    public const CODEC_SORT = [
        'hevc' => 'vcodec:h265',
        'avc'  => 'vcodec:h264',
        'av1'  => 'vcodec:av01',
    ];

    public function __construct(public SettingsService $settingsService)
    {
    }

    public function fromSettings(): string
    {
        return $this->buildFormatArgs(
            $this->currentMaxResolution(),
            (string) ($this->settingsService->get(SettingKey::VIDEO_CODEC_PREFERENCE->value) ?: self::DEFAULT_CODEC)
        );
    }

    public function currentMaxResolution(): string
    {
        return (string) ($this->settingsService->get(SettingKey::VIDEO_MAX_RESOLUTION->value) ?: self::DEFAULT_RESOLUTION);
    }

    public function buildFormatArgs(string $maxResolution, string $codec): string
    {
        $cap  = self::QN_CAP[$maxResolution] ?? null;
        $sort = self::CODEC_SORT[$codec] ?? self::CODEC_SORT[self::DEFAULT_CODEC];

        $selectors = [];
        if ($cap !== null) {
            $selectors[] = "bv*[dynamic_range=SDR][quality<={$cap}]+ba";
        }
        $selectors[] = 'bv*[dynamic_range=SDR]+ba';
        $selectors[] = 'bv*+ba';
        $selectors[] = 'b';

        return sprintf('-f %s -S %s', escapeshellarg(implode('/', $selectors)), escapeshellarg('quality,' . $sort));
    }
}
