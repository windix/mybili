<?php
namespace App\Services\VideoManager\Actions\Video;

use App\Enums\SettingKey;
use App\Events\VideoPartDownloaded;
use App\Exceptions\RedownloadNotImprovedException;
use App\Models\Video;
use App\Models\VideoPart;
use App\Services\BilibiliService;
use App\Services\BilibiliSuspendService;
use App\Services\DownloadFilterService;
use App\Services\DownloadQualityInspector;
use App\Services\DownloadVideoService;
use App\Services\MediaProbeService;
use App\Services\SettingsService;
use App\Services\VideoFormatSelector;
use Carbon\Carbon;
use Log;

class DownloadVideoPartFileAction
{

    public function __construct(
        public BilibiliService $bilibiliService,
        public DownloadFilterService $downloadFilterService,
        public SettingsService $settingsService,
        public BilibiliSuspendService $bilibiliSuspendService,
        public DownloadVideoService $downloadVideoService,
        public MediaProbeService $mediaProbeService,
        public VideoFormatSelector $videoFormatSelector,
        public DownloadQualityInspector $downloadQualityInspector
    ) {

    }

    /**
     * @param bool $redownload 按当前偏好重新下载：先下到临时文件，未被降级才替换原文件
     */
    public function execute(VideoPart $videoPart, bool $redownload = false): void
    {
        $video = $videoPart->video;
        if (video_has_invalid($video->toArray())) {
            Log::info('exclude video by invalid', ['video_id' => $video->id, 'title' => $video->title]);
            return;
        }

        if ($this->downloadFilterService->shouldExcludeByName($video->title)) {
            Log::info('exclude video by name', ['video_id' => $video->id, 'title' => $video->title]);
            return;
        }

        // 判断是否开启多P缓存
        if (! $this->downloadFilterService->isMultiPEnabled() && $videoPart->page > 1) {
            Log::info('multiP is not enabled', ['video_id' => $video->id, 'part' => $videoPart->page]);
            return;
        }

        $url = config('services.bilibili.id_type') == 'bv' ? sprintf('https://www.bilibili.com/video/%s/', $video->bvid) : sprintf('https://www.bilibili.com/video/av%s/', $video->id);

        $filePath = $this->downloadVideoService->getVideoPartValidFilePath($videoPart);
        if ($redownload) {
            $this->redownload($videoPart, $video, $url, $filePath);
            return;
        }
        if ($filePath) {
            $this->updateVideoPartDownloaded($videoPart, $filePath);
            Log::info('video file already exists', ['video_id' => $video->id, 'part' => $videoPart->page, 'filePath' => $filePath]);
            return;
        } else {
            // 如果不存在就去下载

            // 获取音乐节特辑类的链接
            $festivalJumpUrl = $this->bilibiliService->getVideoFestivalJumpUrl($video->id);
            if ($festivalJumpUrl) {
                $url = $festivalJumpUrl;
            }

            $this->downloadVideoService->createDownloadDirectory();
            $binPath = base_path('download-video.sh');

            $savePath = $this->downloadVideoService->buildVideoPartFilePath($videoPart);
            Log::info('download video', [
                'video_id' => $video->id,
                'part'     => $videoPart->page,
                'url'      => escapeshellarg($url),
                'savePath' => escapeshellarg($savePath),
                'binPath'  => escapeshellarg($binPath),
            ]);

            list($output, $result) = $this->execDownloadVideo($url, $savePath, $videoPart->page);
            if ($result != 0) {
                $msg = implode('', $output);
                throw new \Exception("下载异常:\n" . $msg);
            }
            Log::info('download video output', ['video_id' => $video->id, 'part' => $videoPart->page, 'output' => $output]);
            Log::info('download video success', ['video_id' => $video->id, 'part' => $videoPart->page, 'savePath' => $savePath]);
            $this->recordQuality($videoPart, $savePath, $output);
            $this->updateVideoPartDownloaded($videoPart, $savePath, true);

            event(new VideoPartDownloaded($videoPart));
        }
    }

    protected function execDownloadVideo(string $url, string $savePath, int $page): array
    {
        $formatArgs = $this->videoFormatSelector->fromSettings();
        if (config('services.bilibili.ignore_cookies')) {
            $command = sprintf('yt-dlp_linux %s --write-info-json --playlist-items %s -o %s %s', $formatArgs, escapeshellarg($page), escapeshellarg($savePath), escapeshellarg($url));
        } else {
            // 写入cookie到文件
            $cookiePath = storage_path('app/cookie.txt');
            file_put_contents($cookiePath, $this->settingsService->get(SettingKey::COOKIES_CONTENT));
            $command = sprintf('yt-dlp_linux %s --write-info-json --playlist-items %s --cookies=%s -o %s %s', $formatArgs, escapeshellarg($page), escapeshellarg($cookiePath), escapeshellarg($savePath), escapeshellarg($url));
        }
        [$output, $result] = $this->runYtDlp($command);

        if ($result != 0) {
            $msg = implode('', $output);
            if (strpos($msg, "412") !== false) {
                Log::error("Check 412 error, set suspend for bilibili high rate limit", ['msg' => $msg, 'url' => $url, 'page' => $page]);
                $this->bilibiliSuspendService->setSuspend();
            }
        }
        return [$output, $result];
    }

    /**
     * @param bool $freshDownload 本次是否新下载的文件（是则强制重新读取媒体信息）
     */
    protected function redownload(VideoPart $videoPart, Video $video, string $url, ?string $existingPath): void
    {
        $festivalJumpUrl = $this->bilibiliService->getVideoFestivalJumpUrl($video->id);
        if ($festivalJumpUrl) {
            $url = $festivalJumpUrl;
        }
        $this->downloadVideoService->createDownloadDirectory();

        $finalPath = $existingPath ?: $this->downloadVideoService->buildVideoPartFilePath($videoPart);
        $tmpPath   = preg_replace('/\.mp4$/', '.redownload.mp4', $finalPath);
        // 残留的临时文件会让 yt-dlp 认为“已下载”而跳过，必须先删除
        @unlink($tmpPath);
        @unlink(self::infoJsonPath($tmpPath));

        Log::info('redownload video', ['video_id' => $video->id, 'part' => $videoPart->page, 'tmpPath' => $tmpPath]);
        [$output, $result] = $this->execDownloadVideo($url, $tmpPath, $videoPart->page);
        if ($result != 0) {
            @unlink($tmpPath);
            throw new \Exception("下载异常:\n" . implode('', $output));
        }

        $probe = $videoPart->replicate();
        $this->recordQuality($probe, $tmpPath, $output);

        if ($probe->quality_status === 'downgraded') {
            @unlink($tmpPath);
            $names = array_map(
                fn ($item) => is_int($item) ? ($this->downloadQualityInspector->qualityName($item) ?? (string) $item) : $item,
                $probe->missing_qualities ?? []
            );
            throw new RedownloadNotImprovedException(sprintf('重新下载仍被降级（缺少 %s），可能是 cookie 未生效，已保留原文件', implode('、', $names)));
        }

        rename($tmpPath, $finalPath);
        foreach (['download_qn', 'download_format_id', 'quality_status', 'missing_qualities'] as $column) {
            $videoPart->{$column} = $probe->{$column};
        }
        Log::info('redownload video success', ['video_id' => $video->id, 'part' => $videoPart->page, 'savePath' => $finalPath]);
        $this->updateVideoPartDownloaded($videoPart, $finalPath, true);

        event(new VideoPartDownloaded($videoPart));
    }

    /**
     * 执行 yt-dlp 命令（测试中可替换）
     *
     * @return array{0: array, 1: int}
     */
    protected function runYtDlp(string $command): array
    {
        exec($command . ' 2>&1', $output, $result);

        return [$output, $result];
    }

    public static function infoJsonPath(string $savePath): string
    {
        return preg_replace('/\.mp4$/', '.info.json', $savePath);
    }

    /**
     * 根据 info-json 与 yt-dlp 输出记录实际档位并判定是否被降级（不保存，由调用方保存）
     */
    public function recordQuality(VideoPart $videoPart, string $savePath, array $output): void
    {
        $infoPath = self::infoJsonPath($savePath);
        $parsed   = ['qn' => null, 'format_id' => null];
        if (is_file($infoPath)) {
            $info = json_decode((string) file_get_contents($infoPath), true);
            if (is_array($info)) {
                $parsed = $this->downloadQualityInspector->parseInfoJson($info);
            }
            @unlink($infoPath);
        }

        $missing = $this->downloadQualityInspector->parseMissingFormats(implode("\n", $output));

        $videoPart->download_qn        = $parsed['qn'];
        $videoPart->download_format_id = $parsed['format_id'];
        $videoPart->missing_qualities  = $missing ?: null;
        $videoPart->quality_status     = $this->downloadQualityInspector->judge(
            $parsed['qn'],
            $missing,
            $this->videoFormatSelector->currentMaxResolution()
        );
    }

    public function updateVideoPartDownloaded(VideoPart $videoPart, string $savePath, bool $freshDownload = false): void
    {
        $video = Video::where('id', $videoPart->video_id)->first();
        if (is_file($savePath)) {
            $calcHash = hash_file('sha256', $savePath);
            if ($calcHash !== $this->downloadVideoService->getDownloadHash($savePath)) {
                $this->downloadVideoService->saveDownloadHash($savePath, $calcHash);
            }

            $videoPart->video_downloaded_at = Carbon::createFromTimestamp(filectime($savePath));
            $videoPart->video_download_path = get_relative_path($savePath);
            $this->mediaProbeService->fillVideoPart($videoPart, $freshDownload);
            $videoPart->save();

            // 真实下载时去更新视频的下载时间
            $video->video_downloaded_at = Carbon::createFromTimestamp(filectime($savePath));
        } else {
            $videoPart->video_downloaded_at = null;
            $videoPart->video_download_path = null;
            $videoPart->media_info          = null;
            $videoPart->save();
        }

        $video->video_downloaded_num = VideoPart::where('video_id', $video->id)->whereNotNull('video_download_path')->count();
        $video->save();
    }

}
