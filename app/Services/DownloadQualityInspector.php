<?php
namespace App\Services;

/**
 * 解析 yt-dlp 的 info-json 与输出，判断下载是否被 B 站降级
 */
class DownloadQualityInspector
{
    /** B 站档位（qn）→ 名称（与 yt-dlp 缺失提示中的名称一致） */
    public const QUALITY_NAMES = [
        127 => '8K 超高清',
        126 => '杜比视界',
        125 => 'HDR 真彩',
        120 => '4K 超高清',
        116 => '1080P 60帧',
        112 => '1080P 高码率',
        80  => '1080P 高清',
        74  => '720P 60帧',
        64  => '720P 准高清',
        32  => '480P 标清',
        16  => '360P 流畅',
    ];

    /** 主文件只取 SDR，HDR/杜比视界缺失不算降级 */
    private const IGNORED_QN = [125, 126];

    public function qualityName(?int $qn): ?string
    {
        return $qn === null ? null : (self::QUALITY_NAMES[$qn] ?? null);
    }

    /**
     * @return array{qn: ?int, format_id: ?string}
     */
    public function parseInfoJson(array $info): array
    {
        $formatId = isset($info['format_id']) ? (string) $info['format_id'] : null;
        $qn       = null;

        if ($formatId !== null) {
            $ids = explode('+', $formatId);
            foreach ($info['formats'] ?? [] as $format) {
                if (in_array((string) ($format['format_id'] ?? ''), $ids, true)
                    && ($format['vcodec'] ?? 'none') !== 'none'
                    && isset($format['quality'])) {
                    $qn = (int) $format['quality'];
                    break;
                }
            }
        }

        return ['qn' => $qn, 'format_id' => $formatId];
    }

    /**
     * @return array<int|string> 已识别的档位为 qn，未识别的名称原样保留
     */
    public function parseMissingFormats(string $output): array
    {
        if (! preg_match('/Format\(s\) (.+?) are missing; you have to become a premium member/u', $output, $m)) {
            return [];
        }

        $byName = array_flip(self::QUALITY_NAMES);
        $result = [];
        foreach (explode(', ', $m[1]) as $name) {
            $name     = trim($name);
            $result[] = $byName[$name] ?? $name;
        }

        return $result;
    }

    public function judge(?int $selectedQn, array $missing, string $maxResolution): string
    {
        $cap = VideoFormatSelector::QN_CAP[$maxResolution] ?? null;

        foreach ($missing as $item) {
            if (is_string($item)) {
                return 'downgraded';
            }
            if (in_array($item, self::IGNORED_QN, true)) {
                continue;
            }
            if ($cap !== null && $item > $cap) {
                continue;
            }
            if ($selectedQn === null || $item > $selectedQn) {
                return 'downgraded';
            }
        }

        return 'ok';
    }
}
